<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Webhooks;

use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(WebhookDeduplicator::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
final class WebhookDeduplicatorTest extends TestCase
{
    private Repository $store;

    private WebhookDeduplicator $deduplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->store = new Repository(new ArrayStore());
        $this->deduplicator = new WebhookDeduplicator($this->store, 600);
    }

    public function testAnIdIsClaimedOnce(): void
    {
        self::assertTrue($this->deduplicator->claim('msg_1'));
        self::assertFalse($this->deduplicator->claim('msg_1'));
        self::assertTrue($this->deduplicator->claim('msg_2'));
    }

    public function testAReleasedIdCanBeClaimedAgain(): void
    {
        $this->deduplicator->claim('msg_1');

        $this->deduplicator->release('msg_1');

        self::assertTrue($this->deduplicator->claim('msg_1'));
    }

    public function testAnIdIsRememberedForTheTtl(): void
    {
        $this->deduplicator->claim('msg_1');

        $this->travel(599)->seconds();
        self::assertFalse($this->deduplicator->claim('msg_1'));

        $this->travel(1)->seconds();
        self::assertTrue($this->deduplicator->claim('msg_1'));
    }

    public function testTheCacheKeyIsAHashOfTheId(): void
    {
        $id = str_repeat('~', 255);

        $this->deduplicator->claim($id);

        self::assertTrue($this->store->has('pey:webhook:' . hash('sha256', $id)));
        self::assertFalse($this->store->has($id));
    }
}
