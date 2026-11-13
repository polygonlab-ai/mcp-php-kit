<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Schema;

/**
 * Validates a decoded JSON value against a schema checked by JsonSchema::check().
 *
 * The value comes from json_decode() without the associative flag: JSON objects are
 * \stdClass instances and JSON arrays are PHP lists, so `{}` and `[]` stay distinct.
 */
final class Validator
{
    /** Errors reported at most, to keep messages short for the language model. */
    private const MAX_ERRORS = 10;

    private function __construct() {}

    /**
     * @param array<mixed> $schema
     *
     * @return list<string> error messages, empty when the value is valid
     */
    public static function validate(array $schema, mixed $value): array
    {
        $errors = [];
        self::walk($schema, $value, '', $errors);

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $errors
     */
    private static function walk(array $schema, mixed $value, string $path, array &$errors): void
    {
        $where = $path === '' ? '(root)' : $path;

        if (array_key_exists('type', $schema)) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $matches = false;
            foreach ($types as $type) {
                $matches = $matches || (is_string($type) && self::hasType($value, $type));
            }
            if (!$matches) {
                $errors[] = $where . ': must be of type ' . implode(' or ', array_map(static fn(mixed $t): string => is_string($t) ? $t : '?', $types));

                return;
            }
        }
        if (array_key_exists('const', $schema) && !self::equals($schema['const'], $value)) {
            $errors[] = $where . ': must be equal to ' . self::show($schema['const']);
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $found = false;
            foreach ($schema['enum'] as $allowed) {
                $found = $found || self::equals($allowed, $value);
            }
            if (!$found) {
                $errors[] = $where . ': must be one of ' . implode(', ', array_map(self::show(...), $schema['enum']));
            }
        }

        if (is_string($value)) {
            self::checkString($schema, $value, $where, $errors);
        } elseif (is_int($value) || is_float($value)) {
            self::checkNumber($schema, $value, $where, $errors);
        } elseif (is_array($value)) {
            self::checkArray($schema, $value, $path, $errors);
        } elseif ($value instanceof \stdClass) {
            self::checkObject($schema, $value, $path, $errors);
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $errors
     */
    private static function checkString(array $schema, string $value, string $where, array &$errors): void
    {
        $length = mb_strlen($value, 'UTF-8');
        if (is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
            $errors[] = $where . ': must contain at least ' . $schema['minLength'] . ' characters';
        }
        if (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
            $errors[] = $where . ': must contain at most ' . $schema['maxLength'] . ' characters';
        }
        if (is_string($schema['pattern'] ?? null) && preg_match(JsonSchema::regex($schema['pattern']), $value) !== 1) {
            $errors[] = $where . ': must match the pattern ' . $schema['pattern'];
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $errors
     */
    private static function checkNumber(array $schema, int|float $value, string $where, array &$errors): void
    {
        $rules = [
            'minimum' => [static fn(int|float $limit): bool => $value >= $limit, 'greater than or equal to'],
            'maximum' => [static fn(int|float $limit): bool => $value <= $limit, 'less than or equal to'],
            'exclusiveMinimum' => [static fn(int|float $limit): bool => $value > $limit, 'greater than'],
            'exclusiveMaximum' => [static fn(int|float $limit): bool => $value < $limit, 'less than'],
        ];
        foreach ($rules as $keyword => [$test, $label]) {
            $limit = $schema[$keyword] ?? null;
            if ((is_int($limit) || is_float($limit)) && !$test($limit)) {
                $errors[] = $where . ': must be ' . $label . ' ' . self::show($limit);
            }
        }
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $value
     * @param list<string> $errors
     */
    private static function checkArray(array $schema, array $value, string $path, array &$errors): void
    {
        $where = $path === '' ? '(root)' : $path;
        $count = count($value);
        if (is_int($schema['minItems'] ?? null) && $count < $schema['minItems']) {
            $errors[] = $where . ': must contain at least ' . $schema['minItems'] . ' items';
        }
        if (is_int($schema['maxItems'] ?? null) && $count > $schema['maxItems']) {
            $errors[] = $where . ': must contain at most ' . $schema['maxItems'] . ' items';
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                self::walk($schema['items'], $item, $path . '/' . $index, $errors);
            }
        }
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $errors
     */
    private static function checkObject(array $schema, \stdClass $value, string $path, array &$errors): void
    {
        $where = $path === '' ? '(root)' : $path;
        $members = get_object_vars($value);
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name) && !array_key_exists($name, $members)) {
                    $errors[] = $where . ': missing required property "' . $name . '"';
                }
            }
        }
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        foreach ($members as $name => $member) {
            $name = (string) $name;
            $memberPath = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], $name);
            if (array_key_exists($name, $properties)) {
                if (is_array($properties[$name])) {
                    self::walk($properties[$name], $member, $memberPath, $errors);
                }
                continue;
            }
            $additional = $schema['additionalProperties'] ?? true;
            if ($additional === false) {
                $errors[] = $where . ': unexpected property "' . $name . '"';
            } elseif (is_array($additional)) {
                self::walk($additional, $member, $memberPath, $errors);
            }
        }
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'array' => is_array($value),
            'object' => $value instanceof \stdClass,
            default => false,
        };
    }

    /**
     * JSON equality: numbers compared by value (1 equals 1.0), objects regardless of member order.
     */
    private static function equals(mixed $expected, mixed $actual): bool
    {
        return self::normalise($expected) === self::normalise($actual);
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return ['n', (float) $value];
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            ksort($value);

            return ['o', array_map(self::normalise(...), $value)];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['a', array_map(self::normalise(...), $value)];
            }
            ksort($value);

            return ['o', array_map(self::normalise(...), $value)];
        }

        return $value;
    }

    private static function show(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '?' : $json;
    }
}
