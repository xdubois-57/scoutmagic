<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\Drive;

use Core\Storage\Location\Backend\Drive\GoogleDriveClient;

/**
 * A Google Drive that answers, in memory.
 *
 * **Why a fake service rather than a fake client.** The thing worth
 * testing about {@see \Core\Storage\Location\Backend\GoogleDriveBackend}
 * is what it does across several calls — open a session, append, resume
 * after a run that died, promote, collapse the namesakes Drive allows and
 * a storage key does not. A mocked client answers each call in isolation
 * and asserts the sequence somebody expected; this one holds state, so a
 * backend that sends the same bytes twice, or promotes a file that is not
 * finished, is caught by the FOLDER being wrong rather than by a mock
 * expectation being written to match the code.
 *
 * It speaks Drive's HTTP surface through the transport closure
 * `GoogleDriveClient` accepts, so the client under it is the real one:
 * the `Content-Range` arithmetic, the 308 handling and the multipart
 * envelope are all exercised rather than stubbed.
 *
 * Deliberately not a faithful Drive. It ignores paging (one page), never
 * short-commits unless told to, and knows nothing about trash. What it
 * IS faithful about is the two things that bite: a session is the only
 * way large bytes arrive, and two files may share a name.
 */
final class FakeDrive
{
    /** @var array<string, array{name: string, content: string, mime: string, modified: string}> */
    public array $files = [];

    /** @var array<string, array{name: string, total: int, received: string}> */
    public array $sessions = [];

    /** How many HTTP requests the backend has made. */
    public int $requests = 0;

    /** Committed offsets to force, per session URL, instead of everything. */
    public ?int $commitOnly = null;

    /** A status to answer the next request with, instead of succeeding. */
    public ?int $failNextWith = null;

    private int $nextId = 1;

    public function __construct(public string $folderId = 'folder-1')
    {
    }

    public function client(): GoogleDriveClient
    {
        return new GoogleDriveClient(
            fn (string $method, string $url, array $headers, ?string $body): array
                => $this->answer($method, $url, $headers, $body)
        );
    }

    /** The content stored under $name, or null — what a test asserts on. */
    public function contentOf(string $name): ?string
    {
        foreach ($this->files as $file) {
            if ($file['name'] === $name) {
                return $file['content'];
            }
        }

        return null;
    }

    /** How many files carry $name — Drive allows more than one. */
    public function countNamed(string $name): int
    {
        return count(array_filter($this->files, static fn (array $f): bool => $f['name'] === $name));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_map(static fn (array $f): string => $f['name'], $this->files));
    }

    public function put(string $name, string $content, string $mime = 'application/octet-stream'): string
    {
        $id = 'file-' . $this->nextId++;
        $this->files[$id] = [
            'name' => $name,
            'content' => $content,
            'mime' => $mime,
            'modified' => '2026-09-0' . min(9, count($this->files) + 1) . 'T03:00:00.000Z',
        ];

        return $id;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location?: string, range?: string}
     */
    private function answer(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests++;

        if ($this->failNextWith !== null) {
            $status = $this->failNextWith;
            $this->failNextWith = null;

            return ['status' => $status, 'body' => '{"error":{"message":"refused"}}'];
        }

        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return ['status' => 200, 'body' => '{"access_token":"ya29.test","expires_in":3599}'];
        }
        if (str_contains($url, '/about?')) {
            return ['status' => 200, 'body' => (string) json_encode([
                'user' => ['emailAddress' => 'unite@example.org'],
                'storageQuota' => ['limit' => '1000', 'usage' => '400'],
            ])];
        }
        if (str_starts_with($url, 'https://upload.example/')) {
            return $this->session($method, $url, $headers, $body);
        }
        if (str_contains($url, 'uploadType=resumable')) {
            return $this->openSession((string) $body);
        }
        if (str_contains($url, 'uploadType=multipart')) {
            return $this->multipart((string) $body);
        }
        if ($method === 'GET' && str_contains($url, 'alt=media')) {
            $id = $this->idIn($url);

            return isset($this->files[$id])
                ? ['status' => 200, 'body' => $this->files[$id]['content']]
                : ['status' => 404, 'body' => '{"error":{"message":"not found"}}'];
        }
        if ($method === 'DELETE') {
            unset($this->files[$this->idIn($url)]);

            return ['status' => 204, 'body' => ''];
        }
        if ($method === 'PATCH') {
            $id = $this->idIn($url);
            $decoded = json_decode((string) $body, true);
            if (isset($this->files[$id]) && is_array($decoded) && is_string($decoded['mimeType'] ?? null)) {
                $this->files[$id]['mime'] = $decoded['mimeType'];
            }

            return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
        }
        if ($method === 'POST') {
            // Creating the folder.
            $decoded = json_decode((string) $body, true);
            $name = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';

            return ['status' => 200, 'body' => (string) json_encode(['id' => 'folder-created-' . $name])];
        }

        return $this->listing($url);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function listing(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $q = (string) ($query['q'] ?? '');

        $wanted = preg_match("/name='([^']*)'/", $q, $m) === 1 ? $m[1] : null;
        $isFolderQuery = str_contains($q, 'google-apps.folder');

        $files = [];
        foreach ($this->files as $id => $file) {
            if ($isFolderQuery || ($wanted !== null && $file['name'] !== $wanted)) {
                continue;
            }
            $files[] = [
                'id' => $id,
                'name' => $file['name'],
                'size' => (string) strlen($file['content']),
                'md5Checksum' => md5($file['content']),
                'modifiedTime' => $file['modified'],
            ];
        }

        // Newest first, as Drive's `orderBy=createdTime desc` answers.
        $files = array_reverse($files);

        return ['status' => 200, 'body' => (string) json_encode(['files' => $files])];
    }

    /**
     * @return array{status: int, body: string, location: string}
     */
    private function openSession(string $body): array
    {
        $decoded = json_decode($body, true);
        $name = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';
        $url = 'https://upload.example/session-' . (count($this->sessions) + 1);
        $this->sessions[$url] = ['name' => $name, 'total' => 0, 'received' => ''];

        return ['status' => 200, 'body' => '{}', 'location' => $url];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function multipart(string $body): array
    {
        // Split on the boundary and read the two parts BY POSITION: the
        // metadata first, the bytes second. Keying on the part's own
        // `Content-Type` looks tidier and is wrong — this application
        // stores JSON objects of its own, so both parts would announce
        // `application/json` and the content would come back empty.
        $parts = array_values(array_filter(
            preg_split("/--[^\r\n]+(?:--)?\r\n/", $body) ?: [],
            static fn (string $part): bool => trim($part) !== ''
        ));

        $name = preg_match('/"name":"([^"]*)"/', $parts[0] ?? '', $m) === 1 ? $m[1] : '';
        $content = '';
        if (isset($parts[1])) {
            $split = explode("\r\n\r\n", $parts[1], 2);
            $content = count($split) === 2 ? (string) preg_replace("/\r\n$/", '', $split[1]) : '';
        }

        $id = $this->put($name, $content);

        return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, range?: string}
     */
    private function session(string $method, string $url, array $headers, ?string $body): array
    {
        if ($method === 'DELETE') {
            unset($this->sessions[$url]);

            return ['status' => 204, 'body' => ''];
        }

        $session = $this->sessions[$url] ?? null;
        if ($session === null) {
            return ['status' => 404, 'body' => '{"error":{"message":"session gone"}}'];
        }

        $range = $headers['Content-Range'] ?? '';
        if (preg_match('#^bytes \*/(\d+)$#', $range, $m) === 1) {
            // A probe.
            $this->sessions[$url]['total'] = (int) $m[1];
            $held = strlen($session['received']);

            return $held >= (int) $m[1] && $held > 0
                ? ['status' => 200, 'body' => (string) json_encode(['id' => $this->commit($url)])]
                : ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . max(0, $held - 1)];
        }

        if (preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $range, $m) !== 1) {
            return ['status' => 400, 'body' => '{"error":{"message":"bad range"}}'];
        }

        [, $from, , $total] = $m;
        if ((int) $from !== strlen($session['received'])) {
            return ['status' => 400, 'body' => '{"error":{"message":"offset mismatch"}}'];
        }

        $chunk = (string) $body;
        if ($this->commitOnly !== null) {
            $chunk = substr($chunk, 0, $this->commitOnly);
            $this->commitOnly = null;
        }
        $this->sessions[$url]['received'] .= $chunk;
        $this->sessions[$url]['total'] = (int) $total;

        $held = strlen($this->sessions[$url]['received']);
        if ($held >= (int) $total) {
            return ['status' => 200, 'body' => (string) json_encode(['id' => $this->commit($url)])];
        }

        return ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . ($held - 1)];
    }

    private function commit(string $url): string
    {
        $session = $this->sessions[$url];
        unset($this->sessions[$url]);

        return $this->put($session['name'], $session['received']);
    }

    private function idIn(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $parts = explode('/', $path);

        return rawurldecode((string) end($parts));
    }
}
