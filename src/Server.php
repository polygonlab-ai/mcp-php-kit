<?php

declare(strict_types=1);

namespace PolygonLab\McpKit;

use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Http\HttpResponse;
use PolygonLab\McpKit\Internal\HttpHandler;
use PolygonLab\McpKit\Internal\ServerConfig;

/**
 * An MCP server over the Streamable HTTP transport (POST, JSON responses).
 *
 *     $server = Server::builder('my-server', '1.0.0')
 *         ->withTool('echo', 'Return the given text.', fn (string $text): string => $text)
 *         ->build();
 *
 *     $server->handle(HttpRequest::fromGlobals())->send();
 *
 * A server is immutable and keeps no state between requests: it fits the PHP model, where
 * every request runs in a fresh process.
 */
final readonly class Server
{
    private HttpHandler $handler;

    /**
     * @internal use Server::builder()
     */
    public function __construct(ServerConfig $config)
    {
        $this->handler = new HttpHandler($config);
    }

    /**
     * @param string $name    server name, sent to clients in serverInfo
     * @param string $version server version, sent to clients in serverInfo
     */
    public static function builder(string $name, string $version): ServerBuilder
    {
        return new ServerBuilder($name, $version);
    }

    /**
     * Handles one HTTP request. Never throws: every failure becomes an HTTP response.
     */
    public function handle(HttpRequest $request): HttpResponse
    {
        return $this->handler->handle($request);
    }
}
