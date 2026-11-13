<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs a script with the PHP built-in web server, and sends it real HTTP requests:
 * the examples are tested through HttpRequest::fromGlobals() and HttpResponse::send().
 */
final class BuiltInServer
{
    /** @var resource */
    private $process;

    private int $port;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(string $script, array $environment = [])
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        Assert::assertNotFalse($probe, 'no free port: ' . $errorMessage);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $script];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname($script), $environment + ['PATH' => (string) getenv('PATH')]);
        Assert::assertIsResource($process, 'the built-in server did not start');
        $this->process = $process;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        Assert::fail('the built-in server did not answer on port ' . $this->port);
    }

    /**
     * Sends a request and returns [status, lower-case headers, body].
     *
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string}
     */
    public function request(string $method, array $headers = [], string $body = ''): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 5);
        Assert::assertNotFalse($socket, 'connection failed: ' . $errorMessage);
        stream_set_timeout($socket, 10);

        $lines = [$method . ' / HTTP/1.1', 'Host: 127.0.0.1:' . $this->port, 'Connection: close', 'Content-Length: ' . strlen($body)];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $headLines = explode("\r\n", $head);
        if (preg_match('~\AHTTP/1\.[01] (\d{3})~', (string) array_shift($headLines), $matches) !== 1) {
            Assert::fail('invalid status line');
        }
        $responseHeaders = [];
        foreach ($headLines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
        if (($responseHeaders['transfer-encoding'] ?? '') === 'chunked') {
            $responseBody = self::unchunk($responseBody);
        }

        return [(int) $matches[1], $responseHeaders, $responseBody];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function unchunk(string $body): string
    {
        $decoded = '';
        while ($body !== '') {
            [$size, $rest] = array_pad(explode("\r\n", $body, 2), 2, '');
            $length = (int) hexdec($size);
            if ($length === 0) {
                break;
            }
            $decoded .= substr($rest, 0, $length);
            $body = substr($rest, $length + 2);
        }

        return $decoded;
    }
}
