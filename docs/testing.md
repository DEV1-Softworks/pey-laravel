---
title: Testing
description: Test your Pey integration in Laravel without reaching Pey, with Pey::fake(), simulated payments and signed test webhooks.
---

# Testing

Pey has no sandbox: every request moves real money, so your tests must never reach it. `Pey::fake()` replaces the client with the SDK's in-memory `FakePeyClient`. The `InteractsWithPey` trait adds helpers to post signed webhooks and assert on the events they dispatch.

## `Pey::fake()`

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Laravel\Facades\Pey;

$pey = Pey::fake();

$qr = Pey::codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1'));   // or let your code create it

$calls = Pey::callsTo('codi.createQr');   // RecordedCall objects, with ->method and ->arguments
$all = Pey::recordedCalls();
```

`Pey::fake()` replaces the `PeyClientInterface` binding and the facade, and returns the fake.
- **Every call starts a new, empty fake.**
- **Bank account registration** is guarded by `PEY_ALLOWED_CLABES`, as in production. Pass a list to override it: `Pey::fake(['123456789012345673'])`.
- **Laravel's clock drives the fake,** so `$this->travelTo()` moves its timestamps.
- **The fake behaves like Pey where it matters:** the same request objects reject the same input, unknown ids throw `NotFoundException`, and so on.

Type-hint `PeyClientInterface` (or use the facade) in your code. A class that type-hints the concrete `PeyClient` still gets the real client. Resolve your services after calling `Pey::fake()`, because an instance built before keeps the client it was given. That includes `IdempotentRequests`, which follows the fake when you resolve it afterwards (see [Idempotency](idempotency.md#testing)).

### Simulations

The fake drives the flows your code waits for. Each simulation returns what `transactions()->get()` will now report, and the webhook body Pey would send:

| Simulation | Webhook type |
|---|---|
| `simulateQrPayment($codiQrId)` | `codi.qr.payment_received` |
| `simulatePushNotificationPayment($transactionId)` | `codi.pn.payment_received` |
| `simulatePaymentLinkPayment($paymentLinkId)` | `payment_link.status_updated` |
| `activateBankAccount($bankAccountId)` | `bank.account.updated` (returns the body only) |

### Failures

```php
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Laravel\Facades\Pey;

$pey = Pey::fake();

// The next call fails without doing anything, as if it never reached Pey.
$pey->failNext(new \RuntimeException('Pey is down.'));

// The next call takes effect (the QR exists) and then fails, as a timeout after Pey processed it would.
$pey->failNextAfterProcessing(NetworkException::create('POST', '/codi/qr', true, 'Timeout', 'The request timed out.'));
```

Use `failNextAfterProcessing()` to rehearse an unknown outcome. The second call with the same idempotency key then throws `IdempotencyOutcomeUnknownException` instead of sending again.

## Webhooks

Add the `InteractsWithPey` trait to your test case:

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\Testing\InteractsWithPey;
use Tests\TestCase;

final class PaidOrderTest extends TestCase
{
    use InteractsWithPey;

    public function test_a_paid_qr_fulfils_the_order(): void
    {
        $pey = $this->fakePey();
        $qr = Pey::codi()->createQr(new CreateQrRequest('150.00'));
        $payment = $pey->simulateQrPayment($qr->id);

        $this->postPeyWebhook($payment)->assertOk();

        $this->assertPeyWebhookDispatched(
            CodiQrPaymentReceived::class,
            fn (CodiQrPaymentReceived $received): bool => $received->event->transactionId === $payment->transaction->id,
        );
    }
}
```

The trait adds four helpers:
- **`fakePey(?array $allowedClabes = null)`** calls `Pey::fake()` and returns the fake.
- **`postPeyWebhook($webhook, ?string $uri = null, ?string $id = null)`** signs `$webhook` with the SDK's `WebhookSigner` on Laravel's clock, and posts the raw body and the `webhook-*` headers, as Pey would.
  - `$webhook` is what a simulation returned, or a body string, such as the one `activateBankAccount()` returns.
  - It goes to the `pey.webhooks` route, or `/webhooks/pey` when the route has another name.
  - It signs with your configured webhook secret. When none is configured, it configures a random one for the test.
  - Pass the same `$id` twice to send the same webhook twice: the second answer is `{"status":"duplicate"}`.
- **`assertPeyWebhookDispatched($eventClass, ?Closure $callback = null, int $times = 1)`** asserts that a package event was dispatched exactly `$times` times. Only events the callback accepts count.
- **`assertPeyWebhookNotDispatched($eventClass, ?Closure $callback = null)`** asserts that it was not dispatched.

The assertions also see events that `Event::fake()` kept from your listeners. Without `Event::fake()`, your listeners run. A listener that confirms the payment with `Pey::transactions()->get()` then sees the fake's paid transaction, so you can test the whole flow.

## Time

The fake, the webhook timestamp tolerance and the idempotency timings all follow Laravel's clock. Use `$this->travelTo()`, `$this->travel()` and `$this->freezeTime()`; never `sleep()`.

## The real API

`php artisan pey:check` is the only call this package makes on its own. Run it in your deploy pipeline, never in your test suite.
