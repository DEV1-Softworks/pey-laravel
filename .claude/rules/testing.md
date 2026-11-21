# Testing

## Basics

- PHPUnit 11 with attributes only. Every test class declares `#[CoversClass(...)]`, or `#[CoversNothing]` for project-level guards.
- Tests run in random order and fail on warnings, notices, deprecations and risky tests. Every test must be independent.
- Laravel tests extend `Dev1\Pey\Laravel\Tests\TestCase` (Testbench). Plain unit tests may extend PHPUnit's `TestCase`.
- `tests/` mirrors `src/`. Helpers live in `tests/Support/`.
- Method names are `testItDoesSomethingWhenSomething()`. Data providers are `public static provide…()`.

## No network, no real time

- **No real HTTP calls, ever.** Pey has no sandbox. Use the core's `FakePeyClient`, or a PSR-18 stub that records requests.
- Control time with Laravel's `$this->travelTo()` and `freezeTime()`. The bridge's `ClockInterface` adapter reads `Date::now()`, so the webhook tolerance and idempotency staleness follow the test clock. Never call `sleep()`.
- Never use a real API key or webhook secret. Use `pey_test_not_a_real_value` and `whsec_` + base64 of a readable test string, kept in one support file and allowlisted in gitleaks (file AND value).

## What every feature must prove

- **Lazy configuration**: the app boots, and `about`, `route:list` and `config:cache` succeed, without `PEY_API_KEY` or webhook secrets. Resolving the client without them throws `ConfigurationException`, naming the env variable but not any value.
- **Secrets**: for every object, log record, event, command output and exception that could carry the API key or a webhook secret, assert the secret is absent. Check `serialize()` (or that it throws), `var_export`, `print_r`, `json_encode`, and logged context.
- **Events**: serialize every event class (as Laravel's queue would) and assert the payload contains no secret and no raw body.
- **Webhooks**: a tampered body, a wrong secret, a stale or future timestamp and missing headers are rejected with `400` before anything parses the body. Duplicates dispatch nothing.
  - Build bodies with `tests/Support/WebhookBodies.php` (the core fake's simulations), and sign and post them with the `PostsPeyWebhooks` trait. Never hand-write a payload shape.
  - Laravel skips CSRF verification while unit tests run. Prove a CSRF exclusion on the route's resolved middleware, not over HTTP.
- **Idempotency store**: equivalent of the core's store contract tests (atomic acquire, remember, release, recall, TTL), run against a lock-capable cache driver in Testbench. A store without atomic locks is refused.
- **Commands**: non-zero exit codes on failure. Output contains no key, no full CLABE and no response body.

## Coverage

- At least 90% line coverage on `src/`, and 100% on `src/Http/Middleware`, `src/Webhooks` and `src/Idempotency`. `tools/coverage-check.php` enforces this in CI; run it locally with `composer test:coverage`.

## Matrix

- CI runs Laravel 12 on PHP 8.2–8.5 and Laravel 13 on PHP 8.3–8.5. Laravel 12 resolves Guzzle 7 and Laravel 13 resolves Guzzle 8, so behavior that depends on the HTTP client must pass on both.
