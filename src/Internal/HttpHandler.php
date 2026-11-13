<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Exception\JsonRpcException;
use PolygonLab\McpKit\Http\HeaderValue;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Http\HttpResponse;
use PolygonLab\McpKit\JsonRpc\ErrorCode;
use PolygonLab\McpKit\Log\LogLevel;
use PolygonLab\McpKit\ProtocolVersion;

/**
 * Streamable HTTP transport, POST only, JSON responses (no SSE stream).
 *
 * Order of the checks: Origin, HTTP method, authentication, Content-Type, Accept, body size,
 * JSON, JSON-RPC envelope, protocol version and headers, then the method itself.
 *
 * @internal
 */
final readonly class HttpHandler
{
    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    private const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    /** Requests whose `Mcp-Name` header mirrors `params.name`. */
    private const NAMED_METHODS = ['tools/call', 'prompts/get'];

    private const ALLOWED_HEADERS = 'Content-Type, Accept, Authorization, MCP-Protocol-Version, Mcp-Method, Mcp-Name';

    private Dispatcher $dispatcher;

    public function __construct(private ServerConfig $config)
    {
        $this->dispatcher = new Dispatcher($config);
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $start = hrtime(true);
        $origin = $request->header('Origin');
        $originAllowed = $origin !== null && in_array(strtolower(rtrim($origin, '/')), $this->config->allowedOrigins, true);

        // 1. Origin: protection against DNS rebinding. A request without Origin (not a browser) is accepted.
        if ($origin !== null && !$originAllowed) {
            $this->reject('origin not allowed', 403);

            return $this->error(403, ErrorCode::INVALID_REQUEST, 'Forbidden: origin not allowed', null, null);
        }

        // 2. HTTP method.
        if ($request->method === 'OPTIONS') {
            return $this->preflight($request, $originAllowed);
        }
        if ($request->method !== 'POST') {
            $this->reject('method ' . $request->method . ' not allowed', 405);

            return $this->withCors(new HttpResponse(405, ['Allow' => 'POST, OPTIONS'] + self::baseHeaders()), $origin, $originAllowed);
        }

        // 3. Authentication.
        if ($this->config->authenticator !== null) {
            $result = $this->config->authenticator->authenticate($request);
            if (!$result->granted) {
                $this->reject($result->reason, $result->status);
                $headers = self::baseHeaders() + ['Content-Type' => 'application/json'];
                if ($result->challenge !== null) {
                    $headers['WWW-Authenticate'] = $result->challenge;
                }
                $body = Json::encode(['error' => $result->status === 401 ? 'unauthorized' : 'forbidden']);

                return $this->withCors(new HttpResponse($result->status, $headers, $body), $origin, $originAllowed);
            }
        }

        $response = $this->handlePost($request);
        $this->config->logger->log(LogLevel::INFO, 'MCP request handled', [
            'method' => $request->header('Mcp-Method'),
            'status' => $response->status,
            'durationMs' => round((hrtime(true) - $start) / 1e6, 2),
        ]);

        return $this->withCors($response, $origin, $originAllowed);
    }

    private function handlePost(HttpRequest $request): HttpResponse
    {
        // 4. Content-Type and Accept.
        $contentType = strtolower(trim(explode(';', $request->header('Content-Type') ?? '')[0]));
        if ($contentType !== 'application/json') {
            return $this->error(415, ErrorCode::INVALID_REQUEST, 'Invalid Request: Content-Type must be application/json', null, null);
        }
        $accept = $request->header('Accept');
        if ($accept !== null && !self::acceptsJson($accept)) {
            return $this->error(406, ErrorCode::INVALID_REQUEST, 'Invalid Request: the Accept header must allow application/json', null, null);
        }

        // 5. Body size.
        $length = $request->header('Content-Length');
        if (strlen($request->body) > $this->config->maxBodyBytes || ($length !== null && (int) $length > $this->config->maxBodyBytes)) {
            return $this->error(413, ErrorCode::INVALID_REQUEST, 'Invalid Request: body too large', null, null);
        }

        // 6. JSON and JSON-RPC envelope.
        try {
            $message = Json::decode($request->body);
        } catch (\JsonException) {
            return $this->exception(JsonRpcException::parseError(), null);
        }
        if (is_array($message)) {
            return $this->exception(JsonRpcException::invalidRequest('batch requests are not supported by this protocol version'), null);
        }
        if (!$message instanceof \stdClass) {
            return $this->exception(JsonRpcException::invalidRequest('the message must be a JSON object'), null);
        }

        $hasId = property_exists($message, 'id');
        $id = $hasId ? $message->id : null;
        $validId = is_string($id) || is_int($id);
        if (($message->jsonrpc ?? null) !== '2.0') {
            return $this->exception(JsonRpcException::invalidRequest('"jsonrpc" must be "2.0"'), $validId ? $id : null);
        }

        // A response sent by the client: accepted and ignored (this server never sends requests).
        if (!property_exists($message, 'method')) {
            if ($validId && (property_exists($message, 'result') || property_exists($message, 'error'))) {
                return new HttpResponse(202, self::baseHeaders());
            }

            return $this->exception(JsonRpcException::invalidRequest('"method" is missing'), $validId ? $id : null);
        }
        if (!is_string($message->method)) {
            return $this->exception(JsonRpcException::invalidRequest('"method" must be a string'), $validId ? $id : null);
        }
        if ($hasId && !$validId) {
            return $this->exception(JsonRpcException::invalidRequest('"id" must be a string or an integer'), null);
        }
        $params = property_exists($message, 'params') ? $message->params : new \stdClass();
        if (!$params instanceof \stdClass) {
            return $this->exception(JsonRpcException::invalidRequest('"params" must be an object'), $validId ? $id : null);
        }

        // 7. Notification: accepted, no response body (HTTP 202).
        if (!$hasId) {
            return new HttpResponse(202, self::baseHeaders());
        }
        /** @var int|string $id */

        // 8. Protocol version, headers, then the method.
        try {
            $context = $this->context($request, $message->method, $params);
            $result = $this->dispatcher->dispatch($context, $message->method, $params, $request);
        } catch (JsonRpcException $exception) {
            return $this->exception($exception, $id);
        }

        try {
            return $this->json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        } catch (\JsonException) {
            // A tool returned a value that cannot be encoded (invalid UTF-8, resource...).
            $this->config->logger->log(LogLevel::ERROR, 'MCP result not encodable as JSON', ['method' => $message->method]);

            return $this->exception(JsonRpcException::internalError(), $id);
        }
    }

    /**
     * Determines the era and the version of a request, and checks the mirrored headers.
     *
     * @throws JsonRpcException
     */
    private function context(HttpRequest $request, string $method, \stdClass $params): RequestContext
    {
        $meta = $params->_meta ?? null;
        $headerVersion = $request->header('MCP-Protocol-Version');

        // Modern request: the version travels in params._meta (2026-07-28).
        if ($meta instanceof \stdClass && property_exists($meta, self::META_VERSION)) {
            $version = $meta->{self::META_VERSION};
            if (!is_string($version)) {
                throw JsonRpcException::invalidParams('"' . self::META_VERSION . '" must be a string', 400);
            }
            if ($headerVersion === null) {
                throw JsonRpcException::headerMismatch('missing MCP-Protocol-Version header');
            }
            if ($headerVersion !== $version) {
                throw JsonRpcException::headerMismatch('MCP-Protocol-Version header value does not match the body value');
            }
            if (!ProtocolVersion::isModern($version)) {
                throw JsonRpcException::unsupportedProtocolVersion($version, ProtocolVersion::supported());
            }
            if (!($meta->{self::META_CAPABILITIES} ?? null) instanceof \stdClass) {
                throw JsonRpcException::invalidParams('"' . self::META_CAPABILITIES . '" must be an object', 400);
            }
            if (property_exists($meta, self::META_CLIENT_INFO) && !$meta->{self::META_CLIENT_INFO} instanceof \stdClass) {
                throw JsonRpcException::invalidParams('"' . self::META_CLIENT_INFO . '" must be an object', 400);
            }
            $this->checkStandardHeaders($request, $method, $params);

            return RequestContext::modern($version);
        }

        // Legacy handshake: the version is negotiated by the method itself.
        if ($method === 'initialize') {
            return RequestContext::legacy('');
        }

        // Legacy request: the version travels in the MCP-Protocol-Version header.
        if ($headerVersion === null) {
            throw JsonRpcException::unsupportedProtocolVersion(ProtocolVersion::ASSUMED_WITHOUT_HEADER, ProtocolVersion::supported());
        }
        if (ProtocolVersion::isLegacy($headerVersion)) {
            return RequestContext::legacy($headerVersion);
        }
        if (ProtocolVersion::isModern($headerVersion)) {
            throw JsonRpcException::invalidParams('missing required "_meta" field "' . self::META_VERSION . '"', 400);
        }

        throw JsonRpcException::unsupportedProtocolVersion($headerVersion, ProtocolVersion::supported());
    }

    /**
     * `Mcp-Method` (every request) and `Mcp-Name` (named requests) must mirror the body.
     *
     * @throws JsonRpcException
     */
    private function checkStandardHeaders(HttpRequest $request, string $method, \stdClass $params): void
    {
        $headerMethod = $request->header('Mcp-Method');
        if ($headerMethod === null) {
            throw JsonRpcException::headerMismatch('missing Mcp-Method header');
        }
        if ($headerMethod !== $method) {
            throw JsonRpcException::headerMismatch('Mcp-Method header value does not match the body value');
        }
        if (!in_array($method, self::NAMED_METHODS, true) || !is_string($params->name ?? null)) {
            return;
        }
        $rawName = $request->header('Mcp-Name');
        if ($rawName === null) {
            throw JsonRpcException::headerMismatch('missing Mcp-Name header');
        }
        $name = HeaderValue::decode($rawName);
        if ($name === null) {
            throw JsonRpcException::headerMismatch('Mcp-Name header contains invalid characters');
        }
        if ($name !== $params->name) {
            throw JsonRpcException::headerMismatch('Mcp-Name header value does not match the body value');
        }
    }

    private function preflight(HttpRequest $request, bool $originAllowed): HttpResponse
    {
        $headers = ['Allow' => 'POST, OPTIONS'] + self::baseHeaders();
        if ($originAllowed) {
            $requested = $request->header('Access-Control-Request-Headers') ?? '';
            $headers['Access-Control-Allow-Methods'] = 'POST, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = preg_match('/\A[A-Za-z0-9-]+(\s*,\s*[A-Za-z0-9-]+)*\z/', $requested) === 1
                ? $requested
                : self::ALLOWED_HEADERS;
            $headers['Access-Control-Max-Age'] = '600';
        }

        return $this->withCors(new HttpResponse(204, $headers), $request->header('Origin'), $originAllowed);
    }

    private function withCors(HttpResponse $response, ?string $origin, bool $originAllowed): HttpResponse
    {
        if ($origin === null || !$originAllowed) {
            return $response;
        }

        return $response->withHeader('Access-Control-Allow-Origin', $origin)->withHeader('Vary', 'Origin');
    }

    private function exception(JsonRpcException $exception, int|string|null $id): HttpResponse
    {
        return $this->error($exception->httpStatus, $exception->getCode(), $exception->getMessage(), $exception->data, $id);
    }

    private function error(int $status, int $code, string $message, mixed $data, int|string|null $id): HttpResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        $body = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $body['id'] = $id;
        }
        $body['error'] = $error;
        if ($status >= 400) {
            $this->reject($message, $status);
        }

        return $this->json($status, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'] + self::baseHeaders(), Json::encode($body));
    }

    private function reject(string $reason, int $status): void
    {
        $this->config->logger->log(LogLevel::WARNING, 'MCP request rejected', ['reason' => $reason, 'status' => $status]);
    }

    private static function acceptsJson(string $accept): bool
    {
        foreach (explode(',', strtolower($accept)) as $range) {
            $type = trim(explode(';', $range)[0]);
            if (in_array($type, ['application/json', 'application/*', '*/*'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function baseHeaders(): array
    {
        return ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
