<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the unofficial Pey PHP SDK (dev1/pey-php-sdk) into Laravel.
 *
 * This package is not affiliated with Pey or Banco de México. It only binds
 * the SDK's classes into the container; every behavior lives in the SDK.
 */
final class PeyServiceProvider extends ServiceProvider {}
