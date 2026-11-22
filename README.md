# Pey for Laravel

Unofficial Laravel integration for the [Pey](https://pey.mx) API: CoDi payments in Mexico.

> **Unofficial package.** `dev1/pey-laravel` is built and maintained by DEV1 Softworks Labs. It is not affiliated with, endorsed by, or supported by Pey or Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

> **Pey has no sandbox.** Every request goes to production and moves real money. Test with [`Pey::fake()`](docs/testing.md), and use very small amounts when you try the real API.

## What it is

A thin bridge between Laravel and [`dev1/pey-php-sdk`](https://github.com/DEV1-Softworks/pey-php-sdk), the framework-agnostic SDK. This package only wires the SDK into Laravel:

- container bindings and a publishable `config/pey.php`, configured entirely from the environment;
- a `Pey` facade, with `Pey::fake()` and test helpers;
- a webhook route, with signature verification, deduplication and typed Laravel events;
- an idempotency store on Laravel's cache, so each charge is created at most once;
- the `pey:check` and `pey:webhook:sign` Artisan commands.

Payment logic, validation, retries, idempotency rules and webhook verification all live in the SDK. This package never re-implements them.

## Requirements

- PHP 8.2 or later (Laravel 13 needs PHP 8.3 or later)
- Laravel 12 or 13

## Quick start

### 1. Install

```bash
composer require dev1/pey-laravel
```

### 2. Set your environment variables

```dotenv
PEY_API_KEY=your-api-key-from-the-pey-portal
PEY_WEBHOOK_SECRET=whsec_your-secret-from-the-pey-portal
PEY_IDEMPOTENCY_STORE=database
```

Use a store that every server shares, such as `database` or `redis`; see [Idempotency](docs/idempotency.md#the-store). Then check the setup with `php artisan pey:check`, which exits 1 if anything is wrong. Every setting is described in [Configuration](docs/configuration.md).

### 3. Create a CoDi QR

Create charges through `IdempotentRequests`, keyed by your own ids, so a double click or a queue retry never charges twice:

```php
use App\Models\Order;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Idempotency\IdempotentRequests;

final class CreateOrderQr
{
    public function __construct(private readonly IdempotentRequests $pey) {}

    public function __invoke(Order $order): string
    {
        $qr = $this->pey->createQr("order-{$order->id}:qr", new CreateQrRequest(
            amount: $order->total,                 // a decimal string, such as '150.00'
            concept: "Order {$order->id}",
            metadata: ['orderId' => (string) $order->id],
        ));

        $order->update(['codi_qr_id' => $qr->id]);

        return $qr->qrString;                      // render it as a QR code for the payer
    }
}
```

### 4. Receive the webhook

Register the route, then set its URL (for example `https://shop.example/webhooks/pey`) in the Pey portal:

```php
// routes/web.php
use Illuminate\Support\Facades\Route;

Route::peyWebhooks();
```

The route verifies each webhook with the SDK, excludes itself from CSRF verification, and handles each delivery once. Then it dispatches a typed event, such as `CodiQrPaymentReceived`. See [Webhooks](docs/webhooks.md).

### 5. Confirm the transaction

A webhook is a notification, not proof of payment. In a queued listener, confirm the payment with Pey before you fulfil the order:

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
            $order->fulfilOnce($transaction->id);   // a QR can be paid more than once: fulfil per transaction
        }
    }
}
```

To try it locally, `php artisan pey:webhook:sign codi.qr.payment_received` prints a signed sample webhook and a curl command that sends it.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md): every setting, its environment variable and its default
- [Webhooks](docs/webhooks.md): the route, CSRF, duplicates, events, queued listeners and confirming payments
- [Idempotency](docs/idempotency.md): creating each charge at most once, and the store it needs
- [Testing](docs/testing.md): `Pey::fake()`, simulated payments and signed test webhooks
- [Security](docs/security.md): what the package protects, and what remains your responsibility

The SDK's own [documentation](https://github.com/DEV1-Softworks/pey-php-sdk/tree/master/docs) covers the client itself: amounts, request objects, errors and known gaps in Pey's API.

## Security

- The API key and webhook secrets are held in the SDK's `Secret` type. They never appear in logs, exceptions, command output or queued events.
- Webhooks are verified before anything reads them, and POST requests are never retried automatically.
- Report vulnerabilities privately to it@dev1.mx. Please don't open a public issue.

## Status

Before 1.0, minor versions may change the public API. Every change is listed in the [changelog](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
