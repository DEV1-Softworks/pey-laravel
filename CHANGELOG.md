# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Idempotency:
  - `CacheIdempotencyStore` implements the SDK's `IdempotencyStore` on the cache store `pey.idempotency.store` (the default store when unset). `acquire()` takes `Cache::lock()` on the key and then calls `add()`. Storage errors are thrown.
  - Stores that cannot lock across processes are refused when resolved: no locks, `null`, or `array` outside tests.
  - `IdempotencyStore` resolves to it. `IdempotentRequests` is bound with the client, the store, the container clock and the Pey log channel, and follows `Pey::fake()`.
  - A `file` store in production is accepted with a warning: its locks hold on one server only.
  - The stale window is the SDK's 300 seconds, or a minute longer than `pey.timeout` when that is longer, so a request still in flight is never taken for a crash.
- Webhooks:
  - `Route::peyWebhooks(string $uri = '/webhooks/pey')` registers a POST route named `pey.webhooks`, behind the `VerifyPeyWebhook` middleware and excluded from CSRF verification.
  - `VerifyPeyWebhook` verifies the raw body and the headers with the SDK's `WebhookVerifier`. A failure is answered `400 {"error":"invalid_webhook"}`, and only the reason is logged.
  - Each `webhook-id` is deduplicated in `pey.webhooks.dedup_store` for `pey.webhooks.dedup_ttl` (72 hours). A repeat is answered `200` without dispatching. A failing listener releases the id, so Pey's retry is dispatched.
  - Events: `PeyWebhookReceived`, then `CodiQrPaymentReceived`, `CodiPushNotificationPaymentReceived`, `PaymentLinkStatusUpdated`, `BankAccountUpdated` or `UnknownPeyEvent`. They wrap the SDK's verified objects only.
  - A warning at boot when the dedup store cannot recognize a repeated delivery: `array` outside tests, `file` in production, or `null`.
- Requires `illuminate/http` and `illuminate/routing`.
- `Pey` facade (`Dev1\Pey\Laravel\Facades\Pey`) for the bound `PeyClientInterface`.
- `Pey::fake()` replaces the client with the SDK's `FakePeyClient` and returns it, like `Http::fake()`. It never talks to the network.
  - Bank account registration is guarded by `pey.allowed_clabes`, or by the list passed to `fake()`.
  - It uses the container's clock, so `travelTo()` moves its timestamps.
  - `Pey::recordedCalls()` and `Pey::callsTo($method)` return the calls it recorded, for assertions.
- `config/pey.php`, publishable with the `pey-config` tag:
  - every value is read from the environment, with the SDK's defaults;
  - it holds only scalars and arrays, so `config:cache` works.
- `PeyServiceProvider` bindings, all lazy. Nothing is resolved at boot, so `artisan`, `config:cache` and `route:list` work without `PEY_API_KEY`.
  - The SDK `Config` and `PeyClient` are singletons, and `PeyClientInterface` resolves to the client. The SDK builds its own HTTP client.
  - `WebhookVerifier` uses the current and previous webhook secrets (for rotation) and the configured tolerance.
  - `Psr\Clock\ClockInterface` resolves to `LaravelClock`, which follows `travelTo()`. An application binding takes precedence.
  - `pey.logger` is the configured log channel.
- A missing API key or webhook secret throws a `ConfigurationException` naming the env variable, only when the client or verifier is resolved.
- A Pey section in `php artisan about`: SDK version, base URL, whether the API key is configured, and the CLABE and webhook secret counts. Values are never shown.
- Project scaffold:
  - Composer package `dev1/pey-laravel`, depending on `dev1/pey-php-sdk` ^0.1.1 and Laravel 12 or 13. 0.1.1 is the first SDK release that never sends a POST twice under one idempotency key after a client failure, on Guzzle 7 or 8.
  - an auto-discovered `PeyServiceProvider`
  - PHPUnit 11 with Testbench, PHPStan at level max with strict rules and Larastan, and PHP-CS-Fixer (PER-CS 3.0)
  - CI on Laravel 12 (PHP 8.2–8.5) and Laravel 13 (PHP 8.3–8.5), with coverage thresholds, `composer audit` and gitleaks
