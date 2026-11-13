```
       ▄▄▀▀▄▄        ____       _                         _          _
    ▄▀▀  ▄▄  ▀▀▄    |  _ \ ___ | |_   _  __ _  ___  _ __ | |    __ _| |__
    █  ▄▀  ▀▄  █    | |_) / _ \| | | | |/ _` |/ _ \| '_ \| |   / _` | '_ \
    █  ▀▄  ▄▀  █    |  __/ (_) | | |_| | (_| | (_) | | | | |__| (_| | |_) |
    ▀▄▄  ▀▀  ▄▄▀    |_|   \___/|_|\__, |\__, |\___/|_| |_|_____\__,_|_.__/
       ▀▀▄▄▀▀                     |___/ |___/
                                   polygonlab.ai
```

# mcp-php-kit

> Build MCP (Model Context Protocol) servers in plain PHP, hosted on any classic PHP hosting, with an optional Cloudflare Worker in front.

[![CI](https://github.com/polygonlab-ai/mcp-php-kit/actions/workflows/ci.yml/badge.svg)](https://github.com/polygonlab-ai/mcp-php-kit/actions/workflows/ci.yml)
[![Version](https://img.shields.io/packagist/v/polygonlab/mcp-php-kit)](https://packagist.org/packages/polygonlab/mcp-php-kit)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![PHP](https://img.shields.io/packagist/dependency-v/polygonlab/mcp-php-kit/php)](composer.json)

```
MCP client (Claude or any other)
        │  HTTPS, JSON-RPC 2.0, "Streamable HTTP" transport
        ▼
Cloudflare Worker (optional): access token, rate limit, headers, log
        │
        ▼
PHP server (mcp-php-kit): server/discover, tools/list, tools/call, initialize, ping…
        │
        ▼
Your tools: PHP methods declared with an attribute, or callables registered by hand
```

## Why

Most MCP servers are written for Node.js or Python and run as long-lived processes. Many
applications, though, live on PHP hosting where nothing runs between two requests: Apache or
Nginx with PHP-FPM, no daemon, no Node. This kit implements the MCP Streamable HTTP transport as
a plain PHP endpoint: each request is self-contained, the server keeps no state, and an
`index.php` of fifteen lines is enough. It has no runtime dependency, validates every argument
against the tool's JSON Schema before calling your code, and comes with an optional Cloudflare
Worker that adds a bearer token check and a rate limit before traffic reaches your server.

## Features

- MCP revision **2026-07-28** (stateless, `server/discover`), plus **2025-11-25** and **2025-06-18** for clients that open with `initialize`.
- JSON-RPC 2.0 requests, notifications and standard error codes, with the MCP error codes `-32020`, `-32022`.
- Streamable HTTP over POST with JSON responses, `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` and `Mcp-Param-*` header checks, `Origin` allow-list.
- Tools declared with the `#[Tool]` attribute or registered as callables; input schema generated from the parameter types.
- Arguments validated against the input schema before the call; business failures returned with `isError: true`.
- `tools/list` with cursor pagination and caching hints.
- Bearer token authentication compared in constant time, and a shared-secret check for the Worker.
- Optional logging through any PSR-3 logger, without arguments or tokens.
- PHP 8.2 or later, no runtime dependency.

## Installation

```bash
composer require polygonlab/mcp-php-kit
```

## Quick start

Create `index.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Server;

$server = Server::builder('minimal-example', '1.0.0')
    ->withTool('echo', 'Return the given text unchanged.', fn(string $text): string => $text)
    ->withTool('add', 'Add two numbers.', fn(float $a, float $b): array => ['sum' => $a + $b])
    ->build();

$server->handle(HttpRequest::fromGlobals())->send();
```

Start it with the PHP built-in server (or put it behind Apache or Nginx):

```bash
php -S 127.0.0.1:8080 index.php
```

Call the `add` tool:

```bash
curl -s http://127.0.0.1:8080/ \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: add' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"add","arguments":{"a":2,"b":3},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
```

Expected response (this example is [`examples/minimal/index.php`](examples/minimal/index.php), and a test runs this exact
request against it):

```json
{"jsonrpc":"2.0","id":1,"result":{"resultType":"complete","content":[{"type":"text","text":"{\"sum\":5}"}],"structuredContent":{"sum":5},"isError":false,"_meta":{"io.modelcontextprotocol/serverInfo":{"name":"minimal-example","version":"1.0.0"}}}}
```

To connect Claude, add the URL of the endpoint as a custom connector (remote MCP server), with
the bearer token described below.

## Usage

### Declaring tools with attributes

Mark public methods with `#[Tool]`; refine a parameter with `#[Param]`. The input schema is
generated from the parameter types, and the arguments are passed by name.

```php
use PolygonLab\McpKit\Attribute\Param;
use PolygonLab\McpKit\Attribute\Tool;
use PolygonLab\McpKit\Exception\ToolError;

final class CalculatorTools
{
    #[Tool(name: 'divide', description: 'Divide a number by another one.')]
    public function divide(
        #[Param(description: 'Number to divide')]
        float $dividend,
        #[Param(description: 'Number to divide by, not zero')]
        float $divisor,
    ): array {
        if ($divisor === 0.0) {
            throw new ToolError('Cannot divide by zero: choose another divisor.');
        }

        return ['quotient' => $dividend / $divisor];
    }

    #[Tool(name: 'round_number', description: 'Round a number to a given number of decimals.')]
    public function roundNumber(
        float $value,
        #[Param(description: 'Number of decimals', minimum: 0, maximum: 10)]
        int $decimals = 2,
    ): string {
        return number_format(round($value, $decimals), $decimals, '.', '');
    }
}

$server = Server::builder('calculator', '1.0.0')
    ->withToolsFrom(new CalculatorTools())
    ->build();
```

The full example, with authentication, is in [`examples/attributes/`](examples/attributes/).

| PHP parameter | JSON Schema | Notes |
|---|---|---|
| `string`, `int`, `float`, `bool` | `string`, `integer`, `number`, `boolean` | `2.0` is accepted for an `int` |
| `array` | `array` | `#[Param(items: [...])]` describes the items |
| Backed enum | `string` or `integer` with `enum` | The method receives the enum case |
| `?type` or a default value | Not in `required` | The default value is published as `default` |
| Anything else | Refused at registration | Describe it with `#[Param(schema: [...])]` |

`#[Param]` options: `description`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`,
`enum`, `items`, `schema` (replaces the generated schema) and `header` (mirrors the value in the
`Mcp-Param-{header}` HTTP header, `x-mcp-header` in the specification).

### Registering callables

```php
$server = Server::builder('tools', '1.0.0')
    ->withTool('greet', 'Greet someone.', fn(string $name): string => "Hello, {$name}!")
    ->withTool(
        'search',
        'Search the catalogue.',
        fn(string $query, int $limit = 10): array => $catalogue->search($query, $limit),
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ],
    )
    ->build();
```

With an explicit `inputSchema`, the arguments are still passed to the callable by name.

### Results and errors

| The tool… | The client receives |
|---|---|
| returns a `string` | one text content block |
| returns an array, a number, a boolean or a `JsonSerializable` | `structuredContent`, and the same value as JSON text |
| returns `ToolResult::text(...)`, `ToolResult::structured(...)` or `ToolResult::error(...)` | exactly that result |
| returns `null` | an empty content list |
| receives arguments that do not match its schema | `isError: true` and the list of problems, without being called |
| throws `ToolError` | `isError: true` and the message of the exception |
| throws any other exception | JSON-RPC error `-32603 Internal error`; the message is never sent, only logged by class |

`ToolError` messages are read by the language model: make them short and actionable.

### Supported JSON Schema

Dialect 2020-12. Validated keywords: `type`, `enum`, `const`, `properties`, `required`,
`additionalProperties`, `items`, `minItems`, `maxItems`, `minimum`, `maximum`,
`exclusiveMinimum`, `exclusiveMaximum`, `minLength`, `maxLength`, `pattern`. Annotations
`title`, `description`, `default`, `examples`, `format` and `x-mcp-header` are accepted. Any
other keyword (`$ref`, `anyOf`, `oneOf`…) is refused when the tool is registered, so that no
part of a schema is silently ignored.

## Authentication

```php
use PolygonLab\McpKit\Auth\BearerTokenAuthenticator;

$server = Server::builder('calculator', '1.0.0')
    ->withToolsFrom(new CalculatorTools())
    ->withAuthenticator(new BearerTokenAuthenticator([(string) getenv('MCP_TOKEN')]))
    ->build();
```

- Tokens come from your application (environment variable, secret store), never from the code.
  Several tokens allow a rotation without downtime. Each token has at least 16 characters; generate
  one with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.
- Only SHA-256 digests of the tokens are kept in memory, and they are compared with `hash_equals()`,
  in constant time.
- A request without a valid token gets `401` with `WWW-Authenticate: Bearer realm="mcp"`
  (and `error="invalid_token"` for a wrong token), before its body is read.
- Behind the Worker, `SharedSecretAuthenticator` requires the secret header added by the Worker
  (`403` otherwise). `RequireAll` combines authenticators.
- With Apache, PHP-FPM may not see the `Authorization` header: add `CGIPassAuth On` to the
  `.htaccess` of the endpoint.

```php
use PolygonLab\McpKit\Auth\RequireAll;
use PolygonLab\McpKit\Auth\SharedSecretAuthenticator;

// Only requests relayed by the Worker are accepted.
$authenticator = new SharedSecretAuthenticator((string) getenv('ORIGIN_SECRET'));

// Worker secret and bearer token (the Worker then relays Authorization, see below).
$authenticator = new RequireAll(
    new SharedSecretAuthenticator((string) getenv('ORIGIN_SECRET')),
    new BearerTokenAuthenticator([(string) getenv('MCP_TOKEN')]),
);
```

## Cloudflare Worker

The [`worker/`](worker/) folder holds an optional Worker (JavaScript modules, no runtime
dependency) to place in front of the PHP server. For each request it:

1. refuses any `Origin` header that is not in `ALLOWED_ORIGINS` (`403`);
2. accepts `POST` only, and `OPTIONS` for the CORS preflight (`405` otherwise);
3. limits the rate per IP address with the Workers Rate Limiting API (`429`);
4. checks the bearer token stored in the `MCP_TOKEN` secret, in constant time (`401`);
5. refuses bodies larger than `MAX_BODY_BYTES` (`413`);
6. relays the request to `ORIGIN_URL` with the MCP headers only, plus the shared secret header
   (`X-Mcp-Origin-Secret`), never following redirects;
7. writes one JSON log line (method, status, duration), without token, body or IP address.

Deployment, step by step:

1. Install Node.js 22 or later, then, from the `worker/` folder: `npm install`.
2. Copy the configuration: `cp wrangler.toml.example wrangler.toml`, then set `ORIGIN_URL` (the
   URL of your `index.php`) and, if browsers call the server, `ALLOWED_ORIGINS`.
3. Log in to Cloudflare: `npx wrangler login`.
4. Store the secrets (the values are typed at the prompt, never written in a file):
   `npx wrangler secret put MCP_TOKEN` and `npx wrangler secret put ORIGIN_SECRET`
   (`ORIGIN_SECRET`: 32 characters or more, for example `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`).
5. Deploy: `npx wrangler deploy`. Wrangler prints the URL of the Worker: give it to your MCP client.
6. On the PHP side, use `SharedSecretAuthenticator` with the same `ORIGIN_SECRET`.
7. Check: a request without token gets `401` from the Worker; a direct request to the PHP server
   gets `403`.

| Setting | Kind | Default | Role |
|---|---|---|---|
| `MCP_TOKEN` | secret | (required) | Accepted bearer token(s), comma-separated for a rotation |
| `ORIGIN_SECRET` | secret | (required) | Shared value added to relayed requests, 32 characters or more |
| `ORIGIN_URL` | variable | (required) | URL of the PHP server, `https` (`http` for localhost only) |
| `ALLOWED_ORIGINS` | variable | empty | Browser origins allowed, comma-separated |
| `ORIGIN_SECRET_HEADER` | variable | `X-Mcp-Origin-Secret` | Header carrying the shared secret |
| `MAX_BODY_BYTES` | variable | `1048576` | Largest accepted body |
| `FORWARD_AUTHORIZATION` | variable | `false` | `true` to relay `Authorization` to PHP as well |
| `MCP_RATE_LIMITER` | binding | none | Rate limiting binding (`[[ratelimits]]` in `wrangler.toml`) |

Rate limiting relies on the [Workers Rate Limiting API](https://developers.cloudflare.com/workers/runtime-apis/bindings/rate-limit/):
the example allows 60 requests per minute and per IP address, counted per Cloudflare location
(an approximate, not a global, counter). Without the binding the Worker still works, without
rate limit; you can then add a rate limiting rule in the Cloudflare dashboard (WAF) instead.

The Worker fails closed: with a missing or invalid setting, it answers `500` to every request
and logs which setting is wrong.

## Configuration

| Builder method | Type | Default | Role |
|---|---|---|---|
| `withTitle()` | `string` | none | Human-readable name in `serverInfo` |
| `withInstructions()` | `string` | none | Guidance for the model, sent by `server/discover` and `initialize` |
| `withTool()` | name, description, callable, schema, title | none | Registers a callable as a tool |
| `withToolsFrom()` | `object` | none | Registers the `#[Tool]` methods of an object |
| `withAuthenticator()` | `Authenticator` | none | Authentication of every request |
| `withAllowedOrigins()` | `list<string>` | empty | Browser origins allowed (`scheme://host[:port]`) |
| `withLogger()` | `Logger` | `NullLogger` | Log sink; `new CallableLogger($psrLogger->log(...))` for PSR-3 |
| `withPageSize()` | `int` | `50` | Tools per `tools/list` page |
| `withCacheHints()` | `int`, `?string` | `60000`, automatic | `ttlMs` and `cacheScope` of `tools/list` and `server/discover`; scope `private` when an authenticator is set |
| `withMaxBodyBytes()` | `int` | `1048576` | Largest request body |

Logged events: request handled (`info`: method, status, duration), request rejected (`warning`:
reason, status), tool failure (`error`: tool, exception class, file and line). Arguments, results,
tokens and exception messages are never logged.

## Compatibility

| Item | Supported |
|---|---|
| MCP specification | [2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28), the latest published revision; also 2025-11-25 and 2025-06-18 through the `initialize` handshake |
| Transport | Streamable HTTP, POST with `application/json` responses |
| PHP | 8.2, 8.3 and 8.4, tested by continuous integration; extensions `json` and `mbstring` |
| Hosting | Apache or Nginx with PHP-FPM, any host that runs PHP; no daemon, no Node.js |
| Worker | Cloudflare Workers (JavaScript modules); tests on Node.js 22, 24 and 26 |

Version negotiation: a request carrying `_meta["io.modelcontextprotocol/protocolVersion"]` is
served statelessly under 2026-07-28; an `initialize` request opens a 2025 session, answered with
the requested version when supported, otherwise with 2025-11-25. Any other version gets
`400` and the error `-32022` with the list of supported versions.

## Limitations

- **No SSE streams**: every response is a single JSON object, which keeps the kit simple on
  classic PHP hosting where long-lived connections tie up a PHP worker. Consequently no
  progress notifications, no `subscriptions/listen`, and `listChanged` is `false`.
- **Tools only**: MCP resources, prompts, completion, sampling, elicitation and logging are not
  implemented in this version, and are not announced in the capabilities.
- **No multi round-trip requests** (`input_required` results) and no tasks extension.
- **No JSON-RPC batches**: the supported revisions do not allow them.
- Protocol revision 2025-03-26 and older are not supported.

## Tests

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, max level
composer style     # PHP-CS-Fixer, PER Coding Style
cd worker && npm ci && npm test   # Worker, Vitest
```

## Contributing

See [CONTRIBUTING.md](https://github.com/polygonlab-ai/.github/blob/main/CONTRIBUTING.md).

## Security

What the kit does:

- checks `Origin` against an allow-list (protection against DNS rebinding), and refuses every
  browser origin by default;
- authenticates before reading the body, and compares secrets in constant time;
- requires the HTTP headers to mirror the body (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`,
  `Mcp-Param-*`) under 2026-07-28, as the specification asks, so that a proxy cannot be fooled;
- validates every argument against the input schema before calling a tool;
- limits the body size, refuses batches, never sends exception messages to clients, and never
  logs arguments or tokens;
- answers with `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

What it does not do:

- it does not implement OAuth 2.1 authorization (the `BearerTokenAuthenticator` accepts static
  tokens; `resource_metadata` can be advertised if you run your own authorization server);
- it does not rate-limit by itself: use the Worker or your web server;
- it does not sandbox your tools: a tool runs with the rights of your PHP application. Treat
  every argument as untrusted input, as you would treat a form field.

To report a vulnerability, never open a public issue: see
[SECURITY.md](https://github.com/polygonlab-ai/.github/blob/main/SECURITY.md).

## License

MIT License: see [LICENSE](LICENSE).

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
