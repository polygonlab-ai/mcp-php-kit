<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Auth;

use PolygonLab\McpKit\Http\HttpRequest;

/**
 * Decides whether an HTTP request may reach the MCP server.
 *
 * Called once per request, before the body is parsed. Implementations must compare secrets
 * in constant time (hash_equals) and must never log them.
 */
interface Authenticator
{
    public function authenticate(HttpRequest $request): AuthResult;
}
