<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\ProtocolVersion;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\Rpc;

/**
 * Protocol revision 2026-07-28: per-request `_meta`, server/discover, no handshake.
 */
final class ModernProtocolTest extends TestCase
{
    private function server(int $tools = 2, int $pageSize = 50): Server
    {
        $builder = Server::builder('test-server', '1.2.3')
            ->withTitle('Test server')
            ->withInstructions('Use the tools wisely.')
            ->withPageSize($pageSize);
        for ($i = 1; $i <= $tools; $i++) {
            $builder = $builder->withTool('tool_' . $i, 'Tool number ' . $i . '.', fn(string $text): string => $text . $i);
        }

        return $builder->build();
    }

    public function testDiscoverDescribesTheServer(): void
    {
        $response = $this->server()->handle(Rpc::modern('server/discover'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'resultType' => 'complete',
                'supportedVersions' => ['2026-07-28', '2025-11-25', '2025-06-18'],
                'capabilities' => ['tools' => []],
                'instructions' => 'Use the tools wisely.',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'test-server', 'version' => '1.2.3', 'title' => 'Test server']],
            ],
        ], Rpc::body($response));
        // Empty objects stay JSON objects.
        self::assertStringContainsString('"capabilities":{"tools":{}}', $response->body);
    }

    public function testListToolsReturnsDefinitionsAndCachingHints(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/list', [], 'list-1'));

        self::assertSame(200, $response->status);
        self::assertSame('list-1', Rpc::at($response, 'id'));
        self::assertSame('complete', Rpc::at($response, 'result.resultType'));
        self::assertSame('tool_1', Rpc::at($response, 'result.tools.0.name'));
        self::assertSame('Tool number 1.', Rpc::at($response, 'result.tools.0.description'));
        self::assertSame([
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => false,
        ], Rpc::at($response, 'result.tools.0.inputSchema'));
        self::assertSame(60000, Rpc::at($response, 'result.ttlMs'));
        self::assertSame('public', Rpc::at($response, 'result.cacheScope'));
        self::assertArrayNotHasKey('nextCursor', (array) Rpc::at($response, 'result'));
    }

    public function testListToolsPaginatesWithAnOpaqueCursor(): void
    {
        $server = $this->server(5, 2);
        $names = [];
        $cursor = null;
        $pages = 0;
        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $response = $server->handle(Rpc::modern('tools/list', $params));
            $result = Rpc::at($response, 'result');
            self::assertIsArray($result);
            self::assertIsArray($result['tools']);
            foreach ($result['tools'] as $tool) {
                self::assertIsArray($tool);
                $names[] = $tool['name'];
            }
            $cursor = $result['nextCursor'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertSame(3, $pages);
        self::assertSame(['tool_1', 'tool_2', 'tool_3', 'tool_4', 'tool_5'], $names);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'not base64' => ['!!!'];
        yield 'foreign value' => [base64_encode('page=2')];
        yield 'beyond the end' => [rtrim(strtr(base64_encode('offset:99'), '+/', '-_'), '=')];
        yield 'not a string' => [12];
    }

    #[DataProvider('invalidCursors')]
    public function testInvalidCursorIsRejected(mixed $cursor): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/list', ['cursor' => $cursor]));

        self::assertSame(200, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testCallToolReturnsACompleteResult(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 'tool_1', 'arguments' => ['text' => 'hello']]));

        self::assertSame(200, $response->status);
        self::assertSame([
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'hello1']],
            'isError' => false,
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'test-server', 'version' => '1.2.3', 'title' => 'Test server']],
        ], Rpc::at($response, 'result'));
    }

    public function testUnknownToolIsAnInvalidParamsError(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 'missing', 'arguments' => new \stdClass()]));

        self::assertSame(200, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
        self::assertSame('Invalid params: Unknown tool: missing', Rpc::at($response, 'error.message'));
    }

    public function testArgumentsMustBeAnObject(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 'tool_1', 'arguments' => ['a', 'b']]));

        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testToolNameMustBeAString(): void
    {
        $response = $this->server()->handle(Rpc::modern('tools/call', ['name' => 42]));

        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodsUnknownToTheModernEra(): iterable
    {
        yield 'ping, removed in 2026-07-28' => ['ping'];
        yield 'initialize, removed in 2026-07-28' => ['initialize'];
        yield 'resources/list, not implemented' => ['resources/list'];
        yield 'invented method' => ['does/not/exist'];
    }

    #[DataProvider('methodsUnknownToTheModernEra')]
    public function testUnknownMethodIsMethodNotFoundWithHttp404(string $method): void
    {
        $response = $this->server()->handle(Rpc::modern($method));

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::METHOD_NOT_FOUND, Rpc::at($response, 'error.code'));
        self::assertSame(1, Rpc::at($response, 'id'));
    }

    public function testUnsupportedVersionListsTheSupportedOnes(): void
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1900-01-01',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ]);
        $response = $this->server()->handle(Rpc::post($body, ['MCP-Protocol-Version' => '1900-01-01', 'Mcp-Method' => 'tools/list']));

        self::assertSame(400, $response->status);
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => [
                'code' => ErrorCode::UNSUPPORTED_PROTOCOL_VERSION,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => ProtocolVersion::supported(), 'requested' => '1900-01-01'],
            ],
        ], Rpc::body($response));
    }

    public function testLegacyVersionInMetaIsUnsupported(): void
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2025-11-25',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ]);
        $response = $this->server()->handle(Rpc::post($body, ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/list']));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, Rpc::at($response, 'error.code'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function headerMismatches(): iterable
    {
        yield 'version header missing' => [['MCP-Protocol-Version' => ''], 'missing MCP-Protocol-Version header'];
        yield 'version header different' => [['MCP-Protocol-Version' => '2025-11-25'], 'MCP-Protocol-Version header value does not match'];
        yield 'method header missing' => [['Mcp-Method' => ''], 'missing Mcp-Method header'];
        yield 'method header different' => [['Mcp-Method' => 'tools/list'], 'Mcp-Method header value does not match'];
        yield 'name header missing' => [['Mcp-Name' => ''], 'missing Mcp-Name header'];
        yield 'name header different' => [['Mcp-Name' => 'tool_2'], 'Mcp-Name header value does not match'];
        yield 'name header with a control character' => [['Mcp-Name' => "tool\x01"], 'Mcp-Name header contains invalid characters'];
        yield 'name header with invalid base64' => [['Mcp-Name' => '=?base64?***?='], 'Mcp-Name header contains invalid characters'];
    }

    /**
     * @param array<string, string> $headers an empty value removes the header
     */
    #[DataProvider('headerMismatches')]
    public function testHeadersMustMirrorTheBody(array $headers, string $expected): void
    {
        $request = Rpc::modern('tools/call', ['name' => 'tool_1', 'arguments' => ['text' => 'x']]);
        $merged = array_filter(array_merge($request->headers, array_change_key_case($headers)), static fn(string $v): bool => $v !== '');
        $response = $this->server()->handle(new \PolygonLab\McpKit\Http\HttpRequest('POST', $merged, $request->body));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::HEADER_MISMATCH, Rpc::at($response, 'error.code'));
        self::assertIsString(Rpc::at($response, 'error.message'));
        self::assertStringContainsString($expected, (string) Rpc::at($response, 'error.message'));
    }

    public function testBase64EncodedNameHeaderIsDecoded(): void
    {
        $response = $this->server()->handle(Rpc::modern(
            'tools/call',
            ['name' => 'tool_1', 'arguments' => ['text' => 'x']],
            1,
            ['Mcp-Name' => '=?base64?' . base64_encode('tool_1') . '?='],
        ));

        self::assertSame(200, $response->status);
        self::assertSame('x1', Rpc::at($response, 'result.content.0.text'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedMeta(): iterable
    {
        yield 'capabilities missing' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
        yield 'capabilities not an object' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => 'all']];
        yield 'client info not an object' => [['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass(), 'io.modelcontextprotocol/clientInfo' => 'x']];
        yield 'version not a string' => [['io.modelcontextprotocol/protocolVersion' => 20260728, 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()]];
    }

    /**
     * @param array<string, mixed> $meta
     */
    #[DataProvider('malformedMeta')]
    public function testMalformedMetaIsInvalidParamsWithHttp400(array $meta): void
    {
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]]);
        $response = $this->server()->handle(Rpc::post($body, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testModernVersionHeaderWithoutMetaIsInvalidParams(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list', [], 1, '2026-07-28'));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testCacheScopeIsPrivateWhenAuthenticationIsEnabled(): void
    {
        $server = Server::builder('s', '1')
            ->withTool('t', 'T.', fn(): string => 'ok')
            ->withAuthenticator(new \PolygonLab\McpKit\Auth\BearerTokenAuthenticator(['a-long-enough-test-token']))
            ->withCacheHints(5000)
            ->build();
        $response = $server->handle(Rpc::modern('tools/list', [], 1, ['Authorization' => 'Bearer a-long-enough-test-token']));

        self::assertSame('private', Rpc::at($response, 'result.cacheScope'));
        self::assertSame(5000, Rpc::at($response, 'result.ttlMs'));
    }
}
