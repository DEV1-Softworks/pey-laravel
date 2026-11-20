<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel;

use Dev1\Pey\Config;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the unofficial Pey PHP SDK (dev1/pey-php-sdk) into Laravel.
 *
 * This package is not affiliated with Pey or Banco de México. It only binds
 * the SDK's classes into the container; every behavior lives in the SDK.
 *
 * Every binding is lazy. Nothing is built while the application boots, so a
 * missing PEY_API_KEY never breaks `artisan`, `config:cache` or `route:list`;
 * it throws a ConfigurationException when the client is first resolved.
 */
final class PeyServiceProvider extends ServiceProvider
{
    /**
     * Container key of the PSR-3 logger the package and the SDK write to: the
     * `pey.log_channel` channel, or the default channel when it is unset.
     */
    public const LOGGER = 'pey.logger';

    private const CONFIG_FILE = __DIR__ . '/../config/pey.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_FILE, 'pey');

        // The application's own clock wins if it binds one.
        $this->app->singletonIf(ClockInterface::class, LaravelClock::class);

        $this->app->singleton(self::LOGGER, static fn(Container $app): LoggerInterface => CoreFactory::logger(
            $app->make(Repository::class),
            $app->make(LogManager::class),
        ));

        $this->app->singleton(Config::class, static fn(Container $app): Config => CoreFactory::config($app->make(Repository::class)));

        // No HTTP client is passed: the SDK builds its own, with exact
        // timeouts, no redirects and TLS verification, and never retries a
        // POST. Laravel's HTTP client and its retry helpers stay out of it.
        $this->app->singleton(PeyClient::class, static fn(Container $app): PeyClient => PeyClient::create(
            $app->make(Config::class),
            logger: self::logger($app),
        ));

        // Pey::fake() replaces this binding; PeyClient itself is always real.
        $this->app->singleton(PeyClientInterface::class, static fn(Container $app): PeyClientInterface => $app->make(PeyClient::class));

        $this->app->singleton(WebhookVerifier::class, static fn(Container $app): WebhookVerifier => CoreFactory::webhookVerifier(
            $app->make(Repository::class),
            $app->make(ClockInterface::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_FILE => $this->app->configPath('pey.php')], 'pey-config');
        }

        // AboutCommand ships with laravel/framework, not with illuminate/*.
        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('Pey', fn(): array => CoreFactory::about($this->app->make(Repository::class)));
        }
    }

    private static function logger(Container $app): LoggerInterface
    {
        $logger = $app->make(self::LOGGER);
        \assert($logger instanceof LoggerInterface);

        return $logger;
    }
}
