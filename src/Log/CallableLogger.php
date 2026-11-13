<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Log;

/**
 * Forwards every entry to a callable: the bridge to a PSR-3 logger or to any other sink.
 *
 * PSR-3 (Monolog or any other implementation):
 *     new CallableLogger($psrLogger->log(...))
 *
 * error_log(), one JSON line per entry:
 *     new CallableLogger(fn (string $level, string $message, array $context) =>
 *         error_log(json_encode(['level' => $level, 'message' => $message] + $context)))
 */
final readonly class CallableLogger implements Logger
{
    private \Closure $sink;

    /**
     * @param callable(string, string, array<string, mixed>): mixed $sink
     */
    public function __construct(callable $sink)
    {
        $this->sink = \Closure::fromCallable($sink);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        ($this->sink)($level, $message, $context);
    }
}
