<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Repository;

/**
 * Remembers verified webhook ids, so a delivery Pey repeats is dispatched
 * once. It relies on the cache store's add(), which is atomic on shared stores
 * (redis, memcached, database, dynamodb). A storage error is thrown, so the
 * webhook is answered with an error and Pey delivers it again.
 *
 * @internal
 */
final class WebhookDeduplicator
{
    /**
     * 72 hours. Pey does not document how long it retries a delivery.
     */
    public const DEFAULT_TTL_SECONDS = 259_200;

    private const PREFIX = 'pey:webhook:';

    /**
     * @param int $ttlSeconds how long an id is remembered
     */
    public function __construct(
        private readonly Repository $store,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * True the first time the id is seen within the TTL.
     */
    public function claim(string $webhookId): bool
    {
        return $this->store->add(self::key($webhookId), true, $this->ttlSeconds);
    }

    /**
     * Forgets the id, so the next delivery of it is dispatched again.
     */
    public function release(string $webhookId): void
    {
        $this->store->forget(self::key($webhookId));
    }

    /**
     * Hashed, so every driver accepts the key whatever the id contains.
     */
    private static function key(string $webhookId): string
    {
        return self::PREFIX . hash('sha256', $webhookId);
    }
}
