<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests\Support;

use PHPUnit\Framework\Assert;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Http\HttpResponse;

/**
 * Builds MCP requests for the tests and reads the responses.
 */
final class Rpc
{
    public const MODERN = '2026-07-28';
    public const LEGACY = '2025-11-25';

    private function __construct() {}

    /**
     * Request of the modern era: `_meta` in the body, mirrored headers.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers extra or replaced headers
     */
    public static function modern(string $method, array $params = [], int|string|null $id = 1, array $headers = []): HttpRequest
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $base = ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => $method];
        if (($method === 'tools/call') && is_string($params['name'] ?? null)) {
            $base['Mcp-Name'] = $params['name'];
        }

        return self::post(self::message($method, $params, $id), array_merge($base, $headers));
    }

    /**
     * Request of the legacy era: no `_meta`, version in the MCP-Protocol-Version header.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    public static function legacy(string $method, array $params = [], int|string|null $id = 1, ?string $version = self::LEGACY, array $headers = []): HttpRequest
    {
        $base = $version === null ? [] : ['MCP-Protocol-Version' => $version];

        return self::post(self::message($method, $params, $id), array_merge($base, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function post(string $body, array $headers = []): HttpRequest
    {
        $base = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

        return new HttpRequest('POST', array_merge($base, $headers), $body);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function message(string $method, array $params, int|string|null $id): string
    {
        $message = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $message['id'] = $id;
        }
        $message['method'] = $method;
        if ($params !== []) {
            $message['params'] = $params;
        }

        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decoded body of a response (JSON objects as associative arrays).
     *
     * @return array<mixed>
     */
    public static function body(HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Value at a dotted path of the decoded body: `result.tools.0.name`.
     */
    public static function at(HttpResponse $response, string $path): mixed
    {
        $value = self::body($response);
        foreach (explode('.', $path) as $key) {
            Assert::assertIsArray($value, 'no value at "' . $path . '"');
            Assert::assertArrayHasKey($key, $value, 'no value at "' . $path . '"');
            $value = $value[$key];
        }

        return $value;
    }
}
