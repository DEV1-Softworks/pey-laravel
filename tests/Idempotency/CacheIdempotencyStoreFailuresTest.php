<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Storage errors are thrown, never reported as success: the SDK treats a
 * failing acquire() as "do not send".
 */
#[CoversClass(CacheIdempotencyStore::class)]
final class CacheIdempotencyStoreFailuresTest extends TestCase
{
    public function testAcquireThrowsWhenTheRecordCannotBeWritten(): void
    {
        $store = self::storeThatCannotWrite();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Pey idempotency store could not store a record.');

        $store->acquire('k', 'started', 60);
    }

    public function testRememberThrowsWhenTheRecordCannotBeWritten(): void
    {
        $store = self::storeThatCannotWrite();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Pey idempotency store could not store a record.');

        $store->remember('k', 'completed', 60);
    }

    public function testRecallThrowsOnAValueThatIsNotARecord(): void
    {
        $cache = new Repository($locks = new ArrayStore());
        $cache->put('pey:idempotency:k', ['not' => 'a record'], 60);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The Pey idempotency store holds a value that is not a record.');

        (new CacheIdempotencyStore($cache, $locks))->recall('k');
    }

    public function testErrorsNeverShowTheKeyOrTheRecord(): void
    {
        try {
            self::storeThatCannotWrite()->acquire('order-1:qr-key-hash', '{"secret-ish":"record"}', 60);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('order-1', $e->getMessage());
            self::assertStringNotContainsString('record"', $e->getMessage());
        }
    }

    private static function storeThatCannotWrite(): CacheIdempotencyStore
    {
        $store = new class extends ArrayStore {
            public function put($key, $value, $seconds): bool
            {
                return false;
            }
        };

        return new CacheIdempotencyStore(new Repository($store), $store);
    }
}
