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
