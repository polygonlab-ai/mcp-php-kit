<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Auth;

use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Http\HttpRequest;

/**
 * Bearer token authentication (`Authorization: Bearer <token>`, RFC 6750).
 *
 * The application provides the accepted tokens (environment variable, secret store), never
 * hard-coded values. Only their SHA-256 digests are kept in memory, and every digest is compared
 * with hash_equals(): the comparison takes the same time whatever the token and whatever the
 * position of the first wrong character, which defeats timing attacks.
 *
 * Refusal: HTTP 401 with `WWW-Authenticate: Bearer realm="..."` (and `error="invalid_token"`
 * when a wrong token was sent).
 */
final readonly class BearerTokenAuthenticator implements Authenticator
{
    /** Shortest accepted token, in characters. */
    public const MIN_TOKEN_LENGTH = 16;

    /** @var list<string> SHA-256 digests of the accepted tokens */
    private array $digests;

    /**
     * @param list<string> $tokens              accepted tokens (several allow a rotation without downtime)
     * @param string|null  $resourceMetadataUrl optional OAuth protected resource metadata URL, added to the challenge
     */
    public function __construct(
        #[\SensitiveParameter]
        array $tokens,
        private string $realm = 'mcp',
        private ?string $resourceMetadataUrl = null,
    ) {
        if ($tokens === []) {
            throw new InvalidConfiguration('BearerTokenAuthenticator needs at least one token.');
        }
        $digests = [];
        foreach ($tokens as $token) {
            if (strlen(trim($token)) < self::MIN_TOKEN_LENGTH) {
                throw new InvalidConfiguration('Every bearer token must contain at least ' . self::MIN_TOKEN_LENGTH . ' characters.');
            }
            $digests[] = hash('sha256', trim($token));
        }
        if (preg_match('/["\\\\\x00-\x1F\x7F]/', $realm) === 1) {
            throw new InvalidConfiguration('The realm must not contain quotes, backslashes or control characters.');
        }
        if ($resourceMetadataUrl !== null && preg_match('~\Ahttps://[^\s"\\\\]+\z~', $resourceMetadataUrl) !== 1) {
            throw new InvalidConfiguration('The resource metadata URL must be an https URL without quotes or spaces.');
        }
        $this->digests = $digests;
    }

    public function authenticate(HttpRequest $request): AuthResult
    {
        $header = $request->header('Authorization');
        if ($header === null || preg_match('/\A\s*Bearer\s+([^\s]+)\s*\z/i', $header, $matches) !== 1) {
            return AuthResult::unauthorized($this->challenge(null), 'missing bearer token');
        }

        // Every stored digest is compared, without stopping at the first match.
        $given = hash('sha256', $matches[1]);
        $valid = false;
        foreach ($this->digests as $digest) {
            $valid = hash_equals($digest, $given) || $valid;
        }

        return $valid
            ? AuthResult::granted()
            : AuthResult::unauthorized($this->challenge('invalid_token'), 'invalid bearer token');
    }

    private function challenge(?string $error): string
    {
        $challenge = 'Bearer realm="' . $this->realm . '"';
        if ($this->resourceMetadataUrl !== null) {
            $challenge .= ', resource_metadata="' . $this->resourceMetadataUrl . '"';
        }
        if ($error !== null) {
            $challenge .= ', error="' . $error . '"';
        }

        return $challenge;
    }
}
