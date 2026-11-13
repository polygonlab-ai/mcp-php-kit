<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Server;

$server = Server::builder('minimal-example', '1.0.0')
    ->withTool('echo', 'Return the given text unchanged.', fn(string $text): string => $text)
    ->withTool('add', 'Add two numbers.', fn(float $a, float $b): array => ['sum' => $a + $b])
    ->build();

$server->handle(HttpRequest::fromGlobals())->send();
