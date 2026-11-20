# dev1/pey-laravel: unofficial Laravel integration for the Pey API

Laravel 12/13 bridge for `dev1/pey-php-sdk` (CoDi payments, Mexico). Namespace `Dev1\Pey\Laravel\`, PHP 8.2+. Never present it as an official Pey or Banco de México product.

**Bridge only: never re-implement core logic.** This package binds, configures and adapts the core: container, config, facade, middleware, events, cache store and commands. Validation, retries, signatures, idempotency decisions and DTOs belong to the core. If the core lacks something, stop and propose a core change instead of duplicating it here.

Rules live in `.claude/rules/`:
- `laravel-style.md`
- `testing.md`
- `security.md` (full rules)
- `core-contract.md` (what we use from the core, checked versions, open gaps)

User docs go in `docs/` (Markdown with front matter, for labs.dev1.mx). Machine-specific notes go in `CLAUDE.local.md` (gitignored).

## Commands

- `composer test`: PHPUnit 11 on Testbench
- `composer test:coverage`: thresholds are 90% on `src/` and 100% on `Http/Middleware/`, `Webhooks/` and `Idempotency/`
- `composer analyse`: PHPStan max, strict rules and Larastan. Never add a baseline or `ignoreErrors`.
- `composer cs` checks PER-CS; `composer cs:fix` applies it
- Laravel 12 locally: `composer update --with "laravel/framework:^12.0" --with "orchestra/testbench:^10.0"`. Run `composer update` afterwards to go back.

## Security (short form)

1. The API key and webhook secrets only travel inside the core's `Secret`. They never appear in exceptions, logs, `about`, command output, events or serialized jobs.
2. Never throw at boot. A missing key must not break `artisan`, `config:cache` or `route:list`. Throw `ConfigurationException` when the client is resolved.
3. Never parse an unverified webhook body. `VerifyPeyWebhook` is the only entry point, and it verifies `$request->getContent()`.
4. Never retry POSTs. Never hand the core Laravel's HTTP client or retry helpers; the core builds its own client.
5. CLABEs appear in logs and output only through `Clabe::mask()`.
6. Queued events carry verified core event objects only: no secrets and no raw body. Tests serialize every event.
7. `.env*` is gitignored. Fixtures are obviously fake, and each one is allowlisted in gitleaks by file AND value.

## Git workflow

- `master` is production; `develop` is the integration branch. **Never create or use a branch named `main`.**
- Branch `feature/<short-name>` from the latest `develop`. One PR per feature, targeting `develop`, never stacked. Start the next feature only after the previous one is merged.
- Use Conventional Commits: small and granular, no WIP commits.
- The PR body lists what changed, tests added, what was deliberately left out, and any proposed core change.
- Self-merge only when CI is fully green. Wait for human review on `webhooks` and `idempotency-store`.
- Release: cut `release/x.y.z` from `develop`, merge it into `master` and back into `develop`, then tag `vx.y.z` on `master`.
- If `gh` is not authenticated or there is no remote, print the PR title and body instead.
