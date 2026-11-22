<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\RecordingLogger;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Version;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(VerifyPeyWebhook::class)]
#[UsesClass(WebhookDeduplicator::class)]
final class PeyServiceProviderTest extends TestCase
{
    public function testItIsRegisteredWhenTheApplicationBoots(): void
    {
        self::assertInstanceOf(PeyServiceProvider::class, $this->application()->getProvider(PeyServiceProvider::class));
    }

    public function testItMergesThePackageConfig(): void
    {
        self::assertSame(Config::DEFAULT_BASE_URL, config('pey.base_url'));
        self::assertSame(72 * 3600, config('pey.webhooks.dedup_ttl'));
    }

    /**
     * @param class-string|string $abstract
     */
    #[DataProvider('provideLazyBindings')]
    public function testBootingResolvesNothingFromTheSdk(string $abstract): void
    {
        self::assertTrue($this->application()->bound($abstract));
        self::assertFalse($this->application()->resolved($abstract), $abstract . ' must not be resolved while the application boots.');
    }

    public function testArtisanWorksWithoutAnyPeySettings(): void
    {
        self::assertSame(0, Artisan::call('route:list'));
        self::assertSame(0, Artisan::call('about'));
        self::assertStringContainsString('Pey', Artisan::output());
    }

    public function testAboutShowsThePeySectionWithoutThrowingWhenNothingIsConfigured(): void
    {
        self::assertSame([
            'sdk_version' => Version::VERSION,
            'base_url' => Config::DEFAULT_BASE_URL,
            'api_key' => 'missing',
            'allowlisted_clabes' => '0',
            'webhook_secrets' => '0',
        ], $this->aboutSection());
    }

    public function testAboutNeverShowsTheConfiguredSecrets(): void
    {
        $this->configure([
            'pey.api_key' => TestSecrets::API_KEY,
            'pey.allowed_clabes' => TestSecrets::CLABE,
            'pey.webhooks.secrets' => [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()],
        ]);

        self::assertSame(0, Artisan::call('about'));
        $output = Artisan::output();

        self::assertSame('configured', $this->aboutSection()['api_key'] ?? null);
        foreach ([TestSecrets::API_KEY, TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret(), TestSecrets::CLABE] as $hidden) {
            self::assertStringNotContainsString($hidden, $output);
        }
    }

    public function testResolvingTheClientWithoutAnApiKeyThrowsAClearError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Set PEY_API_KEY in your environment');

        $this->application()->make(PeyClientInterface::class);
    }

    public function testItResolvesOneRealClientWhenConfigured(): void
    {
        $this->configure(['pey.api_key' => TestSecrets::API_KEY]);
        $app = $this->application();

        $client = $app->make(PeyClientInterface::class);

        self::assertInstanceOf(PeyClient::class, $client);
        self::assertSame($client, $app->make(PeyClientInterface::class));
        self::assertSame($client, $app->make(PeyClient::class));
    }

    public function testTheSdkConfigIsASingletonBuiltFromLaravelConfig(): void
    {
        $this->configure([
            'pey.api_key' => TestSecrets::API_KEY,
            'pey.timeout' => '20',
            'pey.allowed_clabes' => TestSecrets::CLABE . ',' . TestSecrets::OTHER_CLABE,
        ]);
        $app = $this->application();

        $config = $app->make(Config::class);

        self::assertSame($config, $app->make(Config::class));
        self::assertSame(TestSecrets::API_KEY, $config->apiKey()->reveal());
        self::assertSame(20.0, $config->timeout());
        self::assertSame([TestSecrets::CLABE, TestSecrets::OTHER_CLABE], $config->allowedClabes());
    }

    public function testResolvingTheWebhookVerifierWithoutASecretThrowsAClearError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Set PEY_WEBHOOK_SECRET in your environment');

        $this->application()->make(WebhookVerifier::class);
    }

    public function testTheWebhookVerifierIsASingletonThatUsesTheContainerClock(): void
    {
        $this->configure(['pey.webhooks.secrets' => [TestSecrets::webhookSecret()]]);
        $app = $this->application();
        $signedAt = new \DateTimeImmutable('2001-02-03 04:05:06', new \DateTimeZone('UTC'));
        $app->instance(ClockInterface::class, new class ($signedAt) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        });

        $verifier = $app->make(WebhookVerifier::class);
        $signed = WebhookSigner::sign(TestSecrets::webhookSecret(), '{"type":"pey_laravel.test"}', 'msg_clock', $signedAt->getTimestamp());

        self::assertSame($verifier, $app->make(WebhookVerifier::class));
        self::assertSame('msg_clock', $verifier->verify($signed->body, $signed->headers)->id);
    }

    public function testTheClockIsLaravelsByDefault(): void
    {
        $this->travelTo(new \DateTimeImmutable('2031-05-06 07:08:09 UTC'));

        self::assertSame('2031-05-06T07:08:09+00:00', $this->application()->make(ClockInterface::class)->now()->format(\DATE_ATOM));
    }

    public function testAnApplicationClockIsNeverReplaced(): void
    {
        $app = $this->application();
        $clock = new LaravelClock();
        $app->instance(ClockInterface::class, $clock);

        (new PeyServiceProvider($app))->register();

        self::assertSame($clock, $app->make(ClockInterface::class));
    }

    public function testTheLoggerIsTheDefaultChannelWhenNoneIsConfigured(): void
    {
        self::assertSame(Log::channel(), $this->application()->make(PeyServiceProvider::LOGGER));
    }

    public function testTheLoggerIsTheConfiguredChannel(): void
    {
        $this->configure(['pey.log_channel' => 'single']);

        self::assertSame(Log::channel('single'), $this->application()->make(PeyServiceProvider::LOGGER));
    }

    public function testTheWebhookDedupStoreIsASingletonBuiltFromLaravelConfig(): void
    {
        $app = $this->application();

        $deduplicator = $app->make(WebhookDeduplicator::class);

        self::assertSame($deduplicator, $app->make(WebhookDeduplicator::class));
        self::assertTrue($deduplicator->claim('msg_provider'));
        self::assertFalse($deduplicator->claim('msg_provider'));
    }

    public function testOnlyTheWebhookMiddlewareGetsThePeyLogChannel(): void
    {
        $this->configure(['pey.webhooks.secrets' => [TestSecrets::webhookSecret()]]);
        $app = $this->application();
        $logger = new RecordingLogger();
        $app->instance(PeyServiceProvider::LOGGER, $logger);

        $response = $app->make(VerifyPeyWebhook::class)->handle(Request::create('/webhooks/pey', 'POST'), static fn(): Response => new Response());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertCount(1, $logger->records);
        // Laravel's own PSR-3 binding is untouched.
        self::assertSame('log', $app->getAlias(LoggerInterface::class));
    }

    // -------------------------------------------------------- webhook route

    public function testTheWebhookMacroRegistersAVerifiedPostRoute(): void
    {
        $route = Route::peyWebhooks();

        self::assertSame(['POST'], $route->methods());
        self::assertSame('webhooks/pey', $route->uri());
        self::assertSame(PeyServiceProvider::WEBHOOK_ROUTE, $route->getName());
        self::assertSame(PeyWebhookController::class, $route->getControllerClass());
        self::assertSame([VerifyPeyWebhook::class], $route->middleware());
    }

    public function testTheWebhookRouteTakesAnyUri(): void
    {
        self::assertSame('hooks/pey/live', Route::peyWebhooks('/hooks/pey/live')->uri());
    }

    /**
     * Laravel skips CSRF verification while unit tests run, so HTTP tests
     * cannot prove the exclusion. The route's resolved middleware can.
     */
    public function testTheWebhookRouteSkipsCsrfVerificationInTheWebGroup(): void
    {
        $app = $this->application();
        // The HTTP kernel gives the router its middleware groups.
        $app->make(HttpKernel::class);

        $webhook = $plain = null;
        Route::middleware('web')->group(static function () use (&$webhook, &$plain): void {
            $webhook = Route::peyWebhooks();
            $plain = Route::post('/pey-laravel-test/plain', static fn(): string => 'ok');
        });
        self::assertInstanceOf(\Illuminate\Routing\Route::class, $webhook);
        self::assertInstanceOf(\Illuminate\Routing\Route::class, $plain);

        $router = $app->make(Router::class);
        $csrf = static fn(\Illuminate\Routing\Route $route): array => array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            static fn(mixed $name): bool => \is_string($name) && preg_match('/Csrf|RequestForgery/', $name) === 1,
        ));

        self::assertNotSame([], $csrf($plain), 'The web group must verify CSRF tokens on other routes.');
        self::assertSame([], $csrf($webhook));
        self::assertContains(VerifyPeyWebhook::class, $router->gatherRouteMiddleware($webhook));
    }

    public function testTheWebhookRouteIsListed(): void
    {
        Route::peyWebhooks();

        self::assertSame(0, Artisan::call('route:list', ['--name' => PeyServiceProvider::WEBHOOK_ROUTE]));
        self::assertStringContainsString('webhooks/pey', Artisan::output());
    }

    // ------------------------------------------------------- boot warning

    public function testItWarnsAtBootWhenTheDedupStoreCannotRecognizeDuplicates(): void
    {
        $logger = $this->bootWithWebhooksOutsideTests();

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('uses the array cache driver', $logger->records[0]['message']);
        self::assertStringNotContainsString(TestSecrets::webhookSecret(), $logger->dump());
    }

    public function testItDoesNotWarnInTests(): void
    {
        $this->configure(['pey.webhooks.secrets' => [TestSecrets::webhookSecret()]]);
        $app = $this->application();
        $logger = new RecordingLogger();
        $app->instance(PeyServiceProvider::LOGGER, $logger);

        (new PeyServiceProvider($app))->boot();

        self::assertSame([], $logger->records);
    }

    public function testTheBootWarningNeverBreaksTheBoot(): void
    {
        $this->configure(['pey.log_channel' => 42]);

        $this->bootWithWebhooksOutsideTests(bindLogger: false);

        self::assertTrue(Router::hasMacro('peyWebhooks'));
    }

    public function testTheConfigFileIsPublishedWithThePeyConfigTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(PeyServiceProvider::class, 'pey-config');

        self::assertCount(1, $paths);
        self::assertSame(realpath(\dirname(__DIR__) . '/config/pey.php'), realpath((string) array_key_first($paths)));
        self::assertSame($this->application()->configPath('pey.php'), reset($paths));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLazyBindings(): iterable
    {
        yield 'SDK config' => [Config::class];
        yield 'client' => [PeyClient::class];
        yield 'client interface' => [PeyClientInterface::class];
        yield 'webhook verifier' => [WebhookVerifier::class];
        yield 'logger' => [PeyServiceProvider::LOGGER];
        yield 'webhook dedup store' => [WebhookDeduplicator::class];
        yield 'idempotency store' => [IdempotencyStore::class];
        yield 'cache idempotency store' => [CacheIdempotencyStore::class];
        yield 'idempotent requests' => [IdempotentRequests::class];
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/pey-laravel-test', static fn(): string => 'ok');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configure(array $settings): void
    {
        foreach ($settings as $key => $value) {
            config()->set($key, $value);
        }
    }

    /**
     * Boots the provider again in the local environment, with a webhook
     * secret and Testbench's array cache.
     */
    private function bootWithWebhooksOutsideTests(bool $bindLogger = true): RecordingLogger
    {
        $app = $this->application();
        $app->instance('env', 'local');
        $this->configure(['pey.webhooks.secrets' => [TestSecrets::webhookSecret(), null]]);
        $logger = new RecordingLogger();
        if ($bindLogger) {
            $app->instance(PeyServiceProvider::LOGGER, $logger);
        }

        (new PeyServiceProvider($app))->boot();

        return $logger;
    }

    /**
     * The "Pey" section of `php artisan about --json`.
     *
     * @return array<array-key, mixed>
     */
    private function aboutSection(): array
    {
        self::assertSame(0, Artisan::call('about', ['--json' => true]));
        $about = json_decode(Artisan::output(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($about);
        self::assertIsArray($about['pey'] ?? null);

        return $about['pey'];
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
