<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests\Support;

use PolygonLab\McpKit\Log\Logger;

/**
 * Keeps the log entries in memory for the assertions.
 */
final class MemoryLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /**
     * Every entry serialised, to search for leaked values.
     */
    public function dump(): string
    {
        return json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
