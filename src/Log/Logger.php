<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Log;

/**
 * Minimal logger used by the kit. Its log() method has the same shape as the one of PSR-3,
 * so any PSR-3 logger plugs in through CallableLogger, without a dependency on psr/log.
 *
 * The kit never logs tool arguments, tool results, tokens or secrets.
 */
interface Logger
{
    /**
     * @param string               $level   one of the LogLevel constants (PSR-3 level names)
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;
}
