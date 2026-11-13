<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Http;

/**
 * Decoding of the `Mcp-Name` and `Mcp-Param-{Name}` header values (Streamable HTTP, 2026-07-28).
 *
 * A value is either plain visible ASCII, or the Base64 sentinel `=?base64?{value}?=` that
 * carries any UTF-8 string.
 */
final class HeaderValue
{
    private function __construct() {}

    /**
     * Decoded value, or null when the value contains invalid characters or invalid Base64.
     */
    public static function decode(string $value): ?string
    {
        if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=') && strlen($value) >= 11) {
            $encoded = substr($value, 9, -2);
            if (preg_match('~\A[A-Za-z0-9+/]*={0,2}\z~', $encoded) !== 1) {
                return null;
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
                return null;
            }

            return $decoded;
        }

        // Plain value: visible ASCII, space and horizontal tab only (RFC 9110).
        return preg_match('/\A[\x20-\x7E\t]*\z/', $value) === 1 ? $value : null;
    }
}
