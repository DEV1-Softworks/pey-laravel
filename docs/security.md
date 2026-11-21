---
title: Security
description: What the Pey Laravel integration protects by default, and what your application remains responsible for.
---

# Security

Pey is production only. One API key can create charges and register the bank accounts that receive the money. This package wires the SDK into Laravel without weakening any of [the SDK's protections](https://github.com/DEV1-Softworks/pey-php-sdk/blob/master/docs/security.md), and adds its own for the Laravel side.

## What the package protects

### Secrets stay in `Secret`

The API key and the webhook secrets are wrapped in the SDK's `Secret` as soon as they are read from the configuration.
- **Dumps:** `var_dump`, `print_r`, `var_export`, `json_encode` and error trackers have nothing to show. Serializing or cloning a secret throws.
- **Stack traces:** every parameter that receives one is marked `#[\SensitiveParameter]`, so traces don't show it.
- **Logs, exceptions and commands:** a secret never appears in a log record, an exception, `php artisan about`, `pey:check` or `pey:webhook:sign` output, or a queued event.
- **Reports:** `about` and `pey:check` report "configured" or "missing", and counts. Never a value, a prefix or a length.

The only plain-text copy is Laravel's own configuration, which means `bootstrap/cache/config.php` after `config:cache`.

### Configuration fails late and clearly

Nothing is resolved while the application boots, so `artisan`, `config:cache` and `route:list` work without any Pey setting. A missing or invalid setting throws a `ConfigurationException` when it is first needed. Its message names the environment variable, never the value.

### Webhooks are verified before they are read

The `VerifyPeyWebhook` middleware is the only entry point. It verifies the raw body and the headers with the SDK before anything parses the body.
- **Rejections:** a failure is answered with a generic `400`, and only the reason is logged: never the body, the headers or the secret.
- **Duplicates:** each webhook id is handled once, through an atomic `add()` on a shared cache store.
- **Events:** they carry the SDK's verified objects only, never the raw body, the headers or a secret. The package's tests serialize every event as the queue would.

### Charges are never retried

The SDK builds its own HTTP client: exact timeouts, no redirects, TLS verification always on. It never retries a POST. This package never hands it Laravel's HTTP client or any retry middleware, and no setting enables POST retries.

### Each charge at most once

`IdempotentRequests` runs on a store that locks across processes. Stores that cannot are refused, and storage errors stop the request instead of reporting success. See [Idempotency](idempotency.md).

### CLABEs

- **Registration:** the SDK registers a bank account only if its CLABE is in `PEY_ALLOWED_CLABES`, which is empty by default.
- **Output:** CLABEs appear in logs and command output only masked to their last four digits, and `about` and `pey:check` show counts only.
- **Samples:** the `pey:webhook:sign` bank account sample uses a fake, masked CLABE.

### Commands

- **`pey:check` output:** on an API error it prints the HTTP status, Pey's error type and the request id. It never prints Pey's error message, which comes from the response body.
- **`pey:webhook:sign` environments:** it exists only in the `local` and `testing` environments, and refuses to run anywhere else.
- **`pey:webhook:sign` secret:** it validates the secret's format and never prints it.

## What remains your responsibility

- **Keep secrets out of your repository.** `PEY_API_KEY` and `PEY_WEBHOOK_SECRET` belong in `.env` or a secret manager. Never commit `.env` or `bootstrap/cache/config.php`.
- **Confirm before fulfilling.** A webhook is a notification. Confirm each payment with `Pey::transactions()->get()` before you ship an order (see [Webhooks](webhooks.md#confirm-before-fulfilling)).
- **Protect your queue storage.** A queued `BankAccountUpdated` carries a full CLABE, a name and an RFC, and a queued `UnknownPeyEvent` carries a whole webhook body. The queue driver stores them, and `failed_jobs` keeps them if the listener fails.
- **Use shared stores.** Webhook deduplication and idempotency are only as strong as their cache stores. Use stores that every worker and server share, and that do not evict early.
- **Serve the webhook over HTTPS**, and keep the route's URI to yourself.
- **Rotate secrets** when someone who knew them leaves, with `PEY_WEBHOOK_SECRET_PREVIOUS` during the change (see [Configuration](configuration.md#rotating-the-webhook-secret)).
- **Allowlist only your own accounts.** Add a CLABE to `PEY_ALLOWED_CLABES` only after verifying the account is yours.
- **Test with the fake.** Pey has no sandbox. Use [`Pey::fake()`](testing.md) in tests, and very small amounts when you try the real API.

## Reporting a vulnerability

Report vulnerabilities privately to it@dev1.mx. Please don't open a public issue.
