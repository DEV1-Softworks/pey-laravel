<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The array driver: allowed in tests only, because its records and locks live
 * in one process.
 */
#[CoversClass(CacheIdempotencyStore::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
final class ArrayCacheIdempotencyStoreTest extends CacheIdempotencyStoreContractTestCase
{
    protected function storeName(): string
    {
        return 'array';
    }

    /**
     * Another array driver instance is empty: that is why the store refuses
     * the array driver outside tests. Within one process, a second store on
     * the same cache still agrees.
     */
    protected function newProcessStore(): CacheIdempotencyStore
    {
        return new CacheIdempotencyStore($this->repository(), $this->locks());
    }
}
