<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Auth\Authenticator;
use PolygonLab\McpKit\Log\Logger;

/**
 * Validated, immutable configuration of a server (built by ServerBuilder).
 *
 * @internal
 */
final readonly class ServerConfig
{
    /**
     * @param list<string> $allowedOrigins lower-case origins (scheme://host[:port])
     */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $title,
        public ?string $instructions,
        public ToolRegistry $tools,
        public ?Authenticator $authenticator,
        public array $allowedOrigins,
        public Logger $logger,
        public int $pageSize,
        public int $cacheTtlMs,
        public string $cacheScope,
        public int $maxBodyBytes,
    ) {}
}
