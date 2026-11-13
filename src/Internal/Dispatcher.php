<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Exception\JsonRpcException;
use PolygonLab\McpKit\Exception\ToolError;
use PolygonLab\McpKit\Http\HeaderValue;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Log\LogLevel;
use PolygonLab\McpKit\ProtocolVersion;
use PolygonLab\McpKit\Schema\Validator;
use PolygonLab\McpKit\Tool\ToolResult;

/**
 * Executes one JSON-RPC request (a message with an id) and returns its result.
 *
 * Methods of the modern era (2026-07-28): server/discover, tools/list, tools/call.
 * Methods of the legacy era (2025-11-25, 2025-06-18): initialize, ping, tools/list, tools/call.
 *
 * @internal
 */
final readonly class Dispatcher
{
    public function __construct(private ServerConfig $config) {}

    /**
     * @throws JsonRpcException
     */
    public function dispatch(RequestContext $context, string $method, \stdClass $params, HttpRequest $http): \stdClass
    {
        $result = match (true) {
            $method === 'tools/list' => $this->listTools($context, $params),
            $method === 'tools/call' => $this->callTool($context, $params, $http),
            $context->modern && $method === 'server/discover' => $this->discover(),
            !$context->modern && $method === 'initialize' => $this->initialize($params),
            !$context->modern && $method === 'ping' => new \stdClass(),
            // Modern era: an unknown method is answered with HTTP 404 (Streamable HTTP, 2026-07-28).
            default => throw JsonRpcException::methodNotFound($method, $context->modern ? 404 : 200),
        };

        if ($context->modern) {
            $result = (object) (['resultType' => 'complete'] + get_object_vars($result));
            $result->_meta = (object) ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()];
        }

        return $result;
    }

    private function initialize(\stdClass $params): \stdClass
    {
        $requested = $params->protocolVersion ?? null;
        if (!is_string($requested)) {
            throw JsonRpcException::invalidParams('"protocolVersion" must be a string');
        }
        if (property_exists($params, 'capabilities') && !$params->capabilities instanceof \stdClass) {
            throw JsonRpcException::invalidParams('"capabilities" must be an object');
        }

        // Same version when supported, otherwise the latest handshake-based revision.
        $result = new \stdClass();
        $result->protocolVersion = ProtocolVersion::isLegacy($requested) ? $requested : ProtocolVersion::LATEST_LEGACY;
        $result->capabilities = (object) ['tools' => (object) ['listChanged' => false]];
        $result->serverInfo = $this->serverInfo();
        if ($this->config->instructions !== null) {
            $result->instructions = $this->config->instructions;
        }

        return $result;
    }

    private function discover(): \stdClass
    {
        $result = new \stdClass();
        $result->supportedVersions = ProtocolVersion::supported();
        $result->capabilities = (object) ['tools' => new \stdClass()];
        if ($this->config->instructions !== null) {
            $result->instructions = $this->config->instructions;
        }
        $result->ttlMs = $this->config->cacheTtlMs;
        $result->cacheScope = $this->config->cacheScope;

        return $result;
    }

    private function listTools(RequestContext $context, \stdClass $params): \stdClass
    {
        $offset = 0;
        if (property_exists($params, 'cursor') && $params->cursor !== null) {
            $offset = is_string($params->cursor) ? Cursor::decode($params->cursor) : null;
            if ($offset === null || $offset > $this->config->tools->count()) {
                throw JsonRpcException::invalidParams('invalid cursor');
            }
        }

        $page = array_slice($this->config->tools->all(), $offset, $this->config->pageSize);
        $result = new \stdClass();
        $result->tools = array_map(static fn(RegisteredTool $tool): \stdClass => $tool->definition->toJson(), $page);
        $next = $offset + count($page);
        if ($next < $this->config->tools->count()) {
            $result->nextCursor = Cursor::encode($next);
        }
        if ($context->modern) {
            $result->ttlMs = $this->config->cacheTtlMs;
            $result->cacheScope = $this->config->cacheScope;
        }

        return $result;
    }

    private function callTool(RequestContext $context, \stdClass $params, HttpRequest $http): \stdClass
    {
        $name = $params->name ?? null;
        if (!is_string($name)) {
            throw JsonRpcException::invalidParams('"name" must be a string');
        }
        $tool = $this->config->tools->get($name);
        if ($tool === null) {
            throw JsonRpcException::invalidParams('Unknown tool: ' . $name);
        }
        $arguments = $params->arguments ?? new \stdClass();
        if (!$arguments instanceof \stdClass) {
            throw JsonRpcException::invalidParams('"arguments" must be an object');
        }
        if ($context->modern) {
            $this->checkParameterHeaders($tool, $arguments, $http);
        }

        $errors = Validator::validate($tool->definition->inputSchema, $arguments);
        if ($errors !== []) {
            $result = ToolResult::error('Invalid arguments: ' . implode('; ', $errors) . '.');
        } else {
            $result = $this->invoke($tool, $arguments);
        }

        return $result->toJson(!$context->modern);
    }

    private function invoke(RegisteredTool $tool, \stdClass $arguments): ToolResult
    {
        try {
            $value = ($tool->invoker)($arguments);

            return match (true) {
                $value instanceof ToolResult => $value,
                is_string($value) => ToolResult::text($value),
                $value === null => ToolResult::text(),
                default => ToolResult::structured($value),
            };
        } catch (ToolError $error) {
            return ToolResult::error($error->getMessage());
        } catch (\Throwable $exception) {
            // Never sent to the client: the message may contain internal details.
            $this->config->logger->log(LogLevel::ERROR, 'MCP tool failed', [
                'tool' => $tool->definition->name,
                'exception' => $exception::class,
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            throw JsonRpcException::internalError();
        }
    }

    /**
     * `Mcp-Param-{Name}` headers must match the arguments (x-mcp-header, 2026-07-28).
     */
    private function checkParameterHeaders(RegisteredTool $tool, \stdClass $arguments, HttpRequest $http): void
    {
        foreach ($tool->headerParameters as $parameter) {
            $headerName = 'Mcp-Param-' . $parameter['header'];
            $raw = $http->header($headerName);
            $decoded = $raw === null ? null : HeaderValue::decode($raw);
            if ($raw !== null && $decoded === null) {
                throw JsonRpcException::headerMismatch($headerName . ' header contains invalid characters');
            }

            $value = $arguments;
            foreach ($parameter['path'] as $segment) {
                $value = $value instanceof \stdClass && property_exists($value, $segment) ? $value->{$segment} : null;
            }
            if ($value === null) {
                continue;
            }
            if ($decoded === null) {
                throw JsonRpcException::headerMismatch('missing ' . $headerName . ' header');
            }
            if (!self::sameValue($decoded, $value)) {
                throw JsonRpcException::headerMismatch($headerName . ' header value does not match the body value');
            }
        }
    }

    private static function sameValue(string $header, mixed $value): bool
    {
        return match (true) {
            is_string($value) => $header === $value,
            is_bool($value) => $header === ($value ? 'true' : 'false'),
            is_int($value), is_float($value) => is_numeric($header) && (float) $header === (float) $value,
            default => false,
        };
    }

    /**
     * @return array{name: string, version: string, title?: string}
     */
    private function serverInfo(): array
    {
        $info = ['name' => $this->config->name, 'version' => $this->config->version];
        if ($this->config->title !== null) {
            $info['title'] = $this->config->title;
        }

        return $info;
    }
}
