<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Http;

/**
 * The HTTP response produced by the server: status code, headers and body.
 *
 * send() writes it to the current PHP process; tests read the properties directly.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers header name => value
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = [];
        foreach ($this->headers as $key => $existing) {
            if (strcasecmp($key, $name) !== 0) {
                $headers[$key] = $existing;
            }
        }
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }

    /**
     * Writes the response to the client (status line, headers, body).
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }
}
