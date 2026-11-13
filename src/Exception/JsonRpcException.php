<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Exception;

use PolygonLab\McpKit\JsonRpc\ErrorCode;

/**
 * A JSON-RPC error to return to the client, with the HTTP status of the response.
 */
final class JsonRpcException extends \RuntimeException implements McpKitException
{
    public function __construct(
        int $code,
        string $message,
        public readonly mixed $data = null,
        public readonly int $httpStatus = 200,
    ) {
        parent::__construct($message, $code);
    }

    public static function parseError(): self
    {
        return new self(ErrorCode::PARSE_ERROR, 'Parse error: the body is not valid JSON', null, 400);
    }

    public static function invalidRequest(string $detail): self
    {
        return new self(ErrorCode::INVALID_REQUEST, 'Invalid Request: ' . $detail, null, 400);
    }

    public static function methodNotFound(string $method, int $httpStatus): self
    {
        return new self(ErrorCode::METHOD_NOT_FOUND, 'Method not found: ' . $method, null, $httpStatus);
    }

    public static function invalidParams(string $detail, int $httpStatus = 200): self
    {
        return new self(ErrorCode::INVALID_PARAMS, 'Invalid params: ' . $detail, null, $httpStatus);
    }

    public static function internalError(): self
    {
        return new self(ErrorCode::INTERNAL_ERROR, 'Internal error', null, 200);
    }

    public static function headerMismatch(string $detail): self
    {
        return new self(ErrorCode::HEADER_MISMATCH, 'Header mismatch: ' . $detail, null, 400);
    }

    /**
     * @param list<string> $supported
     */
    public static function unsupportedProtocolVersion(string $requested, array $supported): self
    {
        return new self(
            ErrorCode::UNSUPPORTED_PROTOCOL_VERSION,
            'Unsupported protocol version',
            ['supported' => $supported, 'requested' => $requested],
            400,
        );
    }
}
