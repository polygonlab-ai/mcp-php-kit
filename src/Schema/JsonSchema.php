<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Schema;

use PolygonLab\McpKit\Exception\InvalidToolDefinition;

/**
 * The JSON Schema subset supported by the kit (dialect 2020-12, the MCP default).
 *
 * Validation keywords: type, enum, const, properties, required, additionalProperties, items,
 * minItems, maxItems, minimum, maximum, exclusiveMinimum, exclusiveMaximum, minLength,
 * maxLength, pattern.
 * Annotations (accepted, not validated): $schema, title, description, default, examples,
 * format, deprecated, readOnly, writeOnly, $comment, x-mcp-header.
 *
 * Any other keyword ($ref, anyOf, oneOf...) is refused when the tool is registered: a keyword
 * the validator would silently ignore would let invalid arguments reach the tool.
 */
final class JsonSchema
{
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public const TYPES = ['null', 'boolean', 'object', 'array', 'number', 'string', 'integer'];

    private const ANNOTATIONS = ['$schema', 'title', 'description', 'default', 'examples', 'format', 'deprecated',
        'readOnly', 'writeOnly', '$comment', 'x-mcp-header'];

    private const MAX_DEPTH = 32;

    private function __construct() {}

    /**
     * Checks that a schema only uses supported keywords, with values of the right type.
     *
     * @param array<mixed> $schema
     *
     * @throws InvalidToolDefinition
     */
    public static function check(array $schema, string $path = '#', int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidToolDefinition('Schema nested too deeply at ' . $path . '.');
        }
        if ($schema !== [] && array_is_list($schema)) {
            throw new InvalidToolDefinition('A schema must be an object, not a list, at ' . $path . '.');
        }
        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;
            $at = $path . '/' . $keyword;
            match ($keyword) {
                'type' => self::checkType($value, $at),
                'enum' => is_array($value) && $value !== [] && array_is_list($value)
                    ? null : self::fail('"enum" must be a non-empty list', $at),
                'const' => null,
                'properties' => self::checkProperties($value, $at, $depth),
                'required' => is_array($value) && array_is_list($value) && self::allStrings($value)
                    ? null : self::fail('"required" must be a list of strings', $at),
                'additionalProperties' => is_bool($value) ? null : self::checkSubschema($value, $at, $depth),
                'items' => self::checkSubschema($value, $at, $depth),
                'minItems', 'maxItems', 'minLength', 'maxLength' => is_int($value) && $value >= 0
                    ? null : self::fail('"' . $keyword . '" must be a non-negative integer', $at),
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum' => is_int($value) || is_float($value)
                    ? null : self::fail('"' . $keyword . '" must be a number', $at),
                'pattern' => is_string($value) && self::compiles($value)
                    ? null : self::fail('"pattern" must be a valid regular expression', $at),
                '$schema' => $value === self::DIALECT
                    ? null : self::fail('unsupported JSON Schema dialect (only ' . self::DIALECT . ' is supported)', $at),
                'title', 'description', 'format', '$comment', 'x-mcp-header' => is_string($value)
                    ? null : self::fail('"' . $keyword . '" must be a string', $at),
                'deprecated', 'readOnly', 'writeOnly' => is_bool($value)
                    ? null : self::fail('"' . $keyword . '" must be a boolean', $at),
                'default', 'examples' => null,
                default => self::fail('unsupported keyword "' . $keyword . '"', $at),
            };
        }
    }

    /**
     * Schema ready for json_encode(): every schema becomes an object, even when empty.
     *
     * @param array<mixed> $schema a schema accepted by check()
     */
    public static function toJson(array $schema): \stdClass
    {
        $json = new \stdClass();
        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;
            if ($keyword === 'properties' && is_array($value)) {
                $properties = new \stdClass();
                foreach ($value as $name => $subschema) {
                    $properties->{(string) $name} = is_array($subschema) ? self::toJson($subschema) : $subschema;
                }
                $json->properties = $properties;
            } elseif (($keyword === 'items' || $keyword === 'additionalProperties') && is_array($value)) {
                $json->{$keyword} = self::toJson($value);
            } else {
                $json->{$keyword} = $value;
            }
        }

        return $json;
    }

    /**
     * PCRE form of a JSON Schema pattern (ECMA-262 syntax, Unicode, not anchored).
     */
    public static function regex(string $pattern): string
    {
        return "\x01" . $pattern . "\x01u";
    }

    /**
     * @return array<string, true> annotation keywords
     */
    public static function annotations(): array
    {
        return array_fill_keys(self::ANNOTATIONS, true);
    }

    private static function checkType(mixed $value, string $at): void
    {
        $types = is_array($value) ? $value : [$value];
        if ($types === [] || !array_is_list($types) || count(array_unique($types, SORT_REGULAR)) !== count($types)) {
            self::fail('"type" must be a type name or a non-empty list of distinct type names', $at);
        }
        foreach ($types as $type) {
            if (!in_array($type, self::TYPES, true)) {
                self::fail('unknown type ' . (is_string($type) ? '"' . $type . '"' : 'value'), $at);
            }
        }
    }

    private static function checkProperties(mixed $value, string $at, int $depth): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail('"properties" must be an object of schemas', $at);
        }
        foreach ($value as $name => $subschema) {
            self::checkSubschema($subschema, $at . '/' . $name, $depth);
        }
    }

    private static function checkSubschema(mixed $value, string $at, int $depth): void
    {
        if (!is_array($value)) {
            self::fail('a schema object is expected', $at);
        }
        self::check($value, $at, $depth + 1);
    }

    /**
     * @param array<mixed> $values
     */
    private static function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    private static function compiles(string $pattern): bool
    {
        if (str_contains($pattern, "\x01")) {
            return false;
        }

        return @preg_match(self::regex($pattern), '') !== false;
    }

    private static function fail(string $message, string $at): never
    {
        throw new InvalidToolDefinition('Invalid input schema: ' . $message . ' at ' . $at . '.');
    }
}
