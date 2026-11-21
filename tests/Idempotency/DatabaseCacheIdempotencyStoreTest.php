<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\WithMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The database driver, on Testbench's SQLite database with Laravel's cache
 * and cache_locks tables: records and locks shared by every process.
 */
#[CoversClass(CacheIdempotencyStore::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
#[WithMigration('cache')]
final class DatabaseCacheIdempotencyStoreTest extends CacheIdempotencyStoreContractTestCase
{
    use RefreshDatabase;

    public function testRecordsAndLocksAreRowsInTheDatabase(): void
    {
        $store = $this->newProcessStore();
        $lock = $this->locks()->lock('pey:idempotency-lock:held', 10);
        self::assertTrue($lock->get());

        $store->acquire('k', 'started', 60);

        self::assertSame(1, DB::table('cache')->count());
        self::assertSame(1, DB::table('cache_locks')->count());
        $lock->release();
    }

    protected function storeName(): string
    {
        return 'database';
    }
}
