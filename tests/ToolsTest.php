<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Attribute\Param;
use PolygonLab\McpKit\Attribute\Tool;
use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Exception\InvalidToolDefinition;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\Fixtures\SampleTools;
use PolygonLab\McpKit\Tests\Support\Rpc;
use PolygonLab\McpKit\Tool\ToolResult;

/**
 * Tool declaration (attributes and callables), generated schemas, validation, results.
 */
final class ToolsTest extends TestCase
{
    private function server(): Server
    {
        return Server::builder('tools', '1.0.0')->withToolsFrom(new SampleTools())->build();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(string $tool, array $arguments = [], bool $modern = true): mixed
    {
        $params = ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments];
        $response = $this->server()->handle($modern ? Rpc::modern('tools/call', $params) : Rpc::legacy('tools/call', $params));
        self::assertSame(200, $response->status, $response->body);

        return Rpc::at($response, 'result');
    }

    public function testOnlyMarkedPublicMethodsAreTools(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list'));
        $tools = Rpc::at($response, 'result.tools');
        self::assertIsArray($tools);

        self::assertSame(
            ['describe', 'fail', 'crash', 'raw_result', 'nothing', 'numbers', 'region_query'],
            array_map(static fn(mixed $tool): mixed => is_array($tool) ? $tool['name'] : null, $tools),
        );
    }

    public function testSchemaIsGeneratedFromTheParameterTypes(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list'));

        self::assertSame('Describe', Rpc::at($response, 'result.tools.0.title'));
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'A name', 'minLength' => 2, 'maxLength' => 20, 'pattern' => '^[a-z]+$'],
                'count' => ['type' => 'integer'],
                'ratio' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'note' => ['type' => ['string', 'null'], 'default' => null],
                'priority' => ['type' => 'integer', 'enum' => [1, 2], 'default' => 1],
            ],
            'required' => ['name', 'count', 'ratio', 'active', 'unit'],
            'additionalProperties' => false,
        ], Rpc::at($response, 'result.tools.0.inputSchema'));
    }

    public function testToolWithoutParametersHasAnEmptyObjectSchema(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list'));

        self::assertStringContainsString('"name":"fail","description":"Always fails with a business error.","inputSchema":{"type":"object","properties":{},"additionalProperties":false}', $response->body);
    }

    public function testArgumentsAreConvertedToThePhpTypes(): void
    {
        $result = $this->call('describe', [
            'name' => 'alice', 'count' => 3.0, 'ratio' => 2, 'active' => true, 'unit' => 'fahrenheit',
            'tags' => ['a', 'b'], 'priority' => 2,
        ]);

        self::assertIsArray($result);
        self::assertFalse($result['isError']);
        self::assertSame([
            // JSON does not keep the fraction of 2.0: the float 2.0 returned by the tool reads back as 2.
            'name' => 'alice', 'count' => 3, 'ratio' => 2, 'active' => true, 'unit' => 'fahrenheit',
            'tags' => ['a', 'b'], 'note' => null, 'priority' => 2,
        ], $result['structuredContent']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        $valid = ['name' => 'alice', 'count' => 1, 'ratio' => 0.5, 'active' => false, 'unit' => 'celsius'];

        yield 'missing required' => [array_diff_key($valid, ['count' => true]), '(root): missing required property "count"'];
        yield 'wrong type' => [['count' => 'three'] + $valid, '/count: must be of type integer'];
        yield 'fractional integer' => [['count' => 1.5] + $valid, '/count: must be of type integer'];
        yield 'not in enum' => [['unit' => 'kelvin'] + $valid, '/unit: must be one of "celsius", "fahrenheit"'];
        yield 'too short' => [['name' => 'a'] + $valid, '/name: must contain at least 2 characters'];
        yield 'pattern' => [['name' => 'Alice'] + $valid, '/name: must match the pattern ^[a-z]+$'];
        yield 'unexpected property' => [['extra' => 1] + $valid, '(root): unexpected property "extra"'];
        yield 'item type' => [['tags' => ['ok', 2]] + $valid, '/tags/1: must be of type string'];
        yield 'integer enum' => [['priority' => 3] + $valid, '/priority: must be one of 1, 2'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreReportedToTheModel(array $arguments, string $expected): void
    {
        $result = $this->call('describe', $arguments);

        self::assertIsArray($result);
        self::assertTrue($result['isError']);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);
        self::assertIsString($result['content'][0]['text']);
        self::assertStringStartsWith('Invalid arguments: ', $result['content'][0]['text']);
        self::assertStringContainsString($expected, $result['content'][0]['text']);
    }

    public function testToolErrorBecomesAnErrorResult(): void
    {
        self::assertSame([
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'Nothing to do here.']],
            'isError' => true,
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'tools', 'version' => '1.0.0']],
        ], $this->call('fail'));
    }

    public function testUnexpectedExceptionIsAnInternalError(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 'crash']));

        self::assertSame(ErrorCode::INTERNAL_ERROR, Rpc::at($response, 'error.code'));
        self::assertStringNotContainsString('hunter', $response->body);
    }

    public function testReturnValues(): void
    {
        $raw = $this->call('raw_result', [], false);
        $nothing = $this->call('nothing', [], false);
        $numbers = $this->call('numbers');

        self::assertSame(['content' => [['type' => 'text', 'text' => 'first'], ['type' => 'text', 'text' => 'second']], 'isError' => false], $raw);
        self::assertSame(['content' => [], 'isError' => false], $nothing);
        self::assertIsArray($numbers);
        self::assertSame([1, 2, 3], $numbers['structuredContent']);
        self::assertSame([['type' => 'text', 'text' => '[1,2,3]']], $numbers['content']);
    }

    public function testCallableToolWithExplicitSchema(): void
    {
        $server = Server::builder('s', '1')
            ->withTool(
                'greet',
                'Greet someone.',
                fn(string $name, string $greeting = 'Hello'): string => $greeting . ', ' . $name . '!',
                ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'minLength' => 1]], 'required' => ['name']],
                'Greeter',
            )
            ->build();

        $list = $server->handle(Rpc::legacy('tools/list'));
        $call = $server->handle(Rpc::legacy('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada', 'other' => true]]));

        self::assertSame(['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'minLength' => 1]], 'required' => ['name']], Rpc::at($list, 'result.tools.0.inputSchema'));
        self::assertSame('Greeter', Rpc::at($list, 'result.tools.0.title'));
        self::assertSame('Hello, Ada!', Rpc::at($call, 'result.content.0.text'));
    }

    public function testExplicitSchemaThatMissesAParameterReportsIt(): void
    {
        $server = Server::builder('s', '1')
            ->withTool('loose', 'Loose schema.', fn(string $name): string => $name, ['type' => 'object'])
            ->build();
        $response = $server->handle(Rpc::legacy('tools/call', ['name' => 'loose']));

        self::assertSame(true, Rpc::at($response, 'result.isError'));
        self::assertSame('Missing argument "name".', Rpc::at($response, 'result.content.0.text'));
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        $builder = static fn(): \PolygonLab\McpKit\ServerBuilder => Server::builder('s', '1');

        yield 'name with a space' => [static fn(): mixed => $builder()->withTool('my tool', 'D.', fn(): string => ''), 'Invalid tool name'];
        yield 'name too long' => [static fn(): mixed => $builder()->withTool(str_repeat('a', 129), 'D.', fn(): string => ''), 'Invalid tool name'];
        yield 'empty description' => [static fn(): mixed => $builder()->withTool('t', ' ', fn(): string => ''), 'needs a description'];
        yield 'union type' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(int|string $v): string => ''), 'Unsupported type'];
        yield 'object type' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(\DateTimeImmutable $d): string => ''), 'Unsupported type'];
        yield 'untyped' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn($v): string => ''), 'Unsupported type'];
        yield 'variadic' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(string ...$v): string => ''), 'variadic'];
        yield 'reference' => [static fn(): mixed => $builder()->withTool('t', 'D.', function (string &$v): string {
            return '';
        }), 'variadic or passed by reference'];
        yield '$ref keyword' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/x']]]), 'unsupported keyword "$ref"'];
        yield 'anyOf keyword' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'anyOf' => []]), 'unsupported keyword "anyOf"'];
        yield 'root not an object' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'string']), 'must have "type": "object"'];
        yield 'invalid pattern' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'pattern' => '([a-z']]]), '"pattern" must be a valid regular expression'];
        yield 'other dialect' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object']), 'unsupported JSON Schema dialect'];
        yield 'unknown type' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'properties' => ['a' => ['type' => 'date']]]), 'unknown type "date"'];
        yield 'properties as a list' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'properties' => [['type' => 'string']]]), '"properties" must be an object'];
        yield 'header on a number' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(#[Param(header: 'Ratio')] float $ratio): string => ''), 'string, integer or boolean'];
        yield 'header not a token' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(#[Param(header: 'Bad Name')] string $a): string => ''), 'HTTP token'];
        yield 'duplicate header' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(#[Param(header: 'Zone')] string $a, #[Param(header: 'zone')] string $b): string => ''), 'duplicate'];
        yield 'header inside items' => [static fn(): mixed => $builder()->withTool('t', 'D.', fn(): string => '', ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'Item']]]]), 'reachable'];
        yield 'object without tools' => [static fn(): mixed => $builder()->withToolsFrom(new \stdClass()), 'No public method'];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRefusedAtRegistration(callable $build, string $expected): void
    {
        $this->expectException(InvalidToolDefinition::class);
        $this->expectExceptionMessage($expected);
        $build();
    }

    public function testDuplicateToolNameIsRefused(): void
    {
        $this->expectException(InvalidConfiguration::class);
        Server::builder('s', '1')->withTool('t', 'D.', fn(): string => '')->withTool('t', 'D.', fn(): string => '');
    }

    public function testAttributeWithExplicitSchema(): void
    {
        $tools = new class {
            #[Tool(name: 'search', description: 'Search.', inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']])]
            public function search(string $query): ToolResult
            {
                return ToolResult::text('results for ' . $query);
            }
        };
        $server = Server::builder('s', '1')->withToolsFrom($tools)->build();
        $response = $server->handle(Rpc::modern('tools/call', ['name' => 'search', 'arguments' => ['query' => 'php']]));

        self::assertSame('results for php', Rpc::at($response, 'result.content.0.text'));
    }

    public function testMirroredHeaderParametersAreDeclaredAndChecked(): void
    {
        $list = $this->server()->handle(Rpc::modern('tools/list'));
        self::assertSame('Region', Rpc::at($list, 'result.tools.6.inputSchema.properties.region.x-mcp-header'));

        $ok = $this->server()->handle(Rpc::modern(
            'tools/call',
            ['name' => 'region_query', 'arguments' => ['region' => 'eu-west', 'limit' => 5, 'dryRun' => true]],
            1,
            ['Mcp-Param-Region' => 'eu-west', 'Mcp-Param-Limit' => '5.0', 'Mcp-Param-Dry-Run' => 'true'],
        ));
        self::assertSame('eu-west:5:dry', Rpc::at($ok, 'result.content.0.text'));

        $encoded = $this->server()->handle(Rpc::modern(
            'tools/call',
            ['name' => 'region_query', 'arguments' => ['region' => 'Île-de-France']],
            1,
            ['Mcp-Param-Region' => '=?base64?' . base64_encode('Île-de-France') . '?='],
        ));
        self::assertSame('Île-de-France:none:real', Rpc::at($encoded, 'result.content.0.text'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>, string}>
     */
    public static function headerParameterMismatches(): iterable
    {
        yield 'missing header' => [['region' => 'eu'], [], 'missing Mcp-Param-Region header'];
        yield 'different value' => [['region' => 'eu'], ['Mcp-Param-Region' => 'us'], 'does not match'];
        yield 'different integer' => [['region' => 'eu', 'limit' => 5], ['Mcp-Param-Region' => 'eu', 'Mcp-Param-Limit' => '6'], 'Mcp-Param-Limit'];
        yield 'boolean in capitals' => [['region' => 'eu', 'dryRun' => true], ['Mcp-Param-Region' => 'eu', 'Mcp-Param-Dry-Run' => 'TRUE'], 'Mcp-Param-Dry-Run'];
        yield 'invalid characters' => [['region' => 'eu'], ['Mcp-Param-Region' => "e\u{e9}u"], 'invalid characters'];
    }

    /**
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $headers
     */
    #[DataProvider('headerParameterMismatches')]
    public function testMirroredHeaderParameterMismatchIsRefused(array $arguments, array $headers, string $expected): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 'region_query', 'arguments' => $arguments], 1, $headers));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::HEADER_MISMATCH, Rpc::at($response, 'error.code'));
        self::assertStringContainsString($expected, (string) json_encode(Rpc::at($response, 'error.message')));
    }

    public function testMirroredHeadersAreNotRequiredInTheLegacyEra(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/call', ['name' => 'region_query', 'arguments' => ['region' => 'eu']]));

        self::assertSame('eu:none:real', Rpc::at($response, 'result.content.0.text'));
    }
}
