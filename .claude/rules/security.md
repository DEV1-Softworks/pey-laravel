# Security rules (non-negotiable)

These apply on top of the core's rules (`dev1/pey-php-sdk`, `.claude/rules/security.md`), which this bridge must never weaken. Pey is production only: one API key can create charges and register the bank accounts that receive money.

## 1. Secrets only travel inside `Secret`

- Wrap the API key and every webhook secret in `Dev1\Pey\Security\Secret` the moment they are read from `config()`. Never keep them in a plain property, a closure's captured variables, a cached value, or an event or job.
- Put `#[\SensitiveParameter]` on every parameter that receives one.
- A secret never appears in any of these. Tests prove each one:
  - exception messages or traces
  - log records (message or context)
  - `php artisan about`
  - command output
  - event or job payloads (serialized)
- The only place a secret appears as plain text is Laravel's config repository, and therefore `bootstrap/cache/config.php` after `config:cache`. That is how every Laravel credential works; the bridge adds no other copy.
- `about` and `pey:check` report "configured" or "missing", and counts. Never the value, and never a prefix or a length.

## 2. Lazy configuration, clear failures

- Never resolve the client, verifier or store in `register()` or `boot()`. `artisan`, `config:cache`, `route:list` and `about` must work without `PEY_API_KEY`.
- When something is resolved without its configuration, throw the core's `ConfigurationException` and name the env variable to set. Never echo the configured value.
- The config file has no closures or objects, so `config:cache` works.

## 3. Webhooks

- The `VerifyPeyWebhook` middleware is the only entry point. It passes the raw `$request->getContent()` and the headers to the core `WebhookVerifier`. Never call `$request->json()`, `$request->all()` or `input()` on an unverified request, and never re-encode the body.
- A failure returns `400` with the generic body `{"error":"invalid_webhook"}`. The reason (`VerificationFailure`) is logged at `warning`. Never log the body, the headers or the secret.
- Deduplicate on the `webhook-id` with `Cache::store(...)->add()`. Duplicates answer `200` without dispatching.
- Warn at boot when the dedup store cannot deduplicate across workers or servers: `array` anywhere, or `file` in production.
- Events carry only verified core event objects (and the `VerifiedWebhook`). Never the raw body, headers or secrets. Tests serialize every event.
- Every payment event's docblock, the README and the docs say: confirm with `Pey::transactions()->get($id)` before fulfilling.

## 4. No POST retries

- The core builds its own HTTP client and never retries a POST. Never pass it Laravel's `Http` client, a Guzzle client with retry middleware, or anything else that could resend a request.
- No setting in this bridge may enable POST retries.

## 5. CLABEs

- Mask CLABEs with the core's `Clabe::mask()` in every log line and every piece of command output. Never print a full CLABE, not even from the allowlist.

## 6. Idempotency store

- `CacheIdempotencyStore::acquire()` must be atomic across processes. It uses `Cache::lock()` on the configured store and refuses stores without atomic locks (`array` outside tests) when it is resolved.
- On a storage error, throw. The core treats a failing `acquire()` as "do not send".

## 7. No secrets in the repository

- `.env*` and `CLAUDE.local.md` are gitignored, and gitleaks scans the full history in CI.
- Fixtures use obviously fake values: `pey_test_not_a_real_value`, and `whsec_` + base64 of a readable test string.
- Allowlist each fake value in `.gitleaks.toml` with `condition = "AND"` on one file and the value. Never allowlist a directory.
