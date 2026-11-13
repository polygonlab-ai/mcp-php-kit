<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\McpKit\Auth\BearerTokenAuthenticator;
use PolygonLab\McpKit\Auth\RequireAll;
use PolygonLab\McpKit\Auth\SharedSecretAuthenticator;
use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Http\HttpRequest;
use PolygonLab\McpKit\Server;
use PolygonLab\McpKit\Tests\Support\MemoryLogger;
use PolygonLab\McpKit\Tests\Support\Rpc;

/**
 * Bearer token, shared secret of the Worker, combination, and what is never logged.
 */
final class AuthenticationTest extends TestCase
{
    private const TOKEN = 'first-test-bearer-token';
    private const OTHER_TOKEN = 'second-test-bearer-token';
    private const SHARED = 'shared-value-between-worker-and-origin-server';

    private function server(?MemoryLogger $logger = null): Server
    {
        $builder = Server::builder('auth', '1.0.0')
            ->withTool('echo', 'Echo.', fn(string $text): string => $text)
            ->withAuthenticator(new BearerTokenAuthenticator([self::TOKEN, self::OTHER_TOKEN]));

        return ($logger === null ? $builder : $builder->withLogger($logger))->build();
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping'));

        self::assertSame(401, $response->status);
        self::assertSame('Bearer realm="mcp"', $response->header('WWW-Authenticate'));
        self::assertSame(['error' => 'unauthorized'], Rpc::body($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongCredentials(): iterable
    {
        yield 'wrong token' => ['Bearer not-the-right-token-value'];
        yield 'prefix of the token' => ['Bearer first-test-bearer'];
        yield 'token with a suffix' => ['Bearer first-test-bearer-token-and-more'];
        yield 'basic scheme' => ['Basic Zmlyc3QtdGVzdC1iZWFyZXItdG9rZW4='];
        yield 'empty bearer' => ['Bearer '];
    }

    #[DataProvider('wrongCredentials')]
    public function testWrongCredentialsAreUnauthorized(string $authorization): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Authorization' => $authorization]));

        self::assertSame(401, $response->status);
        self::assertIsString($response->header('WWW-Authenticate'));
        self::assertStringStartsWith('Bearer realm="mcp"', (string) $response->header('WWW-Authenticate'));
    }

    public function testWrongTokenGetsTheInvalidTokenError(): void
    {
        $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Authorization' => 'Bearer not-the-right-token-value']));

        self::assertSame('Bearer realm="mcp", error="invalid_token"', $response->header('WWW-Authenticate'));
    }

    public function testEveryConfiguredTokenIsAccepted(): void
    {
        foreach ([self::TOKEN, self::OTHER_TOKEN] as $token) {
            $response = $this->server()->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Authorization' => 'bearer  ' . $token]));
            self::assertSame(200, $response->status);
        }
    }

    public function testAuthenticationHappensBeforeTheBodyIsRead(): void
    {
        $response = $this->server()->handle(Rpc::post('not json at all'));

        self::assertSame(401, $response->status);
    }

    public function testChallengeCanAdvertiseResourceMetadata(): void
    {
        $authenticator = new BearerTokenAuthenticator([self::TOKEN], 'tools', 'https://example.com/.well-known/oauth-protected-resource');
        $result = $authenticator->authenticate(new HttpRequest('POST', [], ''));

        self::assertFalse($result->granted);
        self::assertSame('Bearer realm="tools", resource_metadata="https://example.com/.well-known/oauth-protected-resource"', $result->challenge);
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'no token' => [static fn(): mixed => new BearerTokenAuthenticator([])];
        yield 'short token' => [static fn(): mixed => new BearerTokenAuthenticator(['short'])];
        yield 'quote in realm' => [static fn(): mixed => new BearerTokenAuthenticator([self::TOKEN], 'a"b')];
        yield 'http metadata url' => [static fn(): mixed => new BearerTokenAuthenticator([self::TOKEN], 'mcp', 'http://example.com/meta')];
        yield 'short shared value' => [static fn(): mixed => new SharedSecretAuthenticator('short')];
        yield 'bad header name' => [static fn(): mixed => new SharedSecretAuthenticator(self::SHARED, 'X Header')];
        yield 'empty combination' => [static fn(): mixed => new RequireAll()];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(callable $build): void
    {
        $this->expectException(InvalidConfiguration::class);
        $build();
    }

    public function testSharedSecretFromTheWorker(): void
    {
        $server = Server::builder('origin', '1.0.0')->withAuthenticator(new SharedSecretAuthenticator(self::SHARED))->build();

        $missing = $server->handle(Rpc::legacy('ping'));
        $wrong = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['X-Mcp-Origin-Secret' => 'shared-value-between-worker-and-origin-serveR']));
        $right = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['x-mcp-origin-secret' => self::SHARED]));

        self::assertSame(403, $missing->status);
        self::assertNull($missing->header('WWW-Authenticate'));
        self::assertSame(['error' => 'forbidden'], Rpc::body($missing));
        self::assertSame(403, $wrong->status);
        self::assertSame(200, $right->status);
    }

    public function testRequireAllReturnsTheFirstRefusal(): void
    {
        $server = Server::builder('origin', '1.0.0')
            ->withAuthenticator(new RequireAll(new SharedSecretAuthenticator(self::SHARED), new BearerTokenAuthenticator([self::TOKEN])))
            ->build();

        $onlySecret = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['X-Mcp-Origin-Secret' => self::SHARED]));
        $both = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['X-Mcp-Origin-Secret' => self::SHARED, 'Authorization' => 'Bearer ' . self::TOKEN]));
        $onlyToken = $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Authorization' => 'Bearer ' . self::TOKEN]));

        self::assertSame(401, $onlySecret->status);
        self::assertSame(200, $both->status);
        self::assertSame(403, $onlyToken->status);
    }

    public function testTokensAreComparedInConstantTime(): void
    {
        foreach (['BearerTokenAuthenticator', 'SharedSecretAuthenticator'] as $class) {
            $source = (string) file_get_contents(__DIR__ . '/../src/Auth/' . $class . '.php');
            self::assertStringContainsString('hash_equals(', $source, $class);
            // No early-exit comparison of secrets (null checks excepted).
            self::assertStringNotContainsString('in_array(', $source, $class);
            self::assertStringNotContainsString('strcmp(', $source, $class);
            self::assertDoesNotMatchRegularExpression('/\$(?:digest|digests|given|value|token)\s*+(?>===|!==|==|!=)\s*+(?!null\b|\[\])/', $source, $class);
        }
    }

    public function testTokensAreNotKeptInClearInMemory(): void
    {
        $dump = print_r(new BearerTokenAuthenticator([self::TOKEN]), true) . print_r(new SharedSecretAuthenticator(self::SHARED), true);

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString(self::SHARED, $dump);
    }

    public function testTokensAndArgumentsAreNeverLogged(): void
    {
        $logger = new MemoryLogger();
        $server = $this->server($logger);
        $server->handle(Rpc::legacy('ping', [], 1, '2025-11-25', ['Authorization' => 'Bearer wrong-but-sensitive-value']));
        $server->handle(Rpc::modern('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'private-argument-value']], 1, ['Authorization' => 'Bearer ' . self::TOKEN]));

        self::assertNotSame([], $logger->entries);
        self::assertStringNotContainsString('wrong-but-sensitive-value', $logger->dump());
        self::assertStringNotContainsString(self::TOKEN, $logger->dump());
        self::assertStringNotContainsString('private-argument-value', $logger->dump());
        self::assertStringContainsString('invalid bearer token', $logger->dump());
        self::assertStringContainsString('tools/call', $logger->dump());
    }
}
