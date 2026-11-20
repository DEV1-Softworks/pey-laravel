<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use Dev1\Pey\Laravel\PeyServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PeyServiceProvider::class)]
final class PeyServiceProviderTest extends TestCase
{
    public function testItIsRegisteredWhenTheApplicationBoots(): void
    {
        self::assertInstanceOf(PeyServiceProvider::class, $this->app?->getProvider(PeyServiceProvider::class));
    }
}
