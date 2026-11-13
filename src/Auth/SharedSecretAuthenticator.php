<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Auth;

use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Http\HttpRequest;

/**
 * Requires a secret header added by a trusted proxy, such as the Cloudflare Worker of this kit
 * (`X-Mcp-Origin-Secret` by default). Requests that do not come through the proxy are refused
 * with HTTP 403.
 *
 * The secret is compared in constant time (SHA-256 digests and hash_equals()).
 */
final readonly class SharedSecretAuthenticator implements Authenticator
{
    public const DEFAULT_HEADER = 'X-Mcp-Origin-Secret';

    /** Shortest accepted secret, in characters. */
    public const MIN_SECRET_LENGTH = 32;

    private string $digest;

    public function __construct(
        #[\SensitiveParameter]
        string $secret,
        private string $headerName = self::DEFAULT_HEADER,
    ) {
        if (strlen(trim($secret)) < self::MIN_SECRET_LENGTH) {
            throw new InvalidConfiguration('The shared secret must contain at least ' . self::MIN_SECRET_LENGTH . ' characters.');
        }
        if (preg_match('/\A[A-Za-z0-9-]+\z/', $headerName) !== 1) {
            throw new InvalidConfiguration('Invalid header name: ' . $headerName);
        }
        $this->digest = hash('sha256', trim($secret));
    }

    public function authenticate(HttpRequest $request): AuthResult
    {
        $value = $request->header($this->headerName);
        if ($value === null) {
            return AuthResult::forbidden('missing shared secret header');
        }

        return hash_equals($this->digest, hash('sha256', trim($value)))
            ? AuthResult::granted()
            : AuthResult::forbidden('invalid shared secret');
    }
}
