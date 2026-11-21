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

- The `VerifyPeyWebhook` middleware is the only entry point. It passes the raw `$request->getContent()` and `$request->headers->all()` to the core `WebhookVerifier`. Never call `$request->json()`, `$request->all()` or `input()` on an unverified request, and never re-encode the body. `WebhookEntryPointTest` fails on any other `$request->` read in `src/`, and on any `json_decode`.
- A failure returns `400` with the generic body `{"error":"invalid_webhook"}`. The reason (`VerificationFailure`) is logged at `warning`. A verified body the core cannot read (`UnexpectedResponseException`) gets the same `400`, and is logged at `error` with the core's message, which names a field but never its value. Never log the body, the headers (the webhook id included) or the secret.
- Deduplicate on the `webhook-id` with `add()` on the dedup store, under a hashed key. Duplicates answer `200` without dispatching.
- If a listener throws, release the id and rethrow, so Pey's retry is dispatched. Listeners must therefore be idempotent.
- `pey.webhooks.dedup_ttl` must be at least twice `pey.webhooks.tolerance`, so a replay is still recognized. A dedup store with the `null` driver is refused when it is resolved, because every webhook would look like a duplicate.
- Warn at boot, from config only and without throwing, when webhooks are in use (a secret is set) and the dedup store cannot recognize a repeat:
  - `array` outside the testing environment (one PHPUnit process is fine);
  - `file` in production;
  - `null` anywhere.
- `Route::peyWebhooks()` excludes the CSRF middleware of Laravel 12 and 13 on its route only. Laravel skips CSRF during unit tests, so the exclusion is tested on the route's resolved middleware.
- Events carry only verified core event objects (and the `VerifiedWebhook`). Never the raw body, headers or secrets. Tests serialize every event.
- The core events are queued as they are: `BankAccountUpdated` carries the full CLABE, the holder's name and RFC, and `UnknownEvent::$payload` the whole decoded body. This was a maintainer decision (2026-11-20): documented on the events and in the README, not filtered.
- Every payment event's docblock, the README and the docs say: confirm with `Pey::transactions()->get($id)` before fulfilling.

## 4. No POST retries

- The core builds its own HTTP client and never retries a POST. Never pass it Laravel's `Http` client, a Guzzle client with retry middleware, or anything else that could resend a request.
- No setting in this bridge may enable POST retries.

## 5. CLABEs

- Mask CLABEs with the core's `Clabe::mask()` in every log line and every piece of command output. Never print a full CLABE, not even from the allowlist.

## 6. Idempotency store

- `CacheIdempotencyStore::acquire()` must be atomic across processes.
  - It takes `Cache::lock()` on the key, then calls `add()` on the store, so drivers whose `add()` is atomic guard a second time.
  - It waits a few seconds for a lock another process holds, then throws `LockTimeoutException`. It never assumes the key is free.
- When the store is resolved, refuse one that cannot lock across processes. The check is on the store instance, not on the driver name:
  - a store without `LockProvider`;
  - `null`, whose lock always succeeds;
  - `array` outside the testing environment.
- On a storage error, throw. The core treats a failing `acquire()` as "do not send".
  - `add()` answering false while the record is absent is a failed write, not "already acquired".
  - A failed `put()` in `remember()`, or a value that is not a string in `recall()`, throws too.
  - Messages never contain the key or the record.
- `file` is accepted, because its locks are atomic on one server. The package cannot tell how many servers create charges, so in production it logs a warning when the store is resolved.
- The stale window passed to `IdempotentRequests` must outlast the HTTP timeout: `max(300, ceil(pey.timeout) + 60)`. `pey.timeout` can be up to 300 seconds.
- `IdempotentRequests` is bound non-shared, so an instance resolved after `Pey::fake()` never wraps the real client.

## 7. No secrets in the repository

- `.env*` and `CLAUDE.local.md` are gitignored, and gitleaks scans the full history in CI.
- Fixtures use obviously fake values: `pey_test_not_a_real_value`, and `whsec_` + base64 of a readable test string.
- Allowlist each fake value in `.gitleaks.toml` with `condition = "AND"` on one file and the value. Never allowlist a directory.
