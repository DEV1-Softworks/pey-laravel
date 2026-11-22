<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Illuminate\Http\Request;

/**
 * A request whose parsed input cannot be read: any attempt fails the test.
 * Only the raw body (getContent()) and the headers stay available.
 */
final class InputTrapRequest extends Request
{
    public function json(mixed $key = null, mixed $default = null): never
    {
        throw new \LogicException('The webhook body was parsed with json() before it was verified.');
    }

    public function all(mixed $keys = null): never
    {
        throw new \LogicException('The webhook input was read with all() before it was verified.');
    }

    public function input(mixed $key = null, mixed $default = null): never
    {
        throw new \LogicException('The webhook input was read with input() before it was verified.');
    }

    public function getPayload(): never
    {
        throw new \LogicException('The webhook body was parsed with getPayload() before it was verified.');
    }
}
