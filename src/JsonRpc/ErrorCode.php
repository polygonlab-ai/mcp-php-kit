<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\JsonRpc;

/**
 * JSON-RPC 2.0 error codes, and the codes defined by the MCP specification (2026-07-28).
 */
final class ErrorCode
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** HTTP headers do not match the body, or a required header is missing or malformed. */
    public const HEADER_MISMATCH = -32020;

    /** The request needs a client capability the client did not declare. */
    public const MISSING_REQUIRED_CLIENT_CAPABILITY = -32021;

    /** The requested protocol version is not supported by the server. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    private function __construct() {}
}
