<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener, so events can be serialized as Laravel's queue does.
 */
final class QueuedListener implements ShouldQueue
{
    public function handle(object $event): void {}
}
