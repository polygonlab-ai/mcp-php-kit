<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

/**
 * Opaque pagination cursor of `tools/list`: the position of the next page, Base64URL-encoded.
 *
 * @internal
 */
final class Cursor
{
    private const PREFIX = 'offset:';

    private function __construct() {}

    public static function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode(self::PREFIX . $offset), '+/', '-_'), '=');
    }

    /**
     * Position encoded in the cursor, or null when the cursor is invalid.
     */
    public static function decode(string $cursor): ?int
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $cursor) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match('/\A' . self::PREFIX . '(0|[1-9][0-9]{0,8})\z/', $decoded, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
