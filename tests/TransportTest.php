<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\Rpc;

/**
 * Streamable HTTP: HTTP method, Origin, Content-Type, Accept, body size, response headers.
 */
final class TransportTest extends TestCase
{
    private function server(): Server
    {
        return Server::builder('transport', '1.0.0')
            ->withTool('echo', 'Echo.', fn(string $text): string => $text)
            ->withAllowedOrigins(['https://app.example.com', 'http://localhost:5173/'])
            ->withMaxBodyBytes(2048)
            ->build();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMethods(): iterable
    {
        yield 'GET (no server stream in this kit)' => ['GET'];
        yield 'DELETE (no protocol session)' => ['DELETE'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
    }

    #[DataProvider('refusedMethods')]
    public function testOnlyPostIsAccepted(string $method): void
    {
        $response = $this->server()->handle(new HttpRequest($method, ['Accept' => 'text/event-stream'], ''));

        self::assertSame(405, $response->status);
        self::assertSame('POST, OPTIONS', $response->header('Allow'));
        self::assertSame('', $response->body);
    }

    public function testResponsesCarrySafeHeaders(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping'));

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testRequestWithoutOriginIsAccepted(): void
    {
        self::assertSame(200, $this->server()->handle(Rpc::legacy('ping'))->status);
    }

    public function testAllowedOriginGetsCorsHeaders(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Origin' => 'https://APP.example.com']));

        self::assertSame(200, $response->status);
        self::assertSame('https://APP.example.com', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->header('Vary'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedOrigins(): iterable
    {
        yield 'other site' => ['https://evil.example.net'];
        yield 'other scheme' => ['http://app.example.com'];
        yield 'other port' => ['https://app.example.com:8443'];
        yield 'opaque origin' => ['null'];
    }

    #[DataProvider('refusedOrigins')]
    public function testOtherOriginIsForbidden(string $origin): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Origin' => $origin]));

        self::assertSame(403, $response->status);
        self::assertSame(ErrorCode::INVALID_REQUEST, Rpc::at($response, 'error.code'));
        self::assertArrayNotHasKey('id', Rpc::body($response));
        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testAnyOriginIsForbiddenByDefault(): void
    {
        $server = Server::builder('s', '1')->build();
        $response = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Origin' => 'http://localhost:3000']));

        self::assertSame(403, $response->status);
    }

    public function testPreflightOfAnAllowedOrigin(): void
    {
        $response = $this->server()->handle(new HttpRequest('OPTIONS', [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type, mcp-protocol-version, mcp-method',
        ], ''));

        self::assertSame(204, $response->status);
        self::assertSame('http://localhost:5173', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('POST, OPTIONS', $response->header('Access-Control-Allow-Methods'));
        self::assertSame('content-type, mcp-protocol-version, mcp-method', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->header('Access-Control-Max-Age'));
    }

    public function testPreflightWithUnusualRequestedHeadersGetsTheDefaultList(): void
    {
        $response = $this->server()->handle(new HttpRequest('OPTIONS', [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Headers' => "x-test\r\nInjected: 1",
        ], ''));

        self::assertSame('Content-Type, Accept, Authorization, MCP-Protocol-Version, Mcp-Method, Mcp-Name', $response->header('Access-Control-Allow-Headers'));
    }

    public function testPreflightOfARefusedOrigin(): void
    {
        $response = $this->server()->handle(new HttpRequest('OPTIONS', ['Origin' => 'https://evil.example.net'], ''));

        self::assertSame(403, $response->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongContentTypes(): iterable
    {
        yield 'form' => ['application/x-www-form-urlencoded'];
        yield 'text' => ['text/plain'];
        yield 'missing' => [''];
    }

    #[DataProvider('wrongContentTypes')]
    public function testContentTypeMustBeJson(string $contentType): void
    {
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];
        $response = $this->server()->handle(new HttpRequest('POST', $headers + ['MCP-Protocol-Version' => '2025-11-25'], Rpc::message('ping', [], 1)));

        self::assertSame(415, $response->status);
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Content-Type' => 'application/json; charset=utf-8']));

        self::assertSame(200, $response->status);
    }

    public function testAcceptMustAllowJson(): void
    {
        $refused = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Accept' => 'text/event-stream']));
        $wildcard = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Accept' => '*/*']));
        $missing = $this->server()->handle(new HttpRequest('POST', ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-11-25'], Rpc::message('ping', [], 1)));

        self::assertSame(406, $refused->status);
        self::assertSame(200, $wildcard->status);
        self::assertSame(200, $missing->status);
    }

    public function testBodyTooLargeIsRefused(): void
    {
        $large = $this->server()->handle(Rpc::legacy('tools/call', ['name' => 'echo', 'arguments' => ['text' => str_repeat('a', 4096)]]));
        $declared = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Content-Length' => '999999']));

        self::assertSame(413, $large->status);
        self::assertSame(413, $declared->status);
    }

    public function testInvalidOriginConfigurationIsRefused(): void
    {
        $this->expectException(InvalidConfiguration::class);
        Server::builder('s', '1')->withAllowedOrigins(['*']);
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'empty name' => [static fn(): mixed => Server::builder(' ', '1')];
        yield 'page size zero' => [static fn(): mixed => Server::builder('s', '1')->withPageSize(0)];
        yield 'negative ttl' => [static fn(): mixed => Server::builder('s', '1')->withCacheHints(-1)];
        yield 'unknown scope' => [static fn(): mixed => Server::builder('s', '1')->withCacheHints(10, 'shared')];
        yield 'tiny body limit' => [static fn(): mixed => Server::builder('s', '1')->withMaxBodyBytes(10)];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(callable $build): void
    {
        $this->expectException(InvalidConfiguration::class);
        $build();
    }

    public function testBuilderIsImmutable(): void
    {
        $base = Server::builder('s', '1');
        $base->withTool('echo', 'Echo.', fn(string $text): string => $text);

        $response = $base->build()->handle(Rpc::legacy('tools/list'));
        self::assertSame([], Rpc::at($response, 'result.tools'));
    }
}
