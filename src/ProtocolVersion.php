<?php

declare(strict_types=1);

namespace PolygonLab\McpKit;

/**
 * MCP protocol revisions understood by the kit.
 *
 * - Modern revisions (2026-07-28) are stateless: every request carries its protocol version
 *   and the client capabilities in `params._meta`, and `server/discover` replaces the handshake.
 * - Legacy revisions (2025-11-25, 2025-06-18) open with the `initialize` handshake; afterwards
 *   every HTTP request carries the `MCP-Protocol-Version` header.
 */
final class ProtocolVersion
{
    /** Latest published revision of the specification, implemented by the kit. */
    public const LATEST = '2026-07-28';

    /** Latest handshake-based revision, offered to clients that open with `initialize`. */
    public const LATEST_LEGACY = '2025-11-25';

    /** @var list<string> */
    public const MODERN = ['2026-07-28'];

    /** @var list<string> */
    public const LEGACY = ['2025-11-25', '2025-06-18'];

    /** Revision assumed by the specification when a request carries no version header. */
    public const ASSUMED_WITHOUT_HEADER = '2025-03-26';

    private function __construct() {}

    /**
     * Every supported revision, most recent first.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return [...self::MODERN, ...self::LEGACY];
    }

    public static function isModern(string $version): bool
    {
        return in_array($version, self::MODERN, true);
    }

    public static function isLegacy(string $version): bool
    {
        return in_array($version, self::LEGACY, true);
    }

    public static function isSupported(string $version): bool
    {
        return self::isModern($version) || self::isLegacy($version);
    }
}
