<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Idempotency;

use Dev1\Pey\Idempotency\IdempotencyStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * The SDK's IdempotencyStore on a Laravel cache store, for IdempotentRequests.
 *
 * acquire() takes `Cache::lock()` on the key, then calls add() on the store:
 * two guards, so two processes never both acquire a key. The lock makes add()
 * atomic even on drivers whose own add() is not, and drivers whose add() is
 * atomic (redis, memcached, database, dynamodb) guard a second time.
 *
 * Use a store that every process creating charges shares, and that never
 * evicts a record before its TTL (for example `database`, or a Redis database
 * with `noeviction`). An evicted record allows a duplicate charge.
 *
 * Every storage error is thrown, never reported as success. The SDK treats a
 * failing acquire() as "do not send". Records and keys never appear in a
 * message.
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    private const PREFIX = 'pey:idempotency:';

    private const LOCK_PREFIX = 'pey:idempotency-lock:';

    /**
     * How long a lock outlives a process that died while holding it. The lock
     * is held only around one add().
     */
    private const LOCK_SECONDS = 10;

    /**
     * @param int $lockWaitSeconds how long acquire() waits for a lock another process holds, then throws
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly LockProvider $locks,
        private readonly int $lockWaitSeconds = 5,
    ) {}

    /**
     * @throws LockTimeoutException when another process holds the key's lock for too long
     * @throws \RuntimeException    when the store cannot write the record
     */
    public function acquire(string $key, string $record, int $ttlSeconds): bool
    {
        $lock = $this->locks->lock(self::LOCK_PREFIX . $key, self::LOCK_SECONDS);

        $acquired = $lock->block($this->lockWaitSeconds, function () use ($key, $record, $ttlSeconds): bool {
            if ($this->cache->add(self::PREFIX . $key, $record, $ttlSeconds)) {
                return true;
            }

            // add() also answers false when it could not write. Only a record
            // that is really there means "already acquired".
            return $this->cache->has(self::PREFIX . $key)
                ? false
                : throw new \RuntimeException('The Pey idempotency store could not store a record.');
        });

        return $acquired === true;
    }

    /**
     * @throws \RuntimeException when the store cannot write the record
     */
    public function remember(string $key, string $record, int $ttlSeconds): void
    {
        if (!$this->cache->put(self::PREFIX . $key, $record, $ttlSeconds)) {
            throw new \RuntimeException('The Pey idempotency store could not store a record.');
        }
    }

    public function release(string $key): void
    {
        // forget() answers false for a missing key on some drivers; that is
        // not an error. A storage error is thrown by the driver.
        $this->cache->forget(self::PREFIX . $key);
    }

    /**
     * @throws \UnexpectedValueException when the store holds something that is not a record
     */
    public function recall(string $key): ?string
    {
        $record = $this->cache->get(self::PREFIX . $key);

        return $record === null || \is_string($record)
            ? $record
            : throw new \UnexpectedValueException('The Pey idempotency store holds a value that is not a record.');
    }
}
