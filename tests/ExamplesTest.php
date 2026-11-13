<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Tests\Support\BuiltInServer;

/**
 * The examples run in the PHP built-in web server, and the README shows exactly what they do.
 */
final class ExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const TOKEN = 'example-test-bearer-token';

    public function testQuickStartCodeIsTheMinimalExample(): void
    {
        $example = str_replace(
            "require __DIR__ . '/../../vendor/autoload.php';",
            "require __DIR__ . '/vendor/autoload.php';",
            self::read(self::ROOT . '/examples/minimal/index.php'),
        );

        self::assertSame(rtrim($example, "\n"), self::block('php'));
        self::assertLessThanOrEqual(20, substr_count($example, "\n"), 'the minimal example stays within 20 lines');
    }

    public function testQuickStartRequestGivesTheDocumentedResponse(): void
    {
        $curl = self::block('bash', 1);
        if (preg_match("/-d '([^']+)'/", $curl, $data) !== 1) {
            self::fail('no -d argument in the curl command');
        }
        self::assertGreaterThan(0, preg_match_all("/-H '([^:]+): ([^']+)'/", $curl, $headers, PREG_SET_ORDER));
        $requestHeaders = [];
        foreach ($headers as $header) {
            $requestHeaders[$header[1]] = $header[2];
        }

        $server = new BuiltInServer(realpath(self::ROOT . '/examples/minimal/index.php') ?: '');
        [$status, $responseHeaders, $body] = $server->request('POST', $requestHeaders, $data[1]);
        $server->stop();

        self::assertSame(200, $status);
        self::assertSame('application/json', $responseHeaders['content-type'] ?? null);
        self::assertSame(self::block('json'), $body);
    }

    public function testMinimalExampleOverRealHttp(): void
    {
        $server = new BuiltInServer(realpath(self::ROOT . '/examples/minimal/index.php') ?: '');
        $json = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

        [$initialize, , $body] = $server->request('POST', $json, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');
        [$notification, , $empty] = $server->request('POST', $json + ['MCP-Protocol-Version' => '2025-06-18'], '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        [$echo, , $echoBody] = $server->request('POST', $json + ['MCP-Protocol-Version' => '2025-06-18'], '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":{"text":"héllo"}}}');
        [$get, $getHeaders] = $server->request('GET');
        $server->stop();

        self::assertSame(200, $initialize);
        self::assertStringContainsString('"protocolVersion":"2025-06-18"', $body);
        self::assertSame(202, $notification);
        self::assertSame('', $empty);
        self::assertSame(200, $echo);
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"héllo"}],"isError":false}}', $echoBody);
        self::assertSame(405, $get);
        self::assertSame('POST, OPTIONS', $getHeaders['allow'] ?? null);
    }

    public function testAttributesExampleWithAuthenticationAndValidation(): void
    {
        $server = new BuiltInServer(realpath(self::ROOT . '/examples/attributes/index.php') ?: '', ['MCP_TOKEN' => self::TOKEN]);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ];
        $call = static fn(string $tool, string $arguments): string => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . $tool . '","arguments":' . $arguments . '}}';

        [$anonymous, $anonymousHeaders] = $server->request('POST', $headers, $call('divide', '{"dividend":1,"divisor":2}'));
        $headers['Authorization'] = 'Bearer ' . self::TOKEN;
        [, , $divide] = $server->request('POST', $headers, $call('divide', '{"dividend":1,"divisor":4}'));
        [, , $byZero] = $server->request('POST', $headers, $call('divide', '{"dividend":1,"divisor":0}'));
        [, , $round] = $server->request('POST', $headers, $call('round_number', '{"value":3.14159}'));
        [, , $tooPrecise] = $server->request('POST', $headers, $call('round_number', '{"value":3.14159,"decimals":20}'));
        [, , $list] = $server->request('POST', $headers, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
        $server->stop();

        self::assertSame(401, $anonymous);
        self::assertSame('Bearer realm="mcp"', $anonymousHeaders['www-authenticate'] ?? null);
        self::assertStringContainsString('"structuredContent":{"quotient":0.25},"isError":false', $divide);
        self::assertStringContainsString('"text":"Cannot divide by zero: choose another divisor."}],"isError":true', $byZero);
        self::assertStringContainsString('"text":"3.14"}],"isError":false', $round);
        self::assertStringContainsString('Invalid arguments: /decimals: must be less than or equal to 10.', $tooPrecise);
        self::assertStringContainsString('"name":"round_number","description":"Round a number to a given number of decimals.","inputSchema":{"type":"object","properties":{"value":{"type":"number"},"decimals":{"type":"integer","description":"Number of decimals","minimum":0,"maximum":10,"default":2}},"required":["value"],"additionalProperties":false}', $list);
    }

    public function testAttributesExampleRefusesToStartWithoutToken(): void
    {
        $server = new BuiltInServer(realpath(self::ROOT . '/examples/attributes/index.php') ?: '');
        [$status, , $body] = $server->request('POST', ['Content-Type' => 'application/json'], '{}');
        $server->stop();

        self::assertSame(500, $status);
        self::assertSame('MCP_TOKEN is not set.', $body);
    }

    /**
     * Code block of the given language in the "Quick start" section ($index: 0 for the first one).
     */
    private static function block(string $language, int $index = 0): string
    {
        $readme = self::read(self::ROOT . '/README.md');
        $start = strpos($readme, "## Quick start\n");
        $end = strpos($readme, "\n## ", (int) $start + 1);
        self::assertNotFalse($start, 'no "Quick start" section in the README');
        self::assertNotFalse($end);
        $section = substr($readme, $start, $end - $start);

        $pattern = '/```' . preg_quote($language, '/') . '\n(.*?)\n```/s';
        self::assertGreaterThan($index, (int) preg_match_all($pattern, $section, $matches), 'no ' . $language . ' block #' . $index);

        return $matches[1][$index];
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, 'unreadable file: ' . $path);

        return $content;
    }
}
