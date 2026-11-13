<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Http\HeaderValue;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Http\HttpResponse;
use PolygonLab\McpKit\Log\CallableLogger;
use PolygonLab\McpKit\Log\LogLevel;
use PolygonLab\McpKit\Log\NullLogger;

/**
 * HTTP value objects, header decoding and loggers.
 */
final class HttpTest extends TestCase
{
    public function testRequestFromServerVariables(): void
    {
        $request = HttpRequest::fromGlobals([
            'REQUEST_METHOD' => 'post',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '12',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_X_MCP_ORIGIN_SECRET' => 'value',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc',
            'argv' => ['ignored'],
        ]);

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('12', $request->header('Content-Length'));
        self::assertSame('2026-07-28', $request->header('MCP-Protocol-Version'));
        self::assertSame('value', $request->header('X-Mcp-Origin-Secret'));
        self::assertSame('Bearer abc', $request->header('Authorization'));
        self::assertSame('', $request->body);
    }

    public function testDirectAuthorizationHeaderWins(): void
    {
        $request = HttpRequest::fromGlobals(['HTTP_AUTHORIZATION' => 'Bearer direct', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer other']);

        self::assertSame('Bearer direct', $request->header('Authorization'));
        self::assertSame('GET', $request->method);
    }

    public function testResponseHeadersAreCaseInsensitive(): void
    {
        $response = (new HttpResponse(200, ['Content-Type' => 'text/plain']))->withHeader('content-type', 'application/json');

        self::assertSame(['content-type' => 'application/json'], $response->headers);
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
        self::assertNull($response->header('Missing'));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function headerValues(): iterable
    {
        yield 'plain ASCII' => ['us-west1', 'us-west1'];
        yield 'base64 sentinel' => ['=?base64?SGVsbG8sIOS4lueVjA==?=', 'Hello, 世界'];
        yield 'base64 of a sentinel-like literal' => ['=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?=', '=?base64?literal?='];
        yield 'tab is allowed' => ["a\tb", "a\tb"];
        yield 'newline is refused' => ["a\nb", null];
        yield 'non-ASCII is refused' => ['é', null];
        yield 'invalid base64' => ['=?base64?@@?=', null];
        yield 'base64 of invalid UTF-8' => ['=?base64?' . base64_encode("\xff") . '?=', null];
    }

    #[DataProvider('headerValues')]
    public function testHeaderValueDecoding(string $raw, ?string $expected): void
    {
        self::assertSame($expected, HeaderValue::decode($raw));
    }

    public function testCallableLoggerForwardsEntries(): void
    {
        $entries = [];
        $logger = new CallableLogger(function (string $level, string $message, array $context) use (&$entries): void {
            $entries[] = [$level, $message, $context];
        });
        $logger->log(LogLevel::WARNING, 'something', ['status' => 403]);
        (new NullLogger())->log(LogLevel::ERROR, 'ignored');

        self::assertSame([['warning', 'something', ['status' => 403]]], $entries);
    }
}
