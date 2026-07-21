<?php

declare(strict_types=1);

namespace Core\Http;

class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $body,
        private array $cookies,
        private array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            body: $_POST,
            cookies: $_COOKIE,
            server: $_SERVER
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function getBody(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function getCookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function getServer(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get an uploaded file entry from $_FILES.
     *
     * @return array<string, mixed>|null
     */
    public function getFile(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Normalizes a multi-file <input type="file" multiple name="key[]">
     * field into a list of individual per-file arrays. PHP's own
     * $_FILES shape for a multi-file field is the reverse of this — one
     * array per property (name/tmp_name/error/size/type), each holding
     * one entry per file — so this cannot just delegate to getFile().
     *
     * @return array<int, array{name: string, tmp_name: string, error: int, size: int, type: string}>
     */
    public function getFiles(string $key): array
    {
        $raw = $_FILES[$key] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }

        $files = [];
        foreach (array_keys($raw['name']) as $index) {
            $files[] = [
                'name' => (string) $raw['name'][$index],
                'tmp_name' => (string) $raw['tmp_name'][$index],
                'error' => (int) $raw['error'][$index],
                'size' => (int) $raw['size'][$index],
                'type' => (string) $raw['type'][$index],
            ];
        }
        return $files;
    }

    /**
     * Get the raw body content (for JSON requests).
     */
    public function getRawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Get the HTTP_REFERER header.
     */
    public function getReferer(): ?string
    {
        $referer = $this->server['HTTP_REFERER'] ?? null;

        return is_string($referer) ? $referer : null;
    }
}
