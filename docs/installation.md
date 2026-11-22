---
title: Installation
description: Install the unofficial Pey integration in a Laravel 12 or 13 application, set your API key and check the connection.
---

# Installation

> **Unofficial package.** `dev1/pey-laravel` is built and maintained by DEV1 Softworks Labs. It is not affiliated with, endorsed by, or supported by Pey or Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

## Before you start

- **Pey has no sandbox.** Every request goes to production and moves real money. Pey recommends testing with very small amounts, such as $0.01 MXN. In your test suite, use [`Pey::fake()`](testing.md) instead.
- **Your API key can create charges and register the bank accounts that receive money.** Keep it in your environment or a secret manager. Never commit it, log it or send it to an error tracker.
- You need PHP 8.2 or later and Laravel 12 or 13. Laravel 13 itself needs PHP 8.3 or later.

## Install

```bash
composer require dev1/pey-laravel
```

This installs the framework-agnostic SDK, [`dev1/pey-php-sdk`](https://github.com/DEV1-Softworks/pey-php-sdk), with it. The service provider is discovered automatically.

Nothing connects to Pey or reads your key while the application boots. A missing key never breaks `php artisan`, `config:cache` or `route:list`. It throws a `ConfigurationException`, naming the variable to set, the first time the client is used.

## Set your API key

Add your key to `.env`:

```dotenv
PEY_API_KEY=your-api-key-from-the-pey-portal
```

To receive webhooks, also set the `whsec_…` secret that the Pey portal shows for your webhook endpoint:

```dotenv
PEY_WEBHOOK_SECRET=whsec_your-secret-from-the-pey-portal
```

Every other setting has a safe default. See [Configuration](configuration.md).

## Publish the configuration (optional)

```bash
php artisan vendor:publish --tag=pey-config
```

This copies `config/pey.php` into your application. Every value in it still comes from the environment, and `php artisan config:cache` works.

## Check the setup

```bash
php artisan about
php artisan pey:check
```

`about` shows a Pey section: the SDK version, the base URL, whether the API key is configured, and how many CLABEs and webhook secrets are set. It never shows a value.

`pey:check` exits with code 1 when anything fails, so you can run it after a deploy or in CI:
1. It validates the client configuration, the webhook verifier and dedup store (when a webhook secret is set), and the idempotency store.
2. It calls `GET /transaction/api-key` once, first page only. The call is read-only, but it does reach production with your key.

## Use the client

Use the `Pey` facade, or type-hint the SDK's `PeyClientInterface`:

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\PeyClientInterface;

$qr = Pey::codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1042'));

final class PaymentService
{
    public function __construct(private readonly PeyClientInterface $pey) {}

    public function recentTransactionCount(): int
    {
        return \count($this->pey->transactions()->listForApiKey()->items);
    }
}
```

Type-hint `PeyClientInterface`, not the concrete `PeyClient`: `Pey::fake()` replaces only the interface binding.

The SDK's [getting started guide](https://github.com/DEV1-Softworks/pey-php-sdk/blob/master/docs/getting-started.md) covers the client itself: amounts, every request object, and the exceptions.

## What next

- [Configuration](configuration.md): every setting and its environment variable.
- [Webhooks](webhooks.md): receive Pey's notifications and confirm payments.
- [Idempotency](idempotency.md): create each charge at most once.
- [Testing](testing.md): test your integration without reaching Pey.
- [Security](security.md): what the package protects, and what remains your responsibility.
