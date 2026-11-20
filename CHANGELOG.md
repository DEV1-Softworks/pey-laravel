# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
  - Composer package `dev1/pey-laravel`, depending on `dev1/pey-php-sdk` ^0.1 and Laravel 12 or 13
  - an auto-discovered `PeyServiceProvider`
  - PHPUnit 11 with Testbench, PHPStan at level max with strict rules and Larastan, and PHP-CS-Fixer (PER-CS 3.0)
  - CI on Laravel 12 (PHP 8.2–8.5) and Laravel 13 (PHP 8.3–8.5), with coverage thresholds, `composer audit` and gitleaks
