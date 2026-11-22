<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Clock;

use Carbon\CarbonImmutable;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LaravelClock::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
final class LaravelClockTest extends TestCase
{
    public function testItFollowsLaravelTimeTravel(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:30:00', 'America/Mexico_City'));

        $now = (new LaravelClock())->now();

        self::assertSame('2026-11-20T16:30:00+00:00', $now->format(\DATE_ATOM));
    }

    public function testItReturnsAnImmutableUtcTime(): void
    {
        $this->freezeTime();

        $now = (new LaravelClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame(Date::now()->getTimestamp(), $now->getTimestamp());
    }

    public function testItWorksWhenTheApplicationUsesImmutableDates(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            $this->travelTo(Carbon::parse('2030-01-01 00:00:00', 'UTC'));

            self::assertSame('2030-01-01T00:00:00+00:00', (new LaravelClock())->now()->format(\DATE_ATOM));
        } finally {
            Date::useDefault();
        }
    }
}
