<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/CalculatorTools.php';

use PolygonLab\McpKit\Auth\BearerTokenAuthenticator;
use PolygonLab\McpKit\Examples\Attributes\CalculatorTools;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Server;

// The token comes from the environment, never from the code.
$token = getenv('MCP_TOKEN');
if (!is_string($token) || $token === '') {
    http_response_code(500);
    exit('MCP_TOKEN is not set.');
}

$server = Server::builder('calculator', '1.0.0')
    ->withToolsFrom(new CalculatorTools())
    ->withAuthenticator(new BearerTokenAuthenticator([$token]))
    ->build();

$server->handle(HttpRequest::fromGlobals())->send();
