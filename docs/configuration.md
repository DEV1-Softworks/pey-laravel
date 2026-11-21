---
title: Configuration
description: Every setting of the Pey Laravel integration, its environment variable, its default and how it is validated.
---

# Configuration

Every setting is read from the environment by `config/pey.php`. That file holds only scalars and arrays, so `php artisan config:cache` works. Publish it with `php artisan vendor:publish --tag=pey-config` if you want to change it.

## Settings

| Environment variable | Config key | Default | Notes |
|---|---|---|---|
| `PEY_API_KEY` | `pey.api_key` | none | Required to call Pey. |
| `PEY_BASE_URL` | `pey.base_url` | `https://api.pey.mx` | HTTPS only. Change it only if Pey releases another environment. |
| `PEY_CONNECT_TIMEOUT` | `pey.connect_timeout` | `5` | Seconds to connect. At most the total timeout. |
| `PEY_TIMEOUT` | `pey.timeout` | `15` | Seconds for the whole request, up to 300. |
| `PEY_MAX_RETRIES` | `pey.retry.max_retries` | `2` | From 0 to 10, for GET requests only, after a network error, a 429 or a 503. POST requests are never retried. |
| `PEY_ALLOWED_CLABES` | `pey.allowed_clabes` | empty | Comma-separated CLABEs this application may register. Empty blocks every registration. |
| `PEY_WEBHOOK_SECRET` | `pey.webhooks.secrets` | none | The `whsec_…` secret from the Pey portal. Required to receive webhooks. |
| `PEY_WEBHOOK_SECRET_PREVIOUS` | `pey.webhooks.secrets` | none | The old secret, while you rotate it. |
| `PEY_WEBHOOK_TOLERANCE` | `pey.webhooks.tolerance` | `300` | Seconds a webhook's timestamp may be from now, in either direction. |
| `PEY_WEBHOOK_DEDUP_STORE` | `pey.webhooks.dedup_store` | default cache store | The cache store that remembers webhook ids. See [Webhooks](webhooks.md#duplicates). |
| `PEY_WEBHOOK_DEDUP_TTL` | `pey.webhooks.dedup_ttl` | `259200` (72 h) | Seconds a webhook id is remembered. At least twice the tolerance. |
| `PEY_IDEMPOTENCY_STORE` | `pey.idempotency.store` | default cache store | The cache store for idempotency records. See [Idempotency](idempotency.md#the-store). |
| `PEY_LOG_CHANNEL` | `pey.log_channel` | default channel | The log channel the SDK and this package write to. |

An empty value means the default. In a published `config/pey.php`, you can also give `allowed_clabes` as a list of strings.

## Validation

Nothing is validated while the application boots. Each value is checked when the object that needs it is first resolved:
- the client, for the API key, URL, timeouts, retries and CLABEs;
- the webhook verifier, for the secrets and tolerance;
- the dedup store and the idempotency store, for their cache stores.

An invalid or missing value throws the SDK's `ConfigurationException`. Its message names the setting and its environment variable, never the value:

```text
The Pey API key is not configured. Set PEY_API_KEY in your environment (config key pey.api_key).
The pey.timeout setting (PEY_TIMEOUT) must be a number of seconds.
```

The SDK enforces its own rules on top: HTTPS only, timeout bounds, the `whsec_` secret format, and CLABE check digits. An invalid CLABE is reported masked to its last four digits.

Run `php artisan pey:check` to resolve everything at once.

## Allowlisted CLABEs

Registering a bank account redirects incoming CoDi payments to it. So the SDK registers a CLABE only when it is in `PEY_ALLOWED_CLABES`, and that list is empty by default. A blocked attempt throws `BankAccountNotAllowedException` before anything is sent.

```dotenv
PEY_ALLOWED_CLABES=123456789012345673,111111111111111115
```

Add a CLABE only after you have verified that the account is yours. `about` and `pey:check` show how many CLABEs are allowlisted, never the CLABEs themselves.

## Rotating the webhook secret

1. Create the new secret in the Pey portal.
2. Set it as `PEY_WEBHOOK_SECRET`, and move the old one to `PEY_WEBHOOK_SECRET_PREVIOUS`. Webhooks signed with either one are accepted.
3. Once Pey signs with the new secret, remove `PEY_WEBHOOK_SECRET_PREVIOUS`.

## Logging

The SDK logs every request at `debug` (method, path, status and duration), and errors at `warning` or `error`. The `Authorization` header is always redacted, and bodies are never logged. This package logs rejected webhooks at `warning`, with the reason only.

Set `PEY_LOG_CHANNEL` to send all of it to a dedicated channel:

```dotenv
PEY_LOG_CHANNEL=pey
```

The package passes that channel to the SDK directly. Your application's own `LoggerInterface` binding is never replaced.

## `config:cache`

After `php artisan config:cache`, Laravel writes every configuration value, including `PEY_API_KEY` and the webhook secrets, to `bootstrap/cache/config.php`. That is how every Laravel credential works. Protect that file as you protect `.env`, and never commit it.
