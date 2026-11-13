<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Auth;

/**
 * Outcome of an authentication: granted, or denied with the HTTP status and challenge to return.
 */
final readonly class AuthResult
{
    private function __construct(
        public bool $granted,
        public int $status,
        public ?string $challenge,
        public string $reason,
    ) {}

    public static function granted(): self
    {
        return new self(true, 200, null, '');
    }

    /**
     * Credentials missing or wrong: HTTP 401 with a `WWW-Authenticate` challenge.
     */
    public static function unauthorized(string $challenge, string $reason): self
    {
        return new self(false, 401, $challenge, $reason);
    }

    /**
     * Request refused without a challenge: HTTP 403.
     */
    public static function forbidden(string $reason): self
    {
        return new self(false, 403, null, $reason);
    }
}
