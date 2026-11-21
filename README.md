# Pey for Laravel

Unofficial Laravel integration for the [Pey](https://pey.mx) API: CoDi payments in Mexico.

> **Unofficial package.** `dev1/pey-laravel` is built and maintained by DEV1 Softworks Labs. It is not affiliated with, endorsed by, or supported by Pey or Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

> **Pey has no sandbox.** Every request goes to production and moves real money.

## What it is

A thin bridge between Laravel and [`dev1/pey-php-sdk`](https://github.com/DEV1-Softworks/pey-php-sdk), the framework-agnostic SDK. This package only wires the SDK into Laravel:

- container bindings and a publishable `config/pey.php`
- a `Pey` facade with `Pey::fake()` for tests
- a webhook route, with signature verification, deduplication and typed events
- an idempotency store on Laravel's cache
- `pey:check` and `pey:webhook:sign` Artisan commands

Payment logic, validation, retries, idempotency rules and webhook verification all live in the SDK, and this package never re-implements them.

## Status

Under development. The first release, `v0.1.0`, is not published yet. The quick start and the full guides will land with it.

## Webhooks

Register the route once, in `routes/web.php` or `routes/api.php`:

```php
use Illuminate\Support\Facades\Route;

Route::peyWebhooks();                 // POST /webhooks/pey, named pey.webhooks
Route::peyWebhooks('/hooks/pey');     // or any URI
```

Then set the URL in the Pey portal and `PEY_WEBHOOK_SECRET` (plus `PEY_WEBHOOK_SECRET_PREVIOUS` while rotating) in your environment.

- **Verification.** The `VerifyPeyWebhook` middleware verifies the raw body and the `webhook-*` headers with the SDK before anything reads the request. A failure is answered `400 {"error":"invalid_webhook"}`, and only the reason is logged.
- **CSRF.** The route is authenticated by its signature, so `Route::peyWebhooks()` excludes it from CSRF verification on Laravel 12 and 13. If you route Pey to your own controller instead, exclude its URI in `bootstrap/app.php`:

  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->validateCsrfTokens(except: ['webhooks/pey']); // Laravel 13 also has preventRequestForgery(except: [...])
  })
  ```

  Or register it in `routes/api.php`, which has no CSRF middleware.
- **Duplicates.** Pey can deliver a webhook more than once. Each `webhook-id` is remembered for `PEY_WEBHOOK_DEDUP_TTL` seconds (72 hours by default) in `PEY_WEBHOOK_DEDUP_STORE` (the default cache store when unset). A repeat is answered `200 {"status":"duplicate"}` and dispatches nothing.
  - Use a store that every server shares, such as `redis` or `database`. `array` forgets ids between requests, and `file` is local to one server. The package logs a warning at boot for those (and for `null`).
- **Events.** A new webhook dispatches `Dev1\Pey\Laravel\Events\PeyWebhookReceived`, then one typed event. Each typed event has the SDK's verified `$event` and the `$webhook` (id and timestamp):

  | Webhook type | Laravel event |
  |---|---|
  | `codi.qr.payment_received` | `CodiQrPaymentReceived` |
  | `codi.pn.payment_received` | `CodiPushNotificationPaymentReceived` |
  | `payment_link.status_updated` | `PaymentLinkStatusUpdated` |
  | `bank.account.updated` | `BankAccountUpdated` |
  | any other type | `UnknownPeyEvent` |

- **Listeners.** Make them queued (`ShouldQueue`), so Pey gets its `200` quickly, and idempotent. If a listener throws, the webhook is answered with an error and the id is forgotten, so Pey's next delivery is dispatched again.

> **Confirm before fulfilling.** A webhook is a notification, not proof of payment. Before you ship an order, confirm it with `Pey::transactions()->get($received->event->transactionId)`: status PAID, the expected payable, and the amount.

```php
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Facades\Pey;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FulfilQrOrder implements ShouldQueue
{
    public function handle(CodiQrPaymentReceived $received): void
    {
        $transaction = Pey::transactions()->get($received->event->transactionId);

        if ($transaction->isPaid()) {
            // Fulfil once per transaction id.
        }
    }
}
```

**Personal data in queues.** A queued listener stores its event in the queue payload (and in `failed_jobs` if it fails). Events never carry the raw body, the headers or a secret. But `BankAccountUpdated` carries the account's full CLABE, the holder's name and their RFC, and `UnknownPeyEvent` carries the whole decoded body. Protect the queue storage like your database, and mask CLABEs with `Dev1\Pey\Validation\Clabe::mask()` before logging them.

## Idempotency

Pey has no idempotency keys, so a double click, a queue retry or a retry after a timeout can create a second charge. The SDK's `IdempotentRequests` calls Pey at most once per key. This package binds it on a Laravel cache store:

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Idempotency\IdempotentRequests;

public function store(Order $order, IdempotentRequests $idempotent)
{
    $qr = $idempotent->createQr("order-{$order->id}:qr", new CreateQrRequest($order->total, concept: "Order {$order->id}"));
    // Calling again with the same key returns the same QR without calling Pey.
}
```

Derive keys from your own ids, so every retry of the same order uses the same key. The SDK's [idempotency guide](https://github.com/DEV1-Softworks/pey-php-sdk/blob/master/docs/idempotency.md) explains each outcome. It also covers what to do when an outcome is unknown, where the key stays blocked until you reconcile it and call `release()`.

**The store.** Records are kept in `PEY_IDEMPOTENCY_STORE`, or the default cache store when it is unset.
- **Shared.** Every process that creates charges must share it.
- **Atomic locks.** The package takes `Cache::lock()` on each key.
- **No early eviction.** A record evicted before its 7 days allows a duplicate charge.

`database`, or a Redis database with `noeviction`, fits. The package refuses three kinds of store when it is first resolved:
- a store without locks;
- `null`;
- `array` outside tests.

`file` locks hold on a single server only.

In tests, `Pey::fake()` applies to `IdempotentRequests` too: resolve it after faking.

## Requirements

- PHP 8.2 or later (Laravel 13 needs PHP 8.3 or later)
- Laravel 12 or 13

## Security

- The API key and webhook secrets are held in the SDK's `Secret` type. They never appear in logs, exceptions, command output or queued events.
- POST requests are never retried automatically.
- Report vulnerabilities privately to it@dev1.mx. Please don't open a public issue.

## License

MIT. See [LICENSE](LICENSE).
