# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-21

First release.

### Added

- Composer package `dev1/pey-laravel`, depending on `dev1/pey-php-sdk` ^0.1.1 and on the `illuminate/*` components of Laravel 12 or 13 (console, contracts, http, log, routing and support). 0.1.1 is the first SDK release that never sends a POST twice under one idempotency key after a client failure, on Guzzle 7 or 8. `PeyServiceProvider` is auto-discovered.
- `PeyServiceProvider` bindings, all lazy. Nothing is resolved at boot, so `artisan`, `config:cache` and `route:list` work without `PEY_API_KEY`.
  - The SDK `Config` and `PeyClient` are singletons, and `PeyClientInterface` resolves to the client. The SDK builds its own HTTP client.
  - `WebhookVerifier` uses the current and previous webhook secrets (for rotation) and the configured tolerance.
  - `Psr\Clock\ClockInterface` resolves to `LaravelClock`, which follows `travelTo()`. An application binding takes precedence.
  - `pey.logger` is the configured log channel.
- A missing API key or webhook secret throws a `ConfigurationException` naming the env variable, only when the client or verifier is resolved.
- `config/pey.php`, publishable with the `pey-config` tag:
  - every value is read from the environment, with the SDK's defaults;
  - it holds only scalars and arrays, so `config:cache` works.
- A Pey section in `php artisan about`: SDK version, base URL, whether the API key is configured, and the CLABE and webhook secret counts. Values are never shown.
- `Pey` facade (`Dev1\Pey\Laravel\Facades\Pey`) for the bound `PeyClientInterface`.
- `Pey::fake()` replaces the client with the SDK's `FakePeyClient` and returns it, like `Http::fake()`. It never talks to the network.
  - Bank account registration is guarded by `pey.allowed_clabes`, or by the list passed to `fake()`.
  - It uses the container's clock, so `travelTo()` moves its timestamps.
  - `Pey::recordedCalls()` and `Pey::callsTo($method)` return the calls it recorded, for assertions.
- Webhooks:
  - `Route::peyWebhooks(string $uri = '/webhooks/pey')` registers a POST route named `pey.webhooks`, behind the `VerifyPeyWebhook` middleware and excluded from CSRF verification.
  - `VerifyPeyWebhook` verifies the raw body and the headers with the SDK's `WebhookVerifier`. A failure is answered `400 {"error":"invalid_webhook"}`, and only the reason is logged.
  - Each `webhook-id` is deduplicated in `pey.webhooks.dedup_store` for `pey.webhooks.dedup_ttl` (72 hours). A repeat is answered `200` without dispatching. A failing listener releases the id, so Pey's retry is dispatched.
  - Events: `PeyWebhookReceived`, then `CodiQrPaymentReceived`, `CodiPushNotificationPaymentReceived`, `PaymentLinkStatusUpdated`, `BankAccountUpdated` or `UnknownPeyEvent`. They wrap the SDK's verified objects only.
  - A warning at boot when the dedup store cannot recognize a repeated delivery: `array` outside tests, `file` in production, or `null`.
- Idempotency:
  - `CacheIdempotencyStore` implements the SDK's `IdempotencyStore` on the cache store `pey.idempotency.store` (the default store when unset). `acquire()` takes `Cache::lock()` on the key and then calls `add()`. Storage errors are thrown.
  - Stores that cannot lock across processes are refused when resolved: no locks, `null`, or `array` outside tests.
  - `IdempotencyStore` resolves to it. `IdempotentRequests` is bound with the client, the store, the container clock and the Pey log channel, and follows `Pey::fake()`.
  - A `file` store in production is accepted with a warning: its locks hold on one server only.
  - The stale window is the SDK's 300 seconds, or a minute longer than `pey.timeout` when that is longer, so a request still in flight is never taken for a crash.
- Console:
  - `pey:check` shows the configuration summary and validates the client, the webhook verifier and dedup store (when a secret is set) and the idempotency store. It then calls `GET /transaction/api-key` once, first page only. It exits 1 on any failure, and never prints a secret, a CLABE or a response body.
  - `pey:webhook:sign {event}`, in the local and testing environments only, prints a sample webhook signed with the configured secret (or `--secret`), and a curl command that posts it to the `pey.webhooks` route.
- `Dev1\Pey\Laravel\Testing\InteractsWithPey` for application tests:
  - `fakePey()` replaces the client with the SDK's fake.
  - `postPeyWebhook()` signs a simulated payment (or a body) with the configured secret, or a random test secret, and posts it to the `pey.webhooks` route.
  - `assertPeyWebhookDispatched()` and `assertPeyWebhookNotDispatched()` count the package's events, also under `Event::fake()`.
- Documentation for labs.dev1.mx in `docs/`: installation, configuration, webhooks, idempotency, testing and security. A README quick start goes from install to confirming a transaction. `DocumentationTest` checks that every PHP example parses and its imports exist, that every link and anchor resolves, and that the configuration page lists exactly the variables `config/pey.php` reads.
- Tooling: PHPUnit 11 with Testbench, PHPStan at level max with strict rules and Larastan (no baseline), and PHP-CS-Fixer (PER-CS 3.0). CI runs Laravel 12 (PHP 8.2–8.5) and Laravel 13 (PHP 8.3–8.5) with a Redis service, coverage thresholds, `composer audit` and gitleaks.

[Unreleased]: https://github.com/DEV1-Softworks/pey-laravel/compare/v0.1.0...develop
[0.1.0]: https://github.com/DEV1-Softworks/pey-laravel/releases/tag/v0.1.0
