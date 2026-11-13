<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

/**
 * JSON encoding and decoding with the options used everywhere in the kit.
 *
 * @internal
 */
final class Json
{
    private const ENCODE = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const MAX_DEPTH = 64;

    private function __construct() {}

    /**
     * Objects are decoded as \stdClass, so that `{}` and `[]` stay distinct.
     *
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /**
     * @throws \JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE);
    }
}
