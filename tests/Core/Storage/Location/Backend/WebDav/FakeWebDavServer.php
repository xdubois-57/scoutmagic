<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\WebDav;

/**
 * A WebDAV share that keeps what it is given.
 *
 * **State rather than expectations, for the reason FakeDrive exists.** A
 * mock asserting « PUT was called once » passes for a backend that writes
 * the same bytes twice under two names; a fake that holds a folder shows
 * the second file sitting in it. Every assertion in the suite that uses
 * this looks at what the share ENDS UP holding.
 *
 * It answers real `PROPFIND` XML, with the `d:` prefix a Nextcloud would
 * use — the parser is tested against the other prefixes separately, so
 * this one only has to be plausible.
 *
 * @phpstan-type WebDavFakeResponse array{status: int, body: string, headers: array<string, string>}
 */
final class FakeWebDavServer
{
    /** @var array<string, string> path => contents */
    public array $files = [];

    /** @var list<string> */
    public array $collections = [];

    /** @var list<array{method: string, url: string}> */
    public array $calls = [];

    /** The Authorization header of the last request, so a test can see credentials travel. */
    public string $lastAuth = '';

    /** When set, every request answers this status instead of doing anything. */
    public int $refuseWith = 0;

    /** When true, the transport itself fails — how an invalid certificate arrives. */
    public bool $connectionFails = false;

    /** Bytes the share says are free, and used. Null for a server with no notion of quotas. */
    public ?int $quotaAvailable = null;

    public ?int $quotaUsed = null;

    /** When true, `GET` ignores `Range:` and answers the whole object with 200. */
    public bool $ignoresRange = false;

    public function __construct(
        public readonly string $baseUrl = 'https://cloud.example.org/dav/scoutmagic'
    ) {
    }

    /**
     * The transport closure {@see \Core\Storage\Location\Backend\WebDav\WebDavClient} takes.
     *
     * @return \Closure(string, string, array<string, string>, ?string): WebDavFakeResponse
     */
    public function transport(): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = ['method' => $method, 'url' => $url];
            $this->lastAuth = $headers['Authorization'] ?? '';

            if ($this->connectionFails) {
                throw new \RuntimeException('SSL certificate problem: unable to get local issuer certificate');
            }
            if ($this->refuseWith !== 0) {
                return ['status' => $this->refuseWith, 'body' => '', 'headers' => []];
            }

            $path = $this->pathOf($url);

            return match ($method) {
                'PUT' => $this->put($path, (string) $body),
                'GET' => $this->get($path, $headers['Range'] ?? null),
                'DELETE' => $this->delete($path),
                'MKCOL' => $this->makeCollection($path),
                'PROPFIND' => $this->propfind($path, (int) ($headers['Depth'] ?? 0)),
                default => ['status' => 405, 'body' => '', 'headers' => []],
            };
        };
    }

    /** @return WebDavFakeResponse */
    private function put(string $path, string $body): array
    {
        $existed = isset($this->files[$path]);
        $this->files[$path] = $body;

        return ['status' => $existed ? 204 : 201, 'body' => '', 'headers' => []];
    }

    /** @return WebDavFakeResponse */
    private function get(string $path, ?string $range): array
    {
        if (!isset($this->files[$path])) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        $contents = $this->files[$path];
        if ($range === null || $this->ignoresRange) {
            return ['status' => 200, 'body' => $contents, 'headers' => []];
        }

        preg_match('/bytes=(\d+)-(\d+)/', $range, $matches);
        $from = (int) $matches[1];
        $to = (int) $matches[2];

        return [
            'status' => 206,
            'body' => substr($contents, $from, $to - $from + 1),
            'headers' => [],
        ];
    }

    /** @return WebDavFakeResponse */
    private function delete(string $path): array
    {
        $removed = false;
        foreach (array_keys($this->files) as $known) {
            // A DELETE on a collection removes everything under it.
            if ($known === $path || str_starts_with($known, $path . '/')) {
                unset($this->files[$known]);
                $removed = true;
            }
        }
        $this->collections = array_values(array_filter(
            $this->collections,
            static fn (string $c): bool => $c !== $path && !str_starts_with($c, $path . '/')
        ));

        return ['status' => $removed ? 204 : 404, 'body' => '', 'headers' => []];
    }

    /** @return WebDavFakeResponse */
    private function makeCollection(string $path): array
    {
        if (in_array($path, $this->collections, true)) {
            return ['status' => 405, 'body' => '', 'headers' => []];
        }
        $this->collections[] = $path;

        return ['status' => 201, 'body' => '', 'headers' => []];
    }

    /** @return WebDavFakeResponse */
    private function propfind(string $path, int $depth): array
    {
        $isRoot = $path === $this->pathOf($this->baseUrl);
        if (!$isRoot && !isset($this->files[$path]) && !in_array($path, $this->collections, true)) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        $entries = '';
        if ($depth === 0) {
            $entries = isset($this->files[$path])
                ? $this->fileEntry($path)
                : $this->collectionEntry($path);
        } else {
            $entries = $this->collectionEntry($path);
            foreach ($this->files as $known => $contents) {
                if (str_starts_with($known, rtrim($path, '/') . '/')) {
                    $entries .= $this->fileEntry($known);
                }
            }
        }

        return [
            'status' => 207,
            'body' => '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">' . $entries . '</d:multistatus>',
            'headers' => [],
        ];
    }

    private function fileEntry(string $path): string
    {
        $contents = $this->files[$path] ?? '';

        return '<d:response><d:href>' . self::encodeHref($path) . '</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status><d:prop>'
            . '<d:getcontentlength>' . strlen($contents) . '</d:getcontentlength>'
            . '<d:getetag>"' . md5($contents) . '"</d:getetag>'
            . '<d:getlastmodified>Mon, 03 Mar 2026 12:00:00 GMT</d:getlastmodified>'
            . '</d:prop></d:propstat></d:response>';
    }

    private function collectionEntry(string $path): string
    {
        $quota = '';
        if ($this->quotaAvailable !== null) {
            $quota .= '<d:quota-available-bytes>' . $this->quotaAvailable . '</d:quota-available-bytes>';
        }
        if ($this->quotaUsed !== null) {
            $quota .= '<d:quota-used-bytes>' . $this->quotaUsed . '</d:quota-used-bytes>';
        }

        return '<d:response><d:href>' . self::encodeHref($path) . '</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status><d:prop>'
            . '<d:resourcetype><d:collection/></d:resourcetype>' . $quota
            . '</d:prop></d:propstat></d:response>';
    }

    /** The path a URL points at, which is what this fake keys everything on. */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return rawurldecode(is_string($path) ? $path : $url);
    }

    /** A real server percent-encodes each segment of an href; so does this one. */
    private static function encodeHref(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path)
        ));
    }
}
