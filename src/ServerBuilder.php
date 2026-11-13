<?php

declare(strict_types=1);

namespace PolygonLab\McpKit;

use PolygonLab\McpKit\Auth\Authenticator;
use PolygonLab\McpKit\Exception\InvalidConfiguration;
use PolygonLab\McpKit\Exception\InvalidToolDefinition;
use PolygonLab\McpKit\Internal\ServerConfig;
use PolygonLab\McpKit\Internal\ToolFactory;
use PolygonLab\McpKit\Internal\ToolRegistry;
use PolygonLab\McpKit\Log\Logger;
use PolygonLab\McpKit\Log\NullLogger;

/**
 * Immutable builder of a Server: every with*() method returns a new builder.
 */
final class ServerBuilder
{
    /** Default size of a `tools/list` page. */
    public const DEFAULT_PAGE_SIZE = 50;

    /** Default freshness hint of `tools/list` and `server/discover` results (one minute). */
    public const DEFAULT_CACHE_TTL_MS = 60_000;

    /** Default limit of a request body (1 MiB). */
    public const DEFAULT_MAX_BODY_BYTES = 1_048_576;

    private ?string $title = null;
    private ?string $instructions = null;
    private ToolRegistry $tools;
    private ?Authenticator $authenticator = null;

    /** @var list<string> */
    private array $allowedOrigins = [];
    private Logger $logger;
    private int $pageSize = self::DEFAULT_PAGE_SIZE;
    private int $cacheTtlMs = self::DEFAULT_CACHE_TTL_MS;
    private ?string $cacheScope = null;
    private int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES;

    /**
     * @internal use Server::builder()
     */
    public function __construct(private readonly string $name, private readonly string $version)
    {
        if (trim($name) === '' || trim($version) === '') {
            throw new InvalidConfiguration('The server name and version must not be empty.');
        }
        $this->tools = new ToolRegistry();
        $this->logger = new NullLogger();
    }

    /**
     * Human-readable name shown by clients.
     */
    public function withTitle(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /**
     * Guidance for the language model on how to use the server.
     */
    public function withInstructions(string $instructions): self
    {
        $clone = clone $this;
        $clone->instructions = $instructions;

        return $clone;
    }

    /**
     * Registers a tool from a callable. Its parameters, passed by name, give the input schema,
     * unless $inputSchema is provided.
     *
     * @param array<mixed>|null $inputSchema
     *
     * @throws InvalidToolDefinition
     */
    public function withTool(string $name, string $description, callable $handler, ?array $inputSchema = null, ?string $title = null): self
    {
        $clone = clone $this;
        $clone->tools = $this->tools->with(ToolFactory::fromCallable($name, $description, $handler, $inputSchema, $title));

        return $clone;
    }

    /**
     * Registers every public method of $instance marked with #[Tool].
     *
     * @throws InvalidToolDefinition
     */
    public function withToolsFrom(object $instance): self
    {
        $clone = clone $this;
        foreach (ToolFactory::fromObject($instance) as $tool) {
            $clone->tools = $clone->tools->with($tool);
        }

        return $clone;
    }

    public function withAuthenticator(Authenticator $authenticator): self
    {
        $clone = clone $this;
        $clone->authenticator = $authenticator;

        return $clone;
    }

    /**
     * Origins allowed to call the server from a browser, such as `https://app.example.com`.
     * A request carrying any other `Origin` header is refused with HTTP 403. Requests without
     * `Origin` (command-line and server-side clients) are not affected.
     *
     * @param list<string> $origins
     */
    public function withAllowedOrigins(array $origins): self
    {
        $normalised = [];
        foreach ($origins as $origin) {
            $origin = strtolower(rtrim(trim($origin), '/'));
            if (preg_match('~\Ahttps?://[a-z0-9.-]+(:[0-9]{1,5})?\z~', $origin) !== 1) {
                throw new InvalidConfiguration('Invalid origin "' . $origin . '": expected scheme://host[:port].');
            }
            $normalised[] = $origin;
        }
        $clone = clone $this;
        $clone->allowedOrigins = array_values(array_unique($normalised));

        return $clone;
    }

    public function withLogger(Logger $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    /**
     * Number of tools per `tools/list` page.
     */
    public function withPageSize(int $pageSize): self
    {
        if ($pageSize < 1 || $pageSize > 1000) {
            throw new InvalidConfiguration('The page size must be between 1 and 1000.');
        }
        $clone = clone $this;
        $clone->pageSize = $pageSize;

        return $clone;
    }

    /**
     * Caching hints of `tools/list` and `server/discover` (2026-07-28).
     *
     * @param string|null $scope "public", "private", or null: "private" when an authenticator is set, "public" otherwise
     */
    public function withCacheHints(int $ttlMs, ?string $scope = null): self
    {
        if ($ttlMs < 0) {
            throw new InvalidConfiguration('The cache TTL must be zero or positive.');
        }
        if ($scope !== null && $scope !== 'public' && $scope !== 'private') {
            throw new InvalidConfiguration('The cache scope must be "public" or "private".');
        }
        $clone = clone $this;
        $clone->cacheTtlMs = $ttlMs;
        $clone->cacheScope = $scope;

        return $clone;
    }

    /**
     * Largest accepted request body, in bytes.
     */
    public function withMaxBodyBytes(int $bytes): self
    {
        if ($bytes < 1024 || $bytes > Http\HttpRequest::MAX_READ_BYTES) {
            throw new InvalidConfiguration('The body limit must be between 1 KiB and 8 MiB.');
        }
        $clone = clone $this;
        $clone->maxBodyBytes = $bytes;

        return $clone;
    }

    public function build(): Server
    {
        return new Server(new ServerConfig(
            $this->name,
            $this->version,
            $this->title,
            $this->instructions,
            $this->tools,
            $this->authenticator,
            $this->allowedOrigins,
            $this->logger,
            $this->pageSize,
            $this->cacheTtlMs,
            $this->cacheScope ?? ($this->authenticator !== null ? 'private' : 'public'),
            $this->maxBodyBytes,
        ));
    }
}
