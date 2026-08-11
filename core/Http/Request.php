<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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

    /**
     * True when a POST's Content-Length exceeds post_max_size — PHP
     * silently empties $_POST/$_FILES in that case (only a "PHP Request
     * Startup" warning is logged), which otherwise surfaces downstream as
     * a confusing "invalid CSRF token" error since the token field
     * vanished along with everything else. Must be checked before
     * routing/CSRF validation, using the raw superglobals (this runs
     * before a Request instance — or even the rest of the bootstrap —
     * exists).
     *
     * Compares Content-Length directly against post_max_size rather than
     * inferring from empty $_POST/$_FILES — those are ALWAYS empty for a
     * JSON (or any non-form-encoded) body regardless of size, which would
     * otherwise misfire on every legitimate small JSON POST (e.g. the
     * AJAX "Générer avec l'IA" endpoints).
     */
    public static function isPostTooLarge(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return false;
        }

        $postMaxBytes = self::parseIniSizeToBytes((string) ini_get('post_max_size'));

        return $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    /**
     * Parses a php.ini size shorthand (e.g. "8M", "2G", "512K", "0") into
     * bytes. "0" (or empty) means unlimited, returned as 0. Public: pure
     * and side-effect-free, worth unit-testing directly rather than only
     * indirectly through isPostTooLarge() — post_max_size is
     * PHP_INI_PERDIR, so it can't be overridden via ini_set() in a test.
     */
    public static function parseIniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
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

    /**
     * The full body array — for a caller that needs to look up a key it
     * doesn't know in advance (e.g. Core\Security\HumanCheck\
     * HumanCheckService's per-render honeypot field name).
     *
     * @return array<string, mixed>
     */
    public function getBodyAll(): array
    {
        return $this->body;
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
