<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Log;

/**
 * Logger that discards everything (default).
 */
final class NullLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void {}
}
