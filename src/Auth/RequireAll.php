<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Auth;

use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Http\HttpRequest;

/**
 * Grants a request only when every authenticator grants it; returns the first refusal.
 *
 * Example: the Worker secret and a bearer token checked by PHP as well.
 */
final readonly class RequireAll implements Authenticator
{
    /** @var list<Authenticator> */
    private array $authenticators;

    public function __construct(Authenticator ...$authenticators)
    {
        if ($authenticators === []) {
            throw new InvalidConfiguration('RequireAll needs at least one authenticator.');
        }
        $this->authenticators = array_values($authenticators);
    }

    public function authenticate(HttpRequest $request): AuthResult
    {
        foreach ($this->authenticators as $authenticator) {
            $result = $authenticator->authenticate($request);
            if (!$result->granted) {
                return $result;
            }
        }

        return AuthResult::granted();
    }
}
