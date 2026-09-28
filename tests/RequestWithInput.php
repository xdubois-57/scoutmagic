<?php

declare(strict_types=1);

namespace Tests;

use Core\Http\Request;

/**
 * A request whose input is given rather than read from the process.
 *
 * Production reads a JSON body from `php://input` and uploads from
 * `$_FILES` — one a test cannot write to, the other global state every
 * later test would inherit. Tests used to get around both with a partial
 * mock of `Request` replacing the one accessor, which PHPUnit reports as a
 * mock with no expectation on every test that uses it (issue #665). This
 * is the same request with those accessors answering from constructor
 * arguments instead; every other accessor is production's.
 *
 * Like the mocks it replaces, the upload accessors answer whatever field
 * name they are asked for: each test sends a single upload.
 */
final class RequestWithInput extends Request
{
    private readonly string $rawBody;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     * @param string|false $rawBody what `getRawBody()` answers; `false`,
     *        as `json_encode()` may return, reads as an empty body, the
     *        way production reads an unreadable input stream
     * @param array<string, mixed>|null $file what `getFile()` answers
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int, type: string}> $files
     *        what `getFiles()` answers
     */
    public function __construct(
        string $method,
        string $path,
        array $query,
        array $body,
        array $cookies,
        array $server,
        string|false $rawBody = '',
        private readonly ?array $file = null,
        private readonly array $files = [],
    ) {
        parent::__construct($method, $path, $query, $body, $cookies, $server);
        $this->rawBody = $rawBody === false ? '' : $rawBody;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getFile(string $key): ?array
    {
        return $this->file;
    }

    public function getFiles(string $key): array
    {
        return $this->files;
    }
}
