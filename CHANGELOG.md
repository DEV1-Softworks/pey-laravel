# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project scaffold:
  - Composer package `dev1/pey-laravel`, depending on `dev1/pey-php-sdk` ^0.1 and Laravel 12 or 13
  - an auto-discovered `PeyServiceProvider`
  - PHPUnit 11 with Testbench, PHPStan at level max with strict rules and Larastan, and PHP-CS-Fixer (PER-CS 3.0)
  - CI on Laravel 12 (PHP 8.2–8.5) and Laravel 13 (PHP 8.3–8.5), with coverage thresholds, `composer audit` and gitleaks
