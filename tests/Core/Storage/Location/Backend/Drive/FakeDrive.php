<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\Drive;

use Core\Storage\Location\Backend\Drive\GoogleDriveClient;

/**
 * A Google Drive that answers, in memory — folders, parents and trash
 * included.
 *
 * **Why a fake service rather than a fake client.** The thing worth
 * testing about {@see \Core\Storage\Location\Backend\GoogleDriveBackend}
 * is what it does across several calls — open a session, append, resume
 * after a run that died, promote, collapse the namesakes Drive allows and
 * a storage key does not, create an album folder once and find it again.
 * A mocked client answers each call in isolation and asserts the sequence
 * somebody expected; this one holds state, so a backend that writes into
 * the wrong folder, or sends the same bytes twice, is caught by the DRIVE
 * being wrong rather than by a mock expectation written to match the code.
 *
 * It speaks Drive's HTTP surface through the transport closure
 * `GoogleDriveClient` accepts, so the client under it is the real one:
 * the query strings, the `Content-Range` arithmetic, the 308 handling and
 * the multipart envelope are all exercised rather than stubbed.
 *
 * **How folders are modelled (#474).** Every entry — file or folder — has
 * an id, a name, ONE parent id (`root` for the top of « Mon Drive »), a
 * `trashed` flag and a creation sequence number. A folder is an entry
 * whose mime type is Drive's folder type. The query language is parsed
 * for what the client sends — `'<id>' in parents`, `name='…'`,
 * `mimeType='…'` and `mimeType!='…'`, `trashed=false` — with Drive's
 * backslash escaping, and a query it cannot parse is refused with a 400,
 * as Drive refuses it. An entry is trashed when it or any ancestor is,
 * which is Drive's own rule. `DELETE` on a folder removes everything
 * under it; a `PATCH` renames, trashes or relabels. Listings are paged
 * (`pageSize`, `pageToken`) so a walk that forgets a cursor is caught.
 *
 * By default it holds the tree a connected location has:
 * `ScoutMagic/Photos des galeries/`, whose id is {@see $folderId}. Pass
 * null for a Drive that has never met this application.
 *
 * Deliberately not a faithful Drive in everything else: it never
 * short-commits unless told to, and knows nothing about permissions.
 */
final class FakeDrive
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /**
     * @var array<string, array{name: string, parent: string, mime: string, content: string,
     *     trashed: bool, seq: int, modified: string}>
     */
    public array $files = [];

    /** @var array<string, array{name: string, parent: string, total: int, received: string}> */
    public array $sessions = [];

    /** How many HTTP requests the backend has made. */
    public int $requests = 0;

    /** Committed offsets to force, per session URL, instead of everything. */
    public ?int $commitOnly = null;

    /** A status to answer the next request with, instead of succeeding. */
    public ?int $failNextWith = null;

    /** A status to answer every PATCH with, instead of succeeding. */
    public ?int $failPatchesWith = null;

    /** The id of the ScoutMagic parent folder, when the tree was seeded. */
    public ?string $parentFolderId = null;

    private int $nextId = 1;

    private int $sequence = 0;

    public function __construct(public ?string $folderId = 'folder-1', string $label = 'Photos des galeries')
    {
        if ($folderId !== null) {
            $this->parentFolderId = $this->addFolder('ScoutMagic', 'root', 'scoutmagic-1');
            $this->addFolder($label, $this->parentFolderId, $folderId);
        }
    }

    public function client(): GoogleDriveClient
    {
        return new GoogleDriveClient(
            fn (string $method, string $url, array $headers, ?string $body): array
                => $this->answer($method, $url, $headers, $body)
        );
    }

    public function addFolder(string $name, string $parent, ?string $id = null): string
    {
        $id ??= 'dir-' . $this->nextId++;
        $this->files[$id] = [
            'name' => $name,
            'parent' => $parent,
            'mime' => self::FOLDER_MIME,
            'content' => '',
            'trashed' => false,
            'seq' => ++$this->sequence,
            'modified' => '2026-09-01T03:00:00.000Z',
        ];

        return $id;
    }

    /**
     * Stores a file directly — by default in the location's own folder.
     */
    public function put(string $name, string $content, string $mime = 'application/octet-stream', ?string $parent = null): string
    {
        $id = 'file-' . $this->nextId++;
        $this->files[$id] = [
            'name' => $name,
            'parent' => $parent ?? $this->folderId ?? 'root',
            'mime' => $mime,
            'content' => $content,
            'trashed' => false,
            'seq' => ++$this->sequence,
            'modified' => '2026-09-0' . min(9, count($this->files) + 1) . 'T03:00:00.000Z',
        ];

        return $id;
    }

    /** The content of the live file named $name anywhere, or null. */
    public function contentOf(string $name): ?string
    {
        foreach ($this->files as $id => $file) {
            if ($file['name'] === $name && $file['mime'] !== self::FOLDER_MIME && !$this->isTrashed($id)) {
                return $file['content'];
            }
        }

        return null;
    }

    /**
     * The content of the live file at $path, a path of names from the top
     * of « Mon Drive » (`ScoutMagic/Photos des galeries/5/med_9.jpg`).
     */
    public function contentAt(string $path): ?string
    {
        foreach ($this->files as $id => $file) {
            if ($file['mime'] !== self::FOLDER_MIME && !$this->isTrashed($id) && $this->pathOf($id) === $path) {
                return $file['content'];
            }
        }

        return null;
    }

    /** How many live files carry $name — Drive allows more than one. */
    public function countNamed(string $name): int
    {
        return count(array_filter(
            array_keys($this->files),
            fn (string $id): bool => $this->files[$id]['name'] === $name
                && $this->files[$id]['mime'] !== self::FOLDER_MIME
                && !$this->isTrashed($id)
        ));
    }

    /**
     * The names of every live FILE, wherever it sits.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->files as $id => $file) {
            if ($file['mime'] !== self::FOLDER_MIME && !$this->isTrashed($id)) {
                $names[] = $file['name'];
            }
        }

        return $names;
    }

    /**
     * Every live file as its full path from the top of « Mon Drive »,
     * sorted — what somebody browsing the Drive sees.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->files as $id => $file) {
            if ($file['mime'] !== self::FOLDER_MIME && !$this->isTrashed($id)) {
                $paths[] = $this->pathOf($id);
            }
        }
        sort($paths);

        return $paths;
    }

    /** The id of the live folder at $path from the top of « Mon Drive », or null. */
    public function folderAt(string $path): ?string
    {
        foreach ($this->files as $id => $file) {
            if ($file['mime'] === self::FOLDER_MIME && !$this->isTrashed($id) && $this->pathOf((string) $id) === $path) {
                return (string) $id;
            }
        }

        return null;
    }

    public function isTrashed(string $id): bool
    {
        $seen = 0;
        while (isset($this->files[$id]) && $seen++ < 64) {
            if ($this->files[$id]['trashed']) {
                return true;
            }
            $id = $this->files[$id]['parent'];
        }

        return false;
    }

    public function pathOf(string $id): string
    {
        $names = [];
        $seen = 0;
        while (isset($this->files[$id]) && $seen++ < 64) {
            array_unshift($names, $this->files[$id]['name']);
            $id = $this->files[$id]['parent'];
        }

        return implode('/', $names);
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
            return ['status' => 200, 'body' => (string) json_encode([
                'access_token' => 'ya29.test',
                'refresh_token' => 'refresh-abc',
                'expires_in' => 3599,
            ])];
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

        $id = $this->idIn($url);
        if ($method === 'GET' && str_contains($url, 'alt=media')) {
            return isset($this->files[$id]) && !$this->isTrashed($id)
                ? ['status' => 200, 'body' => $this->files[$id]['content']]
                : ['status' => 404, 'body' => '{"error":{"message":"not found"}}'];
        }
        if ($method === 'GET' && $id !== null) {
            return isset($this->files[$id])
                ? ['status' => 200, 'body' => (string) json_encode([
                    'id' => $id,
                    'name' => $this->files[$id]['name'],
                    'trashed' => $this->isTrashed($id),
                ])]
                : ['status' => 404, 'body' => '{"error":{"message":"not found"}}'];
        }
        if ($method === 'DELETE' && $id !== null) {
            $this->deleteTree($id);

            return ['status' => 204, 'body' => ''];
        }
        if ($method === 'PATCH' && $id !== null) {
            return $this->patch($id, (string) $body);
        }
        if ($method === 'POST') {
            return $this->createFolder((string) $body);
        }

        return $this->listing($url);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function patch(string $id, string $body): array
    {
        if ($this->failPatchesWith !== null) {
            return ['status' => $this->failPatchesWith, 'body' => '{"error":{"message":"refused"}}'];
        }
        if (!isset($this->files[$id])) {
            return ['status' => 404, 'body' => '{"error":{"message":"not found"}}'];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (is_string($decoded['mimeType'] ?? null)) {
                $this->files[$id]['mime'] = $decoded['mimeType'];
            }
            if (is_string($decoded['name'] ?? null)) {
                $this->files[$id]['name'] = $decoded['name'];
            }
            if (($decoded['trashed'] ?? null) === true) {
                $this->files[$id]['trashed'] = true;
            }
        }

        return ['status' => 200, 'body' => (string) json_encode(['id' => $id])];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function createFolder(string $body): array
    {
        $decoded = json_decode($body, true);
        $name = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';
        $parents = is_array($decoded) && is_array($decoded['parents'] ?? null) ? $decoded['parents'] : ['root'];
        $parent = (string) ($parents[0] ?? 'root');
        if ($parent !== 'root' && !isset($this->files[$parent])) {
            return ['status' => 404, 'body' => '{"error":{"message":"parent not found"}}'];
        }

        return ['status' => 200, 'body' => (string) json_encode(['id' => $this->addFolder($name, $parent)])];
    }

    private function deleteTree(string $id): void
    {
        foreach (array_keys($this->files) as $child) {
            if (isset($this->files[$child]) && $this->files[$child]['parent'] === $id) {
                $this->deleteTree((string) $child);
            }
        }
        unset($this->files[$id]);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function listing(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $conditions = self::parseQuery((string) ($query['q'] ?? ''));
        if ($conditions === null) {
            return ['status' => 400, 'body' => '{"error":{"message":"Invalid query"}}'];
        }

        $matches = [];
        foreach ($this->files as $id => $file) {
            $id = (string) $id;
            if (self::matches($id, $file, $conditions, $this->isTrashed($id))) {
                $matches[] = $id;
            }
        }

        usort($matches, fn (string $a, string $b): int => $this->files[$a]['seq'] <=> $this->files[$b]['seq']);
        if (str_contains((string) ($query['orderBy'] ?? ''), 'desc')) {
            $matches = array_reverse($matches);
        }

        $offset = (int) ($query['pageToken'] ?? 0);
        $size = max(1, (int) ($query['pageSize'] ?? 100));
        $page = array_slice($matches, $offset, $size);

        $files = array_map(fn (string $id): array => [
            'id' => $id,
            'name' => $this->files[$id]['name'],
            'mimeType' => $this->files[$id]['mime'],
            'size' => (string) strlen($this->files[$id]['content']),
            'md5Checksum' => md5($this->files[$id]['content']),
            'modifiedTime' => $this->files[$id]['modified'],
        ], $page);

        $answer = ['files' => $files];
        if ($offset + $size < count($matches)) {
            $answer['nextPageToken'] = (string) ($offset + $size);
        }

        return ['status' => 200, 'body' => (string) json_encode($answer)];
    }

    /**
     * @param array{name: string, parent: string, mime: string, content: string, trashed: bool, seq: int,
     *     modified: string} $file
     * @param list<array{0: string, 1: string, 2: string}> $conditions
     */
    private static function matches(string $id, array $file, array $conditions, bool $trashed): bool
    {
        foreach ($conditions as [$field, $operator, $value]) {
            $ok = match ($field) {
                'parents' => $file['parent'] === $value,
                'name' => $file['name'] === $value,
                'mimeType' => $operator === '=' ? $file['mime'] === $value : $file['mime'] !== $value,
                'trashed' => ($value === 'true') === $trashed,
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * The conditions of a Drive query, or null when it does not parse.
     *
     * Walked character by character the way Drive reads it: a backslash
     * inside a literal escapes the character after it, and only an
     * unescaped quote closes. A regular expression over `[^']*` would read
     * an ESCAPED quote as the end of the literal and silently answer a
     * truncated name — a fake that cannot tell a correctly escaped query
     * from a broken one, which is precisely the difference the escaping
     * exists to make. An unterminated literal is a malformed query, and
     * Drive answers an error rather than a best guess: reading it as « no
     * name asked for » would list the whole folder, the answer that makes
     * a namesake sweep delete everything.
     *
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    private static function parseQuery(string $q): ?array
    {
        $conditions = [];
        $length = strlen($q);
        $i = 0;

        while ($i < $length) {
            while ($i < $length && $q[$i] === ' ') {
                $i++;
            }

            if ($q[$i] === "'") {
                $literal = self::readLiteral($q, $i);
                if ($literal === null) {
                    return null;
                }
                if (substr($q, $i, 11) !== ' in parents') {
                    return null;
                }
                $i += 11;
                $conditions[] = ['parents', '=', $literal];
            } elseif (preg_match('/\G(name|mimeType|trashed)(!=|=)/', $q, $m, 0, $i) === 1) {
                $i += strlen($m[0]);
                if ($m[1] === 'trashed') {
                    if (preg_match('/\G(true|false)/', $q, $b, 0, $i) !== 1) {
                        return null;
                    }
                    $i += strlen($b[0]);
                    $conditions[] = ['trashed', '=', $b[1]];
                } else {
                    if (($q[$i] ?? '') !== "'") {
                        return null;
                    }
                    $literal = self::readLiteral($q, $i);
                    if ($literal === null) {
                        return null;
                    }
                    $conditions[] = [$m[1], $m[2], $literal];
                }
            } else {
                return null;
            }

            if ($i >= $length) {
                break;
            }
            if (substr($q, $i, 5) !== ' and ') {
                return null;
            }
            $i += 5;
        }

        return $conditions;
    }

    /** Reads the quoted literal starting at $i, leaving $i past its closing quote. */
    private static function readLiteral(string $q, int &$i): ?string
    {
        $value = '';
        for ($j = $i + 1; $j < strlen($q); $j++) {
            if ($q[$j] === '\\' && $j + 1 < strlen($q)) {
                $value .= $q[++$j];
                continue;
            }
            if ($q[$j] === "'") {
                $i = $j + 1;

                return $value;
            }
            $value .= $q[$j];
        }

        return null;
    }

    /**
     * @return array{status: int, body: string, location?: string}
     */
    private function openSession(string $body): array
    {
        $decoded = json_decode($body, true);
        $name = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';
        $parents = is_array($decoded) && is_array($decoded['parents'] ?? null) ? $decoded['parents'] : ['root'];
        $url = 'https://upload.example/session-' . (count($this->sessions) + 1) . '-' . $this->nextId++;
        $this->sessions[$url] = ['name' => $name, 'parent' => (string) ($parents[0] ?? 'root'), 'total' => 0, 'received' => ''];

        return ['status' => 200, 'body' => '{}', 'location' => $url];
    }

    /**
     * One multipart part's payload — everything past its own headers.
     */
    private static function afterHeaders(string $part): string
    {
        $split = explode("\r\n\r\n", $part, 2);

        return count($split) === 2 ? $split[1] : '';
    }

    /**
     * @return array{status: int, body: string}
     */
    private function multipart(string $body): array
    {
        // Split on the boundary and read the two parts BY POSITION: the
        // metadata first, the bytes second. This application stores JSON
        // objects of its own, so both parts may announce
        // `application/json` and keying on that would lose the content.
        $parts = array_values(array_filter(
            preg_split("/--[^\r\n]+(?:--)?\r\n/", $body) ?: [],
            static fn (string $part): bool => trim($part) !== ''
        ));

        // Decoded as JSON rather than matched with a regular expression,
        // so a name carrying a backslash is filed under the name the
        // client meant.
        $metadata = json_decode(self::afterHeaders($parts[0] ?? ''), true);
        $name = is_array($metadata) && is_string($metadata['name'] ?? null) ? $metadata['name'] : '';
        $parents = is_array($metadata) && is_array($metadata['parents'] ?? null) ? $metadata['parents'] : ['root'];
        $parent = (string) ($parents[0] ?? 'root');
        if ($parent !== 'root' && !isset($this->files[$parent])) {
            return ['status' => 404, 'body' => '{"error":{"message":"parent not found"}}'];
        }
        $content = (string) preg_replace("/\r\n$/", '', self::afterHeaders($parts[1] ?? ''));

        return ['status' => 200, 'body' => (string) json_encode(['id' => $this->put($name, $content, 'application/octet-stream', $parent)])];
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

            if ($held >= (int) $m[1] && $held > 0) {
                return ['status' => 200, 'body' => (string) json_encode(['id' => $this->commit($url)])];
            }

            // **No `Range` at all when the session holds nothing**, which
            // is what Google answers and `bytes=0-0` is not.
            return $held === 0
                ? ['status' => 308, 'body' => '']
                : ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . ($held - 1)];
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

        return $this->put($session['name'], $session['received'], 'application/octet-stream', $session['parent']);
    }

    /** The id in a `/files/{id}` URL, or null for the collection itself. */
    private function idIn(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/files/([^/]+)$#', $path, $m) !== 1) {
            return null;
        }

        return rawurldecode($m[1]);
    }
}
