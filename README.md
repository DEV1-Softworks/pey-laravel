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

## Requirements

- PHP 8.2 or later (Laravel 13 needs PHP 8.3 or later)
- Laravel 12 or 13

## Security

- The API key and webhook secrets are held in the SDK's `Secret` type. They never appear in logs, exceptions, command output or queued events.
- POST requests are never retried automatically.
- Report vulnerabilities privately to it@dev1.mx. Please don't open a public issue.

## License

MIT. See [LICENSE](LICENSE).
