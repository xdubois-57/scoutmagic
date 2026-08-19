<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector;

/**
 * A throwaway HTTP server (php -S) that hands out canned responses and
 * records what it was sent.
 *
 * The provider drivers talk to a real endpoint through file_get_contents(),
 * and the bugs worth testing here live precisely in how they react to a real
 * HTTP status line — something no in-process mock of the driver can exercise.
 * So the tests point a driver at 127.0.0.1 and assert on genuine traffic.
 */
final class StubHttpServer
{
    /** @var resource|null */
    private $process = null;

    private string $dir;
    private int $port;

    private function __construct(string $dir, int $port)
    {
        $this->dir = $dir;
        $this->port = $port;
    }

    /**
     * @param array<int, array{status?: int, body: string, headers?: array<int, string>}> $responses
     *        Served in order; the last one repeats once the list runs out.
     */
    public static function start(array $responses): self
    {
        $dir = sys_get_temp_dir() . '/scoutmagic-stub-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        file_put_contents($dir . '/responses.json', json_encode(array_values($responses)));
        file_put_contents($dir . '/router.php', self::ROUTER);

        $port = self::freePort();

        $descriptors = [1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']];
        $process = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, '-t', $dir, $dir . '/router.php'],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the stub HTTP server.');
        }

        $server = new self($dir, $port);
        $server->process = $process;
        $server->waitUntilReady();

        return $server;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Every request the server received, oldest first.
     *
     * @return array<int, array{method: string, uri: string, body: string, headers: array<string, string>}>
     */
    public function requests(): array
    {
        $log = $this->dir . '/requests.jsonl';
        if (!is_file($log)) {
            return [];
        }

        $requests = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array{method: string, uri: string, body: string, headers: array<string, string>} $decoded */
                $requests[] = $decoded;
            }
        }

        return $requests;
    }

    /**
     * @return array{method: string, uri: string, body: string, headers: array<string, string>}
     */
    public function lastRequest(): array
    {
        $requests = $this->requests();
        if ($requests === []) {
            throw new \RuntimeException('The stub server received no request.');
        }

        return $requests[count($requests) - 1];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function waitUntilReady(): void
    {
        // php -S needs a moment to bind; 5s is far more than it ever takes
        // and keeps a slow CI box from failing spuriously.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(20_000);
        }

        throw new \RuntimeException('The stub HTTP server did not become ready.');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('Could not allocate a port for the stub HTTP server.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        if ($port <= 0) {
            throw new \RuntimeException('Could not read the allocated port.');
        }

        return $port;
    }

    private const ROUTER = <<<'PHP'
        <?php
        $dir = __DIR__;
        $responses = json_decode((string) file_get_contents($dir . '/responses.json'), true) ?: [];

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_TYPE') {
                $headers[$key] = (string) $value;
            }
        }

        file_put_contents(
            $dir . '/requests.jsonl',
            json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'body' => (string) file_get_contents('php://input'),
                'headers' => $headers,
            ]) . "\n",
            FILE_APPEND
        );

        $countFile = $dir . '/count.txt';
        $index = is_file($countFile) ? (int) file_get_contents($countFile) : 0;
        file_put_contents($countFile, (string) ($index + 1));

        // The last entry repeats, so a test only has to describe as many
        // responses as it actually cares about.
        $response = $responses[min($index, count($responses) - 1)] ?? ['status' => 200, 'body' => '{}'];

        http_response_code((int) ($response['status'] ?? 200));
        foreach (($response['headers'] ?? []) as $header) {
            header($header);
        }
        if (!isset($response['headers'])) {
            header('Content-Type: application/json');
        }
        echo (string) ($response['body'] ?? '');
        PHP;
}
