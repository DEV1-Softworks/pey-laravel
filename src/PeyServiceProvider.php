<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
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

    /**
     * Name of the route that `Route::peyWebhooks()` registers.
     */
    public const WEBHOOK_ROUTE = 'pey.webhooks';

    /**
     * Where `Route::peyWebhooks()` listens when no URI is given.
     */
    public const WEBHOOK_URI = '/webhooks/pey';

    /**
     * The base CSRF middleware of Laravel 12 and of Laravel 13. Excluding a
     * class also excludes its subclasses (ValidateCsrfToken, and an app's own
     * VerifyCsrfToken). Strings, because neither class exists in both.
     */
    private const CSRF_MIDDLEWARE = [
        'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
        'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
    ];

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

        $this->app->singleton(WebhookDeduplicator::class, static fn(Container $app): WebhookDeduplicator => CoreFactory::webhookDeduplicator(
            $app->make(Repository::class),
            $app->make(CacheFactory::class),
        ));

        $this->app->singleton(CacheIdempotencyStore::class, static fn(Container $app): CacheIdempotencyStore => CoreFactory::idempotencyStore(
            $app->make(Repository::class),
            $app->make(CacheFactory::class),
            $app instanceof Application && $app->runningUnitTests(),
            $app instanceof Application && $app->environment('production') === true,
            self::logger($app),
        ));

        // An application may bind its own store; IdempotentRequests uses it.
        $this->app->singleton(IdempotencyStore::class, static fn(Container $app): IdempotencyStore => $app->make(CacheIdempotencyStore::class));

        // Not shared: it keeps no state (the store does), and each instance
        // wraps the client bound at that moment, so Pey::fake() applies.
        $this->app->bind(IdempotentRequests::class, static fn(Container $app): IdempotentRequests => new IdempotentRequests(
            $app->make(PeyClientInterface::class),
            $app->make(IdempotencyStore::class),
            clock: $app->make(ClockInterface::class),
            logger: self::logger($app),
        ));

        // The configured channel, given to the middleware only: the
        // application's own LoggerInterface binding is never replaced.
        $this->app->when(VerifyPeyWebhook::class)
            ->needs(LoggerInterface::class)
            ->give(static fn(Container $app): LoggerInterface => self::logger($app));
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

        // The webhook is authenticated by its signature, so CSRF does not apply.
        // Macroable binds the closure to the router: $this is the Router and
        // self:: would be too, so this class is named in full.
        $csrf = self::CSRF_MIDDLEWARE;
        Router::macro('peyWebhooks', function (string $uri = PeyServiceProvider::WEBHOOK_URI) use ($csrf): Route {
            return $this->post($uri, PeyWebhookController::class)
                ->middleware(VerifyPeyWebhook::class)
                ->withoutMiddleware($csrf)
                ->name(PeyServiceProvider::WEBHOOK_ROUTE);
        });

        $this->warnAboutTheDedupStore();
    }

    /**
     * Logs, at every boot, a dedup store that cannot recognize a repeated
     * webhook. It reads config only and never throws.
     */
    private function warnAboutTheDedupStore(): void
    {
        $warning = CoreFactory::dedupStoreWarning(
            $this->app->make(Repository::class),
            $this->app->environment('production') === true,
            $this->app->runningUnitTests(),
        );

        if ($warning === null) {
            return;
        }

        try {
            self::logger($this->app)->warning($warning);
        } catch (ConfigurationException) {
            // An invalid pey.log_channel is reported when the client is resolved.
        }
    }

    /**
     * The `pey.logger` binding, which an application or a test may replace.
     */
    private static function logger(Container $app): LoggerInterface
    {
        return self::asLogger($app->make(self::LOGGER));
    }

    private static function asLogger(mixed $logger): LoggerInterface
    {
        return $logger instanceof LoggerInterface
            ? $logger
            : throw new \LogicException(\sprintf('The %s binding must resolve to a PSR-3 logger.', self::LOGGER));
    }
}
