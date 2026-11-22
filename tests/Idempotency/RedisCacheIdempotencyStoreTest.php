<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The redis driver, on a real Redis server: CI runs one, and
 * .claude/rules/testing.md explains how to run one locally. It uses database
 * 15 and a random key prefix per test, never flushes, and every key it writes
 * expires.
 *
 * PEY_TEST_REDIS_CLIENT picks phpredis or predis (default: phpredis when the
 * extension is loaded).
 */
#[CoversClass(CacheIdempotencyStore::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
#[RequiresEnv('PEY_TEST_REDIS_HOST', 'Set PEY_TEST_REDIS_HOST (and PEY_TEST_REDIS_PORT) to run the idempotency store contract on Redis.')]
final class RedisCacheIdempotencyStoreTest extends CacheIdempotencyStoreContractTestCase
{
    /**
     * Redis expires keys on its own clock, which travel() does not move, so
     * the TTL Redis was given is asserted instead.
     */
    public function testRecordsExpire(): void
    {
        $this->newProcessStore()->acquire('k', 'started', 60);

        self::assertTtlBetween(55, 60, $this->ttl('k'));
        self::assertFalse($this->newProcessStore()->acquire('k', 'other', 60));
    }

    public function testRememberRenewsTheTtl(): void
    {
        $store = $this->newProcessStore();
        $store->acquire('k', 'started', 10);

        $store->remember('k', 'completed', 100);

        self::assertTtlBetween(95, 100, $this->ttl('k'));
        self::assertSame('completed', $store->recall('k'));
    }

    public function testItRunsOnTheConfiguredRedisClient(): void
    {
        $store = $this->repository()->getStore();
        self::assertInstanceOf(RedisStore::class, $store);

        self::assertSame(self::client(), $this->application()->make('config')->get('database.redis.client'));
        self::assertTrue($this->newProcessStore()->acquire('client-check', 'started', 60));
    }

    protected function storeName(): string
    {
        return 'pey_redis';
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');
        $config->set('database.redis.client', self::client());
        $config->set('database.redis.pey_test', [
            'host' => self::env('PEY_TEST_REDIS_HOST', '127.0.0.1'),
            'port' => self::env('PEY_TEST_REDIS_PORT', '6379'),
            'database' => 15,
        ]);
        $config->set('cache.stores.pey_redis', [
            'driver' => 'redis',
            'connection' => 'pey_test',
            'lock_connection' => 'pey_test',
            'prefix' => 'pey-laravel-test-' . bin2hex(random_bytes(6)) . ':',
        ]);
    }

    private function ttl(string $key): int
    {
        $store = $this->repository()->getStore();
        self::assertInstanceOf(RedisStore::class, $store);

        $ttl = $store->connection()->command('ttl', [$store->getPrefix() . 'pey:idempotency:' . $key]);
        self::assertIsInt($ttl);

        return $ttl;
    }

    private static function assertTtlBetween(int $min, int $max, int $ttl): void
    {
        self::assertGreaterThanOrEqual($min, $ttl);
        self::assertLessThanOrEqual($max, $ttl);
    }

    private static function client(): string
    {
        return self::env('PEY_TEST_REDIS_CLIENT', \extension_loaded('redis') ? 'phpredis' : 'predis');
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
