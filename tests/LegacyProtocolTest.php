<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\Rpc;

/**
 * Handshake-based revisions (2025-11-25, 2025-06-18): initialize, notifications/initialized, ping.
 */
final class LegacyProtocolTest extends TestCase
{
    private function server(): Server
    {
        return Server::builder('legacy-server', '2.0.0')
            ->withInstructions('Be nice.')
            ->withTool('echo', 'Echo.', fn(string $text): string => $text)
            ->withTool('pair', 'Returns a list.', fn(): array => [1, 2])
            ->withTool('point', 'Returns an object.', fn(): array => ['x' => 1, 'y' => 2])
            ->build();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function negotiations(): iterable
    {
        yield '2025-11-25 is kept' => ['2025-11-25', '2025-11-25'];
        yield '2025-06-18 is kept' => ['2025-06-18', '2025-06-18'];
        yield '2025-03-26 is answered with the latest legacy revision' => ['2025-03-26', '2025-11-25'];
        yield '2026-07-28 has no handshake: latest legacy revision' => ['2026-07-28', '2025-11-25'];
        yield 'unknown version: latest legacy revision' => ['1999-01-01', '2025-11-25'];
    }

    #[DataProvider('negotiations')]
    public function testInitializeNegotiatesTheVersion(string $requested, string $expected): void
    {
        $response = $this->server()->handle(Rpc::legacy('initialize', [
            'protocolVersion' => $requested,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'legacy-client', 'version' => '0.1.0'],
        ], 0, null));

        self::assertSame(200, $response->status);
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 0,
            'result' => [
                'protocolVersion' => $expected,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'legacy-server', 'version' => '2.0.0'],
                'instructions' => 'Be nice.',
            ],
        ], Rpc::body($response));
        self::assertNull($response->header('Mcp-Session-Id'), 'no protocol session is minted');
    }

    public function testInitializeRequiresAProtocolVersion(): void
    {
        $response = $this->server()->handle(Rpc::legacy('initialize', ['capabilities' => new \stdClass()], 1, null));

        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testInitializeRequiresObjectCapabilities(): void
    {
        $response = $this->server()->handle(Rpc::legacy('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => 'all'], 1, null));

        self::assertSame(ErrorCode::INVALID_PARAMS, Rpc::at($response, 'error.code'));
    }

    public function testInitializedNotificationIsAcceptedWithoutBody(): void
    {
        $response = $this->server()->handle(Rpc::legacy('notifications/initialized', [], null));

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    public function testPingReturnsAnEmptyObject(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 'ping-1'));

        self::assertSame(200, $response->status);
        self::assertSame('{"jsonrpc":"2.0","id":"ping-1","result":{}}', $response->body);
    }

    public function testListToolsHasNoModernFields(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list', [], 1, '2025-06-18'));
        $result = Rpc::at($response, 'result');

        self::assertIsArray($result);
        self::assertSame(['tools'], array_keys($result));
        self::assertSame('echo', Rpc::at($response, 'result.tools.0.name'));
    }

    public function testCallToolHasNoModernFields(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]));

        self::assertSame(['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false], Rpc::at($response, 'result'));
    }

    public function testStructuredContentMustBeAnObjectInLegacyRevisions(): void
    {
        $list = $this->server()->handle(Rpc::legacy('tools/call', ['name' => 'pair']));
        $object = $this->server()->handle(Rpc::legacy('tools/call', ['name' => 'point']));

        self::assertSame(['content' => [['type' => 'text', 'text' => '[1,2]']], 'isError' => false], Rpc::at($list, 'result'));
        self::assertSame(['x' => 1, 'y' => 2], Rpc::at($object, 'result.structuredContent'));
    }

    public function testModernMethodIsUnknownInTheLegacyEra(): void
    {
        $response = $this->server()->handle(Rpc::legacy('server/discover'));

        self::assertSame(200, $response->status);
        self::assertSame(ErrorCode::METHOD_NOT_FOUND, Rpc::at($response, 'error.code'));
    }

    public function testRequestWithoutVersionHeaderIsUnsupported(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list', [], 1, null));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, Rpc::at($response, 'error.code'));
        self::assertSame('2025-03-26', Rpc::at($response, 'error.data.requested'));
    }

    public function testUnknownVersionHeaderIsUnsupported(): void
    {
        $response = $this->server()->handle(Rpc::legacy('tools/list', [], 1, '2024-11-05'));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, Rpc::at($response, 'error.code'));
        self::assertSame('2024-11-05', Rpc::at($response, 'error.data.requested'));
    }

    public function testSessionHeadersAreIgnored(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Mcp-Session-Id' => 'abc', 'Last-Event-ID' => '3']));

        self::assertSame(200, $response->status);
        self::assertNull($response->header('Mcp-Session-Id'));
    }
}
