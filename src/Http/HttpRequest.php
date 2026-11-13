<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Http;

/**
 * An incoming HTTP request: method, headers and raw body.
 *
 * Build it from the PHP globals with fromGlobals(), or directly in tests:
 * `new HttpRequest('POST', ['Content-Type' => 'application/json'], '{...}')`.
 * Header names are case-insensitive.
 */
final readonly class HttpRequest
{
    /** Hard limit on the body read by fromGlobals(), in bytes (8 MiB). */
    public const MAX_READ_BYTES = 8 * 1024 * 1024;

    public string $method;

    /** @var array<string, string> lower-case header name => value */
    public array $headers;

    /**
     * @param array<string, string> $headers header name => value (any case)
     */
    public function __construct(string $method, array $headers, public string $body)
    {
        $this->method = strtoupper($method);
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }
        $this->headers = $normalised;
    }

    /**
     * Request handled by the current PHP process (Apache, Nginx and PHP-FPM, built-in server).
     *
     * The body is read up to $maxBytes + 1 bytes, so that an oversized body is detected
     * without being loaded entirely in memory.
     *
     * @param array<array-key, mixed>|null $server defaults to $_SERVER
     */
    public static function fromGlobals(?array $server = null, int $maxBytes = self::MAX_READ_BYTES): self
    {
        $server ??= $_SERVER;
        $method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        // Some Apache configurations only expose the Authorization header after a rewrite.
        if (!isset($headers['authorization']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $body = '';
        $stream = fopen('php://input', 'rb');
        if ($stream !== false) {
            $read = stream_get_contents($stream, max(0, $maxBytes) + 1);
            fclose($stream);
            $body = $read === false ? '' : $read;
        }

        return new self($method, $headers, $body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
