---
title: Webhooks
description: Receive Pey's signed webhooks in Laravel, handle each delivery once with queued listeners, and confirm payments before fulfilling orders.
---

# Webhooks

Pey notifies your application with signed webhooks when a CoDi QR, a push notification or a payment link is paid, and when a bank account becomes active. This package verifies each one with the SDK, handles each delivery once, and dispatches a Laravel event for it.

## Register the route

Register the route once, in `routes/web.php` or `routes/api.php`:

```php
use Illuminate\Support\Facades\Route;

Route::peyWebhooks();                    // POST /webhooks/pey, named pey.webhooks
Route::peyWebhooks('/payments/pey');     // or any URI
```

Then:
1. Set the endpoint URL (for example `https://shop.example/webhooks/pey`) in the Pey portal.
2. Copy its `whsec_…` secret to `PEY_WEBHOOK_SECRET`.

The macro returns the `Route`, so you can chain more middleware, such as `->middleware('throttle:120,1')`.

### CSRF

A webhook is authenticated by its signature, not by a session, so `Route::peyWebhooks()` excludes its route from CSRF verification. That covers Laravel 12's `ValidateCsrfToken` and Laravel 13's `PreventRequestForgery`, including subclasses of your own.

If you route Pey to your own controller instead, exclude its URI in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel 13 also accepts preventRequestForgery(except: [...]).
        $middleware->validateCsrfTokens(except: ['webhooks/pey']);
    })
    ->create();
```

Or register the route in `routes/api.php`, which has no CSRF middleware.

## What happens to each request

The `VerifyPeyWebhook` middleware runs before anything reads the request. It passes the raw body and the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers to the SDK's `WebhookVerifier`. The verifier checks the signature (Standard Webhooks) and that the timestamp is within `PEY_WEBHOOK_TOLERANCE` (300 seconds) of now, and only then parses the body.

| Request | Answer | Logged |
|---|---|---|
| Missing or malformed header, wrong signature, stale or future timestamp | `400 {"error":"invalid_webhook"}` | `warning`, with the reason: `missing_header`, `invalid_header`, `invalid_signature`, `timestamp_too_old` or `timestamp_too_new` |
| Valid signature, but a body the SDK cannot read | `400 {"error":"invalid_webhook"}` | `error`, with the field that failed, never its value |
| A new, valid webhook | `200 {"status":"received"}`, after dispatching its events | |
| A webhook id already handled | `200 {"status":"duplicate"}`; nothing is dispatched | |
| A listener or the dedup store throws | `500`; the id is released, so Pey's retry is handled | by Laravel's exception handler |

Rejections never log the body, the headers or the secret. Pey shows delivery attempts and responses in its portal: open your API key, then the gear icon, then "Webhook Logs".

## Duplicates

Pey can deliver the same webhook more than once. Each `webhook-id` is remembered, under a hashed key, in `PEY_WEBHOOK_DEDUP_STORE` for `PEY_WEBHOOK_DEDUP_TTL` seconds (72 hours by default). When the store is unset, the default cache store is used. A repeat in that window is answered `200` without dispatching anything, so Pey stops retrying.

The store must be shared by every worker and server. `redis`, `database`, `memcached` and `dynamodb` work. When a webhook secret is configured, the package logs a warning at boot for:
- `array` outside tests: it keeps ids in the memory of one process.
- `file` in production: it is local to one server.
- `null`: it stores nothing, so every webhook would look like a duplicate. A `null` store is also refused when the first webhook arrives.

The TTL must be at least twice the tolerance: a replayed webhook is accepted while its timestamp is within the tolerance in either direction, so its id must be remembered that long.

## Events

A new webhook dispatches `PeyWebhookReceived`, then one typed event. All of them are in the `Dev1\Pey\Laravel\Events` namespace:

| Webhook type | Typed event | `$event` (from the SDK) |
|---|---|---|
| `codi.qr.payment_received` | `CodiQrPaymentReceived` | `Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived` |
| `codi.pn.payment_received` | `CodiPushNotificationPaymentReceived` | `Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived` |
| `payment_link.status_updated` | `PaymentLinkStatusUpdated` | `Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated` |
| `bank.account.updated` | `BankAccountUpdated` | `Dev1\Pey\Webhooks\Event\BankAccountUpdated` |
| any other type | `UnknownPeyEvent` | `Dev1\Pey\Webhooks\Event\UnknownEvent` |

Each typed event has two properties:
- `$event`: the SDK's verified event.
- `$webhook`: the SDK's `VerifiedWebhook`, with its `id`, its `timestamp` and the same event.

`PeyWebhookReceived` has `$webhook` only. `UnknownPeyEvent` means the installed SDK does not model that type yet. It is acknowledged, so Pey stops retrying; upgrade `dev1/pey-php-sdk` to handle it.

## Listeners

Make every listener **queued** and **idempotent**:
- **Queued** (`ShouldQueue`), so Pey gets its `200` quickly.
- **Idempotent**, because delivery is at least once. A listener that throws makes the webhook fail with `500`, and Pey's retry dispatches its events again, to every listener. Fulfil an order once per transaction id, for example with a unique index.

### Confirm before fulfilling

A webhook is a notification, not proof of payment. Before you ship anything, confirm the payment with `Pey::transactions()->get()`:

```php
use App\Models\Order;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Money\Amount;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FulfilPaidQr implements ShouldQueue
{
    public function handle(CodiQrPaymentReceived $received): void
    {
        $order = Order::where('codi_qr_id', $received->event->codiQrId)->firstOrFail();
        $transaction = Pey::transactions()->get($received->event->transactionId);

        $paid = $transaction->isPaid()
            && $transaction->payableType === PayableType::CodiQr
            && $transaction->payableId === $order->codi_qr_id
            && $transaction->amountBreakdown?->base->equals(Amount::fromString($order->total)) === true;

        if ($paid) {
            $order->fulfilOnce($transaction->id);
        }
    }
}
```

Compare `amountBreakdown->base`, not `amount`: the total `amount` also includes any tip and the fee charged to the payer. A QR without expiration can be paid more than once, so fulfil per transaction id. For each payment type:
- **Push notifications:** check `PayableType::CodiPushNotification`, and the `transactionId` that `createPushNotification()` returned.
- **Payment links:** confirm each transaction in `$received->event->transactions`, or the link itself with `Pey::paymentLinks()->get($received->event->paymentLinkId)`.

Laravel discovers listeners in `app/Listeners` by the event type of their `handle()` method.

### Personal data in queues

Events never carry the raw body, the headers or a secret. But a queued listener stores its event in the queue payload, and in `failed_jobs` if it fails:
- `BankAccountUpdated` carries the account's full CLABE, the holder's name and, when Pey has it, their RFC (`taxId`).
- `UnknownPeyEvent` carries the whole verified body, decoded.

Protect your queue storage as you protect your database, and mask CLABEs with `Dev1\Pey\Validation\Clabe::mask()` before you log them.

## Your own controller

To handle verified webhooks yourself, put your route behind the middleware and read what it verified:

```php
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pey', static function (Request $request) {
    $webhook = VerifyPeyWebhook::verifiedWebhook($request);   // id, timestamp and the SDK event

    return response()->json(['status' => 'received']);
})->middleware(VerifyPeyWebhook::class);
```

You then handle duplicates and CSRF yourself. `Route::peyWebhooks()` does both.

## Test locally

In the `local` and `testing` environments, `pey:webhook:sign` prints a sample webhook signed with your `PEY_WEBHOOK_SECRET`, and a curl command that posts it:

```bash
php artisan pey:webhook:sign codi.qr.payment_received --url=http://localhost:8000/webhooks/pey
```

- **Types:** `codi.qr.payment_received`, `codi.pn.payment_received`, `payment_link.status_updated` and `bank.account.updated`.
- **Duplicates:** running the printed command twice sends the same `webhook-id`, so the second delivery is answered as a duplicate.
- **Other options:** `--secret=` signs with another `whsec_` secret, and `--id=` sets the `webhook-id`.

For automated tests, see [Testing](testing.md#webhooks).
