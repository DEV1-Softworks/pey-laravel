<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\Tests\TestCase;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Sleep;

/**
 * The SDK's IdempotencyStore contract (the equivalent of its own store tests,
 * which it does not ship), run against one Laravel cache driver per subclass.
 */
abstract class CacheIdempotencyStoreContractTestCase extends TestCase
{
    private CacheIdempotencyStore $store;

    /**
     * The cache store, as config/cache.php names it, that the tests run on.
     */
    abstract protected function storeName(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->store = $this->newProcessStore();
    }

    public function testAcquireStoresOnlyWhenAbsent(): void
    {
        self::assertTrue($this->store->acquire('k', 'first', 60));
        self::assertFalse($this->store->acquire('k', 'second', 60));
        self::assertSame('first', $this->store->recall('k'));
    }

    public function testRememberReplacesTheRecord(): void
    {
        $this->store->acquire('k', 'started', 60);
        $this->store->remember('k', 'completed', 60);

        self::assertSame('completed', $this->store->recall('k'));
    }

    public function testRememberOnAMissingKeyStoresTheRecord(): void
    {
        $this->store->remember('missing', 'completed', 60);

        self::assertSame('completed', $this->store->recall('missing'));
    }

    public function testReleaseFreesTheKey(): void
    {
        $this->store->acquire('k', 'started', 60);
        $this->store->release('k');
        $this->store->release('missing');

        self::assertNull($this->store->recall('k'));
        self::assertTrue($this->store->acquire('k', 'again', 60));
    }

    public function testRecordsExpire(): void
    {
        $this->store->acquire('k', 'started', 60);

        $this->travel(59)->seconds();
        self::assertSame('started', $this->store->recall('k'));
        self::assertFalse($this->store->acquire('k', 'other', 60));

        $this->travel(1)->seconds();
        self::assertNull($this->store->recall('k'));
        self::assertTrue($this->store->acquire('k', 'other', 60));
    }

    public function testRememberRenewsTheTtl(): void
    {
        $this->store->acquire('k', 'started', 10);
        $this->travel(5)->seconds();
        $this->store->remember('k', 'completed', 100);
        $this->travel(50)->seconds();

        self::assertSame('completed', $this->store->recall('k'));
    }

    public function testKeysAreIndependent(): void
    {
        $this->store->acquire('a', 'A', 60);

        self::assertTrue($this->store->acquire('b', 'B', 60));
        self::assertSame('A', $this->store->recall('a'));
        self::assertNull($this->store->recall('c'));
    }

    public function testRecordsOfTensOfKilobytesAreKeptExactly(): void
    {
        // A QR created with generateImage carries the image, base64-encoded.
        $record = '{"state":"completed","result":{"qrImage":"' . base64_encode(random_bytes(48_000)) . '","concept":"Café ñ \u{1F600}"}}';

        $this->store->acquire('k', $record, 60);

        self::assertSame($record, $this->store->recall('k'));
    }

    public function testAnotherProcessCannotAcquireAnAcquiredKey(): void
    {
        $other = $this->newProcessStore();

        self::assertTrue($this->store->acquire('k', 'mine', 60));
        self::assertFalse($other->acquire('k', 'theirs', 60));
        self::assertSame('mine', $other->recall('k'));
    }

    public function testAcquireReleasesItsLock(): void
    {
        $this->store->acquire('k', 'started', 60);

        self::assertTrue($this->locks()->lock('pey:idempotency-lock:k', 10)->get());
    }

    public function testAcquireWaitsForALockAnotherProcessHoldsAndThenGivesUp(): void
    {
        Sleep::fake(syncWithCarbon: true);
        self::assertTrue($this->locks()->lock('pey:idempotency-lock:k', 10, 'another-process')->get());
        $store = new CacheIdempotencyStore($this->repository(), $this->locks(), lockWaitSeconds: 2);

        try {
            $store->acquire('k', 'started', 60);
            self::fail('Expected a LockTimeoutException.');
        } catch (LockTimeoutException) {
            // The SDK treats a failing acquire() as "do not send".
        }

        self::assertNull($store->recall('k'));
    }

    public function testAcquireProceedsOnceTheOtherProcessReleasesItsLock(): void
    {
        $lock = $this->locks()->lock('pey:idempotency-lock:k', 10, 'another-process');
        self::assertTrue($lock->get());
        $lock->release();

        self::assertTrue($this->store->acquire('k', 'started', 60));
    }

    /**
     * A store as a new process would build it: a fresh driver instance on the
     * same backend.
     */
    protected function newProcessStore(): CacheIdempotencyStore
    {
        $this->application()->make(CacheManager::class)->forgetDriver($this->storeName());

        return new CacheIdempotencyStore($this->repository(), $this->locks());
    }

    protected function repository(): Repository
    {
        return $this->application()->make(CacheManager::class)->store($this->storeName());
    }

    protected function locks(): LockProvider
    {
        $store = $this->repository()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);

        return $store;
    }

    protected function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
