<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

/**
 * Protocol era and version of one request.
 *
 * @internal
 */
final readonly class RequestContext
{
    private function __construct(
        public bool $modern,
        public string $version,
    ) {}

    /** Request with per-request `_meta` (2026-07-28 and later). */
    public static function modern(string $version): self
    {
        return new self(true, $version);
    }

    /** Request of a handshake-based revision (2025-11-25, 2025-06-18), or the `initialize` request itself. */
    public static function legacy(string $version): self
    {
        return new self(false, $version);
    }
}
