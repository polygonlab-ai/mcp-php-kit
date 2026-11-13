<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\MemoryLogger;
use PolygonLab\McpKit\Tests\Support\Rpc;

/**
 * JSON-RPC 2.0 envelope: parse errors, invalid requests, notifications, responses, batches.
 */
final class JsonRpcTest extends TestCase
{
    private function server(?MemoryLogger $logger = null): Server
    {
        $builder = Server::builder('rpc', '1.0.0')->withTool('echo', 'Echo.', fn(string $text): string => $text);

        return ($logger === null ? $builder : $builder->withLogger($logger))->build();
    }

    public function testParseErrorHasNoId(): void
    {
        $response = $this->server()->handle(Rpc::post('{"jsonrpc": "2.0", "id": 1, '));

        self::assertSame(400, $response->status);
        self::assertSame(['jsonrpc' => '2.0', 'error' => ['code' => ErrorCode::PARSE_ERROR, 'message' => 'Parse error: the body is not valid JSON']], Rpc::body($response));
    }

    public function testBatchIsRefused(): void
    {
        $batch = '[' . Rpc::message('ping', [], 1) . ',' . Rpc::message('ping', [], 2) . ']';
        $response = $this->server()->handle(Rpc::post($batch, ['MCP-Protocol-Version' => '2025-11-25']));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_REQUEST, Rpc::at($response, 'error.code'));
    }

    /**
     * @return iterable<string, array{string, int|string|null}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'scalar body' => ['42', null];
        yield 'jsonrpc missing' => ['{"id":1,"method":"ping"}', 1];
        yield 'jsonrpc 1.0' => ['{"jsonrpc":"1.0","id":"a","method":"ping"}', 'a'];
        yield 'method missing' => ['{"jsonrpc":"2.0","id":1}', 1];
        yield 'method not a string' => ['{"jsonrpc":"2.0","id":1,"method":12}', 1];
        yield 'null id' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}', null];
        yield 'fractional id' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}', null];
        yield 'boolean id' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}', null];
        yield 'params as a list' => ['{"jsonrpc":"2.0","id":3,"method":"ping","params":[1]}', 3];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequest(string $body, int|string|null $expectedId): void
    {
        $response = $this->server()->handle(Rpc::post($body, ['MCP-Protocol-Version' => '2025-11-25']));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_REQUEST, Rpc::at($response, 'error.code'));
        $decoded = Rpc::body($response);
        if ($expectedId === null) {
            self::assertArrayNotHasKey('id', $decoded);
        } else {
            self::assertSame($expectedId, $decoded['id']);
        }
    }

    public function testAnyNotificationIsAcceptedWithoutResponse(): void
    {
        foreach (['notifications/initialized', 'notifications/cancelled', 'notifications/unknown'] as $method) {
            $response = $this->server()->handle(Rpc::legacy($method, [], null));
            self::assertSame(202, $response->status, $method);
            self::assertSame('', $response->body, $method);
        }
    }

    public function testModernNotificationIsAccepted(): void
    {
        $response = $this->server()->handle(Rpc::modern('notifications/cancelled', ['requestId' => 3], null));

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    public function testClientResponseIsAcceptedAndIgnored(): void
    {
        $response = $this->server()->handle(Rpc::post('{"jsonrpc":"2.0","id":5,"result":{}}'));

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    public function testStringAndIntegerIdsAreEchoed(): void
    {
        $string = $this->server()->handle(Rpc::legacy('ping', [], 'abc'));
        $integer = $this->server()->handle(Rpc::legacy('ping', [], 0));

        self::assertSame('abc', Rpc::at($string, 'id'));
        self::assertSame(0, Rpc::at($integer, 'id'));
    }

    public function testInternalErrorDoesNotLeakDetails(): void
    {
        $logger = new MemoryLogger();
        $server = Server::builder('rpc', '1.0.0')
            ->withTool('boom', 'Fails.', function (): string {
                throw new \RuntimeException('secret connection string');
            })
            ->withLogger($logger)
            ->build();
        $response = $server->handle(Rpc::modern('tools/call', ['name' => 'boom']));

        self::assertSame(200, $response->status);
        self::assertSame(['code' => ErrorCode::INTERNAL_ERROR, 'message' => 'Internal error'], Rpc::at($response, 'error'));
        self::assertStringNotContainsString('secret connection string', $response->body);
        self::assertStringNotContainsString('secret connection string', $logger->dump());
        self::assertStringContainsString('RuntimeException', $logger->dump());
    }

    public function testResultThatCannotBeEncodedIsAnInternalError(): void
    {
        $server = Server::builder('rpc', '1.0.0')->withTool('binary', 'Invalid UTF-8.', fn(): string => "\xB1\x31")->build();
        $response = $server->handle(Rpc::modern('tools/call', ['name' => 'binary']));

        self::assertSame(ErrorCode::INTERNAL_ERROR, Rpc::at($response, 'error.code'));
        self::assertSame(1, Rpc::at($response, 'id'));
    }
}
