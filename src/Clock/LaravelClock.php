<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Clock;

use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock that reads Laravel's `Date::now()`, in UTC.
 *
 * The SDK's webhook tolerance and idempotency staleness use it, so
 * `$this->travelTo()` and `$this->freezeTime()` in tests move them too.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface(Date::now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
