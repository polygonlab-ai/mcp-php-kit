<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Schema\JsonSchema;
use PolygonLab\McpKit\Schema\Validator;

/**
 * The supported JSON Schema subset, on decoded JSON values.
 */
final class SchemaTest extends TestCase
{
    /**
     * @return iterable<string, array{array<mixed>, string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'integer accepts 2.0' => [['type' => 'integer'], '2.0', []];
        yield 'number accepts an integer' => [['type' => 'number'], '7', []];
        yield 'string refuses a number' => [['type' => 'string'], '7', ['(root): must be of type string']];
        yield 'union of types' => [['type' => ['string', 'null']], 'null', []];
        yield 'object refuses a list' => [['type' => 'object'], '[]', ['(root): must be of type object']];
        yield 'array refuses an object' => [['type' => 'array'], '{}', ['(root): must be of type array']];
        yield 'empty object is an object' => [['type' => 'object'], '{}', []];
        yield 'boolean refuses 0' => [['type' => 'boolean'], '0', ['(root): must be of type boolean']];
        yield 'const' => [['const' => 'fixed'], '"other"', ['(root): must be equal to "fixed"']];
        yield 'enum compares numbers by value' => [['enum' => [1, 2]], '2.0', []];
        yield 'enum with objects' => [['enum' => [['a' => 1, 'b' => 2]]], '{"b":2,"a":1}', []];
        yield 'minimum' => [['minimum' => 3], '2', ['(root): must be greater than or equal to 3']];
        yield 'maximum' => [['maximum' => 3], '4', ['(root): must be less than or equal to 3']];
        yield 'exclusive minimum' => [['exclusiveMinimum' => 3], '3', ['(root): must be greater than 3']];
        yield 'exclusive maximum' => [['exclusiveMaximum' => 3.5], '3.5', ['(root): must be less than 3.5']];
        yield 'length counts characters' => [['maxLength' => 3], '"été"', []];
        yield 'min length' => [['minLength' => 4], '"été"', ['(root): must contain at least 4 characters']];
        yield 'pattern is not anchored' => [['pattern' => 'b+'], '"abbc"', []];
        yield 'unicode pattern' => [['pattern' => '^\p{Lu}'], '"École"', []];
        yield 'pattern with a slash' => [['pattern' => '^a/b$'], '"a/b"', []];
        yield 'min items' => [['minItems' => 2], '[1]', ['(root): must contain at least 2 items']];
        yield 'max items' => [['maxItems' => 1], '[1,2]', ['(root): must contain at most 1 items']];
        yield 'nested path' => [
            ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a/b' => ['type' => 'integer']]]]]],
            '{"list":[{"a/b":1},{"a/b":"x"}]}',
            ['/list/1/a~1b: must be of type integer'],
        ];
        yield 'additional properties schema' => [['additionalProperties' => ['type' => 'string']], '{"a":"x","b":2}', ['/b: must be of type string']];
        yield 'additional properties allowed by default' => [['properties' => ['a' => ['type' => 'string']]], '{"a":"x","b":2}', []];
        yield 'annotations are ignored' => [['type' => 'string', 'format' => 'email', 'description' => 'd'], '"not an email"', []];
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testValidation(array $schema, string $json, array $expected): void
    {
        JsonSchema::check($schema);

        self::assertSame($expected, Validator::validate($schema, json_decode($json, false, 64, JSON_THROW_ON_ERROR)));
    }

    public function testErrorsAreCapped(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        self::assertCount(10, Validator::validate($schema, range(1, 50)));
    }

    public function testEmptySchemasAreEncodedAsObjects(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => [], 'b' => ['type' => 'array', 'items' => []]], 'additionalProperties' => []];

        self::assertSame(
            '{"type":"object","properties":{"a":{},"b":{"type":"array","items":{}}},"additionalProperties":{}}',
            json_encode(JsonSchema::toJson($schema)),
        );
    }

    public function testDeeplyNestedSchemaIsRefused(): void
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < 40; $i++) {
            $schema = ['type' => 'object', 'properties' => ['a' => $schema]];
        }

        $this->expectException(\PolygonLab\McpKit\Exception\InvalidToolDefinition::class);
        JsonSchema::check($schema);
    }
}
