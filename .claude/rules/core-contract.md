# Core contract

What this bridge uses from `dev1/pey-php-sdk`, and the version it was checked against. Update this file whenever the bridge starts using another core API, or the core constraint changes.

**Checked against:** `dev1/pey-php-sdk` **v0.1.1** (commit `da3039e`, Packagist), on 2026-11-20.

**Minimum:** `^0.1.1`. In 0.1.0, a POST whose HTTP client threw a PSR-18 `RequestExceptionInterface` was reported as never sent, so `IdempotentRequests` could release the key and a retry could duplicate the charge. Guzzle 7 and 8 both throw that interface for failures after sending. Never lower the constraint.

## Rules

- Use only the core's public API. Anything marked `@internal` is off limits: `EventFactory`, `Transport`, `HttpClientFactory`, `IdempotencyRecord`, `Testing\Internal\*` and the `PeyClient` constructor.
- Never copy core logic, such as validation, retry rules, signature checks, idempotency decisions or payload shapes. If the bridge needs something the core lacks, propose a core change (see "Open gaps").
- Throw the core's exceptions. `ConfigurationException` is final; build it with `ConfigurationException::because()`.

## What the bridge uses

| Core API | Used for | Notes |
|---|---|---|
| `Config` | built from `config('pey')` | Throws `ConfigurationException` on any invalid value. `Config::DEFAULT_BASE_URL`, `DEFAULT_CONNECT_TIMEOUT` and `DEFAULT_TIMEOUT` are the defaults. |
| `Http\RetryPolicy` | `pey.retry.max_retries` | GET only, by design. The bridge exposes no other retry setting. |
| `PeyClient::create(Config, null, LoggerInterface)` | the `PeyClientInterface` singleton | **No HTTP client is passed.** The core builds Guzzle with exact timeouts, no redirects and TLS on. |
| `PeyClientInterface` | facade root, type hints | |
| `Security\Secret` | wraps every key and secret read from config | WeakMap-backed. Refuses `serialize()` and `clone`. |
| `Webhooks\WebhookVerifier` | the `VerifyPeyWebhook` middleware | `(secret or list, tolerance, ClockInterface)`, then `verify(rawBody, headers)` |
| `Webhooks\VerifiedWebhook` | `PeyWebhookReceived`, deduplication by `$id` | |
| `Webhooks\Event\*` | wrapped by the typed Laravel events | `CodiQrPaymentReceived`, `CodiPushNotificationPaymentReceived`, `PaymentLinkStatusUpdated`, `BankAccountUpdated`, `UnknownEvent` |
| `Exception\WebhookVerificationException::failure()` | the reason logged at `warning` | A `VerificationFailure` enum value. It never contains header values. |
| `Exception\UnexpectedResponseException` | a verified body the core cannot read | Thrown by `verify()` after the signature check. Its message names a field, never a value. |
| `Webhooks\Event\WebhookEvent` | `WebhookEvents::for()` | Open interface: a type from a newer core gets only `PeyWebhookReceived`. |
| `Idempotency\IdempotencyStore` | implemented by `CacheIdempotencyStore`, bound as a singleton | `acquire` (atomic) / `remember` / `release` / `recall`. An application's own binding wins. |
| `Idempotency\IdempotentRequests` | bound non-shared | Built with the bound client and store, the container clock (staleness follows `travelTo()`) and the Pey log channel. The core's default retention (7 days) and staleness (300 s) are kept: the bridge exposes no setting for them. |
| `Testing\FakePeyClient`, `RecordedCall` | `Pey::fake()`, `Pey::recordedCalls()`, `Pey::callsTo()` | Built as `new FakePeyClient(allowedClabes, ClockInterface)` from `pey.allowed_clabes` (or the given list) and the container clock. Calls are read through `recordedCalls()` and `callsTo()`. |
| `Testing\WebhookSigner`, `SignedWebhook` | `pey:webhook:sign`, `InteractsWithPey` | Pass the timestamp from the Laravel clock. The signer defaults to `time()`. |
| `Validation\Clabe::mask()` | CLABEs in logs and output | |
| `Version::VERSION` | `php artisan about` | |

## Dependencies the core pulls in

- **Guzzle** comes through `illuminate/http`.
  - Laravel 12 resolves Guzzle 7; Laravel 13 resolves Guzzle 8.
  - The core's CI tests Guzzle 7 and Guzzle 8 since v0.1.1.

## Open gaps (core changes proposed)

1. **Event to webhook body.**
   - Needed by `pey:webhook:sign {event}` and `InteractsWithPey::postPeyWebhook(object $coreEvent)`.
   - The core only parses bodies into events (`EventFactory`, internal), and its sample payloads are in `tests/Fixtures`, which is not shipped.
   - Proposal: `Testing\WebhookPayloads::fromEvent(WebhookEvent): string` and `::sample(string $type): string`.
   - Partial workaround: `FakePeyClient`'s simulations (`simulateQrPayment()`, `simulatePushNotificationPayment()`, `simulatePaymentLinkPayment()`, `activateBankAccount()`) return documented bodies for every modelled type. The bridge's tests use them (`tests/Support/WebhookBodies.php`), and `pey:webhook:sign {event}` can too. `postPeyWebhook(object $coreEvent)` still needs `fromEvent()`.
2. **Store contract tests.** Covered on the bridge side by `tests/Idempotency/CacheIdempotencyStoreContractTestCase.php`, run on the array, file and database drivers.
   - The core's idempotency store tests live in `tests/`, which is not shipped, so the bridge keeps equivalent tests.
   - Optional proposal: an abstract contract test case shipped by the core.
3. **Personal data in queued events.** Resolved without a core change.
   - `BankAccountUpdated::$clabe` is the full CLABE, and `UnknownEvent::$payload` is the whole verified body, decoded. Both end up in queue payloads when listeners are queued.
   - The maintainer chose (2026-11-20) to document it on the Laravel events and in the README rather than filter the core objects.
