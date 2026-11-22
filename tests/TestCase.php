<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use Dev1\Pey\Laravel\PeyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a Testbench application with the package provider registered, as
 * auto-discovery would in a real application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [PeyServiceProvider::class];
    }
}
