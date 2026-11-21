<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\IdempotencyInProgressException;
use Dev1\Pey\Exception\IdempotencyOutcomeUnknownException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Idempotency\InMemoryIdempotencyStore;
use Dev1\Pey\Idempotency\Operation;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\RecordingLogger;
use Dev1\Pey\Laravel\Tests\Support\ReentrantPeyClient;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\PeyClientInterface;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * IdempotentRequests as the container builds it: the bound client, the cache
 * store, the Laravel clock and the Pey log channel.
 */
#[CoversClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(CacheIdempotencyStore::class)]
#[UsesClass(Pey::class)]
final class IdempotentRequestsBindingTest extends TestCase
{
    private const KEY = 'order-1:qr';

    public function testEachChargeIsCreatedOnceAcrossInstances(): void
    {
        Pey::fake();
        $request = new CreateQrRequest('150.00', concept: 'Order 1');

        $first = $this->idempotentRequests()->createQr(self::KEY, $request);
        $second = $this->idempotentRequests()->createQr(self::KEY, $request);

        self::assertEquals($first, $second);
        self::assertCount(1, Pey::callsTo('codi.createQr'));
    }

    public function testAnUnknownOutcomeKeepsTheKeyBlocked(): void
    {
        $fake = Pey::fake();
        $fake->failNextAfterProcessing(NetworkException::create('POST', '/codi/qr', true, 'Timeout', 'The request timed out.'));
        $request = new CreateQrRequest('150.00');

        try {
            $this->idempotentRequests()->createQr(self::KEY, $request);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException) {
            // The QR may exist.
        }

        $this->expectException(IdempotencyOutcomeUnknownException::class);
        try {
            $this->idempotentRequests()->createQr(self::KEY, $request);
        } finally {
            self::assertCount(1, Pey::callsTo('codi.createQr'));
        }
    }

    public function testInstancesResolvedAfterFakingWrapTheFake(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        $before = $this->idempotentRequests();

        Pey::fake();
        $after = $this->idempotentRequests();
        $after->createQr(self::KEY, new CreateQrRequest('150.00'));

        self::assertNotSame($before, $after);
        self::assertCount(1, Pey::callsTo('codi.createQr'));
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('provideTimesInFlight')]
    public function testStalenessFollowsTheLaravelClock(int $seconds, string $expected): void
    {
        $this->freezeTime();
        $caught = null;
        $this->application()->instance(PeyClientInterface::class, new ReentrantPeyClient(function (CreateQrRequest $request) use ($seconds, &$caught): QrCode {
            // While this charge is in flight, the same key is used again.
            $this->travel($seconds)->seconds();
            try {
                $this->idempotentRequests()->createQr(self::KEY, $request);
            } catch (\Throwable $e) {
                $caught = $e;
            }

            return new QrCode('qr_reentrant', 'not-a-real-qr-string');
        }));

        $this->idempotentRequests()->createQr(self::KEY, new CreateQrRequest('150.00'));

        self::assertInstanceOf($expected, $caught);
    }

    public function testItLogsToThePeyChannel(): void
    {
        $logger = new RecordingLogger();
        $this->application()->instance(PeyServiceProvider::LOGGER, $logger);
        Pey::fake();

        $this->idempotentRequests()->release(Operation::CodiQr, self::KEY);

        self::assertCount(1, $logger->records);
        [$record] = $logger->records;
        self::assertSame('notice', $record['level']);
        self::assertStringContainsString('released by the application', $record['message']);
        self::assertStringNotContainsString(self::KEY, $logger->dump());
    }

    public function testAStoreThatCannotLockAcrossProcessesFailsWhenResolved(): void
    {
        // Testbench's default cache store uses the array driver.
        $this->application()->instance('env', 'local');
        Pey::fake();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Set PEY_IDEMPOTENCY_STORE');

        $this->idempotentRequests();
    }

    public function testAnApplicationStoreIsUsedInstead(): void
    {
        $app = $this->application();
        $app->instance(IdempotencyStore::class, new InMemoryIdempotencyStore(new LaravelClock()));
        Pey::fake();

        $this->idempotentRequests()->createQr(self::KEY, new CreateQrRequest('150.00'));
        $this->idempotentRequests()->createQr(self::KEY, new CreateQrRequest('150.00'));

        self::assertCount(1, Pey::callsTo('codi.createQr'));
        self::assertFalse($app->resolved(CacheIdempotencyStore::class));
    }

    public function testTheStoreIsOneSharedInstance(): void
    {
        $app = $this->application();

        $store = $app->make(IdempotencyStore::class);

        self::assertSame($store, $app->make(IdempotencyStore::class));
        self::assertSame($store, $app->make(CacheIdempotencyStore::class));
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function provideTimesInFlight(): iterable
    {
        yield 'still in progress' => [IdempotentRequests::DEFAULT_STALE_AFTER_SECONDS - 1, IdempotencyInProgressException::class];
        yield 'stale' => [IdempotentRequests::DEFAULT_STALE_AFTER_SECONDS + 1, IdempotencyOutcomeUnknownException::class];
    }

    private function idempotentRequests(): IdempotentRequests
    {
        return $this->application()->make(IdempotentRequests::class);
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
