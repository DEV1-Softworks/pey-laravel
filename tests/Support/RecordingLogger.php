<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every record in memory, for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Every record, flattened to one string, to assert something never appears.
     */
    public function dump(): string
    {
        return print_r($this->records, true) . json_encode($this->records);
    }
}
