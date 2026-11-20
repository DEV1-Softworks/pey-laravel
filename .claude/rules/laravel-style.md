# Laravel and PHP style

## Language level

- The floor is PHP 8.2, even though Laravel 13 needs 8.3. CI runs 8.2 to 8.5. Avoid newer features:
  - 8.3: typed class constants, `#[\Override]`, `json_validate()`
  - 8.4: property hooks, asymmetric visibility, `new Foo()->bar()`, `array_find()`
  - 8.5: the pipe operator
- Start every PHP file with `declare(strict_types=1);`.

## Design

- Classes are `final` by default. Events and value wrappers are `final readonly` where Laravel allows it.
- Depend on `Illuminate\Contracts\*` (Container, Config\Repository, Cache\Factory, Events\Dispatcher) in constructors, not on concrete classes or facades. Facades are for the public API (`Pey`) and for the service provider.
- Require `illuminate/*` components, never `laravel/framework`. Classes that only exist in the framework (for example `Illuminate\Foundation\Console\AboutCommand`) are used behind `class_exists()`.
- No magic beyond what Laravel requires (facades, macros). No dynamic properties.
- Mark anything that is not public API `@internal`.

## Service provider

- `register()` only declares bindings, as closures that run at resolve time. Never resolve the client, verifier or store in `register()` or `boot()`. See `security.md`, section 2.
- Package classes are singletons keyed by the core's types: `Config`, `PeyClientInterface` (with `PeyClient` as an alias), `WebhookVerifier`, `IdempotencyStore`, `IdempotentRequests`.
- Global PSR bindings the app may already own, such as `Psr\Clock\ClockInterface`, use `singletonIf()`, so the application's binding wins.
- Never bind or replace the application's global `Psr\Log\LoggerInterface`. Pass the configured channel (`pey.log_channel`) to the core objects explicitly.
- Read settings with `config('pey.…')` inside factories. Call `env()` only in `config/pey.php`.
- The config file holds scalars and arrays only: no closures and no objects, so `config:cache` works. Parse env strings, such as comma-separated CLABEs, in a factory, not in the config file.

## Naming and layout

- `src/PeyServiceProvider.php` holds wiring only. Factories that build core objects from config live next to it (for example `src/Support/`).
- `src/Facades/Pey.php`, `src/Http/Middleware/`, `src/Http/Controllers/`, `src/Events/`, `src/Webhooks/`, `src/Idempotency/`, `src/Console/`, `src/Testing/`.
- Config keys are snake_case and mirror the brief: `api_key`, `base_url`, `connect_timeout`, `timeout`, `retry.max_retries`, `allowed_clabes`, `webhooks.*`, `idempotency.store`, `log_channel`.

## Types and static analysis

- Use native types everywhere. Add PHPDoc only for array shapes, `list<T>`, generics and `@throws`.
- `config()` returns `mixed`. Narrow it explicitly (`is_string`, `is_numeric`, …) before use, and report bad types as a `ConfigurationException` that names the config key.
- PHPStan runs at level max with strict rules and Larastan, with **no baseline**. Never add `ignoreErrors`. An inline `@phpstan-ignore <identifier>` needs a comment explaining why, and must be pointed out in the PR.

## Formatting

- PER-CS 3.0 through PHP-CS-Fixer (`composer cs:fix`), with strict comparisons, single quotes and ordered imports.
