<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Http\Controllers;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PaymentLinkStatusUpdated;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Events\UnknownPeyEvent;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\PostsPeyWebhooks;
use Dev1\Pey\Laravel\Tests\Support\WebhookBodies;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Dev1\Pey\Webhooks\Event;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(PeyWebhookController::class)]
#[UsesClass(VerifyPeyWebhook::class)]
#[UsesClass(WebhookDeduplicator::class)]
#[UsesClass(WebhookEvents::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(PeyWebhookReceived::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(CodiPushNotificationPaymentReceived::class)]
#[UsesClass(PaymentLinkStatusUpdated::class)]
#[UsesClass(BankAccountUpdated::class)]
#[UsesClass(UnknownPeyEvent::class)]
final class PeyWebhookControllerTest extends TestCase
{
    use PostsPeyWebhooks;

    /**
     * @var list<object>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->configureWebhookSecrets();

        EventFacade::listen('Dev1\Pey\Laravel\Events\*', function (string $name, array $payload): void {
            $event = $payload[0] ?? null;
            self::assertIsObject($event);
            $this->dispatched[] = $event;
        });
    }

    /**
     * @param class-string $laravelEvent
     * @param class-string $coreEvent
     */
    #[DataProvider('provideWebhooks')]
    public function testItDispatchesTheGenericEventThenTheTypedOne(string $body, string $laravelEvent, string $coreEvent): void
    {
        $this->postWebhook(self::sign($body, 'msg_typed'))->assertOk()->assertExactJson(['status' => 'received']);

        self::assertCount(2, $this->dispatched);
        [$generic, $typed] = $this->dispatched;
        self::assertInstanceOf(PeyWebhookReceived::class, $generic);
        self::assertInstanceOf($laravelEvent, $typed);
        self::assertTrue(property_exists($typed, 'event') && property_exists($typed, 'webhook'));
        self::assertInstanceOf($coreEvent, $typed->event);
        self::assertSame($generic->webhook, $typed->webhook);
        self::assertSame($generic->webhook->event, $typed->event);
        self::assertSame('msg_typed', $generic->webhook->id);
    }

    public function testADuplicateIsAcknowledgedWithoutDispatching(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment(), 'msg_duplicate');

        $this->postWebhook($signed)->assertOk()->assertExactJson(['status' => 'received']);
        $this->postWebhook($signed)->assertOk()->assertExactJson(['status' => 'duplicate']);
        $this->postWebhook(self::sign($signed->body, 'msg_duplicate'))->assertOk()->assertExactJson(['status' => 'duplicate']);

        self::assertCount(2, $this->dispatched);
    }

    public function testDuplicatesAreRecognizedByIdNotByBody(): void
    {
        $body = WebhookBodies::qrPayment();

        $this->postWebhook(self::sign($body, 'msg_first'))->assertOk()->assertExactJson(['status' => 'received']);
        $this->postWebhook(self::sign($body, 'msg_second'))->assertOk()->assertExactJson(['status' => 'received']);

        self::assertCount(4, $this->dispatched);
    }

    public function testIdsAreRememberedForTheDedupTtl(): void
    {
        config()->set('pey.webhooks.dedup_ttl', 600);
        $this->postWebhook(self::sign(WebhookBodies::UNKNOWN, 'msg_ttl'))->assertOk();

        $this->travel(599)->seconds();
        $this->postWebhook(self::sign(WebhookBodies::UNKNOWN, 'msg_ttl'))->assertExactJson(['status' => 'duplicate']);

        $this->travel(2)->seconds();
        $this->postWebhook(self::sign(WebhookBodies::UNKNOWN, 'msg_ttl'))->assertExactJson(['status' => 'received']);

        self::assertCount(4, $this->dispatched);
    }

    public function testAFailingListenerLetsPeyDeliverTheWebhookAgain(): void
    {
        $calls = 0;
        EventFacade::listen(CodiQrPaymentReceived::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('The listener failed.');
            }
        });
        $signed = self::sign(WebhookBodies::qrPayment(), 'msg_retry');

        $this->postWebhook($signed)->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->postWebhook($signed)->assertOk()->assertExactJson(['status' => 'received']);
        $this->postWebhook($signed)->assertOk()->assertExactJson(['status' => 'duplicate']);

        // The listener ran once per delivery that was not a duplicate: it
        // failed, then succeeded on Pey's retry.
        self::assertSame(2, $calls);
        self::assertCount(2, array_filter($this->dispatched, static fn(object $event): bool => $event instanceof PeyWebhookReceived));
    }

    public function testAStorageErrorFailsTheWebhookWithoutDispatching(): void
    {
        $store = new Repository(new class extends ArrayStore {
            public function get($key): never
            {
                throw new \RuntimeException('The cache is down.');
            }
        });
        $this->application()->instance(WebhookDeduplicator::class, new WebhookDeduplicator($store, 600));

        $this->postWebhook(self::sign(WebhookBodies::qrPayment()))->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        self::assertSame([], $this->dispatched);
    }

    public function testItRefusesARequestTheMiddlewareDidNotVerify(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->postWebhook(self::sign(WebhookBodies::qrPayment()), uri: '/pey-laravel-test/unverified');
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('VerifyPeyWebhook', $e->getMessage());
        }

        self::assertSame([], $this->dispatched);
    }

    /**
     * @return iterable<string, array{string, class-string, class-string}>
     */
    public static function provideWebhooks(): iterable
    {
        yield 'QR payment' => [WebhookBodies::qrPayment(), CodiQrPaymentReceived::class, Event\CodiQrPaymentReceived::class];
        yield 'push notification payment' => [WebhookBodies::pushNotificationPayment(), CodiPushNotificationPaymentReceived::class, Event\CodiPushNotificationPaymentReceived::class];
        yield 'payment link paid' => [WebhookBodies::paymentLinkPaid(), PaymentLinkStatusUpdated::class, Event\PaymentLinkStatusUpdated::class];
        yield 'bank account activated' => [WebhookBodies::bankAccountActivated(), BankAccountUpdated::class, Event\BankAccountUpdated::class];
        yield 'unknown type' => [WebhookBodies::UNKNOWN, UnknownPeyEvent::class, Event\UnknownEvent::class];
    }

    /**
     * The web group encrypts cookies, so it needs an application key. A
     * random one, so the repository holds no key-shaped literal.
     *
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    /**
     * Inside the web group, as in an application's routes/web.php. Laravel
     * skips CSRF verification during unit tests, so the exclusion itself is
     * proven in PeyServiceProviderTest.
     *
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineWebRoutes($router): void
    {
        Route::peyWebhooks();
        $router->post('/pey-laravel-test/unverified', PeyWebhookController::class)->withoutMiddleware('web');
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
