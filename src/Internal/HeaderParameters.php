<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Exception\InvalidToolDefinition;

/**
 * Tool parameters mirrored into `Mcp-Param-{Name}` HTTP headers (`x-mcp-header`, 2026-07-28).
 *
 * Checks the constraints of the specification when the tool is registered: token syntax,
 * case-insensitive uniqueness, primitive type (string, integer, boolean), and a property
 * reachable from the root through `properties` keys only.
 *
 * @internal
 */
final class HeaderParameters
{
    private function __construct() {}

    /**
     * @param array<mixed> $schema input schema of the tool
     *
     * @return list<array{header: string, path: list<string>, type: string}>
     */
    public static function collect(array $schema, string $tool): array
    {
        $found = [];
        self::walk($schema, [], $found, $tool);

        if (count($found) !== self::countAll($schema)) {
            throw new InvalidToolDefinition('Tool "' . $tool . '": "x-mcp-header" is only allowed on properties reachable through "properties" keys.');
        }
        $seen = [];
        foreach ($found as $parameter) {
            $key = strtolower($parameter['header']);
            if (isset($seen[$key])) {
                throw new InvalidToolDefinition('Tool "' . $tool . '": duplicate "x-mcp-header" value "' . $parameter['header'] . '".');
            }
            $seen[$key] = true;
        }

        return $found;
    }

    /**
     * @param array<mixed>                                                 $schema
     * @param list<string>                                                 $path
     * @param list<array{header: string, path: list<string>, type: string}> $found
     */
    private static function walk(array $schema, array $path, array &$found, string $tool): void
    {
        if (array_key_exists('x-mcp-header', $schema)) {
            $header = $schema['x-mcp-header'];
            if (!is_string($header) || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $header) !== 1) {
                throw new InvalidToolDefinition('Tool "' . $tool . '": "x-mcp-header" must be a non-empty HTTP token.');
            }
            $types = is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
            $primitive = array_values(array_filter($types, static fn(mixed $type): bool => $type !== 'null'));
            if (count($primitive) !== 1 || !in_array($primitive[0], ['string', 'integer', 'boolean'], true)) {
                throw new InvalidToolDefinition('Tool "' . $tool . '": "x-mcp-header" is only allowed on string, integer or boolean properties.');
            }
            $found[] = ['header' => $header, 'path' => $path, 'type' => (string) $primitive[0]];
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    self::walk($property, [...$path, (string) $name], $found, $tool);
                }
            }
        }
    }

    /**
     * @param array<mixed> $schema
     */
    private static function countAll(array $schema): int
    {
        $count = 0;
        foreach ($schema as $key => $value) {
            if ($key === 'x-mcp-header') {
                $count++;
            } elseif (is_array($value)) {
                $count += self::countAll($value);
            }
        }

        return $count;
    }
}
