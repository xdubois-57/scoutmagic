<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\WebDav\WebDavAccessException;
use Core\Storage\Location\Backend\WebDav\WebDavClient;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StorageQuota;
use Core\Storage\Location\StoredObject;

/**
 * A WebDAV share as a storage location.
 *
 * **The one that proves the abstraction was worth building**, and the one
 * an ordinary unit gets the most from: an address, a username and a
 * password point this at a Nextcloud, a kDrive, a Hetzner Storage Box or
 * a Koofr, with no application to register anywhere.
 *
 * **It can seek, which Drive cannot.** A `GET` carrying `Range:` returns a
 * slice, so a film plays from here and a viewer can jump into the middle
 * of one. That is the difference between a gallery that holds videos and
 * one that merely stores them.
 *
 * **It cannot hand a visitor a URL**, which is where the real work of this
 * iteration lives. {@see directUrl()} answers null, exactly as the local
 * disk does, and the consumer then serves the object through its own
 * access-controlled route — the path that already exists rather than a
 * second one invented here. Every byte therefore passes through PHP,
 * which is what the location's own card says in as many words.
 */
final class WebDavBackend implements RangeReadableBackend, QuotaReportingBackend
{
    /**
     * How many entries one listing page asks for.
     *
     * `PROPFIND` has no cursor of its own: `Depth: 1` returns a whole
     * collection in one answer, so this is a ceiling applied after the
     * fact rather than a page size the server honours. A folder with more
     * children than this is reported truncated rather than silently cut.
     */
    private const LIST_CEILING = 5000;

    public function __construct(
        private readonly WebDavClient $client,
        private readonly WebDavLocationConfig $config,
        private readonly string $password
    ) {
    }

    /** @return list<StorageCapability> */
    public static function declaredCapabilities(): array
    {
        return [
            StorageCapability::RangeRead,
            StorageCapability::Quota,
        ];
    }

    /** @return list<StorageCapability> */
    public function capabilities(): array
    {
        return self::declaredCapabilities();
    }

    public function supports(StorageCapability $capability): bool
    {
        return in_array($capability, self::declaredCapabilities(), true);
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        // **The collections first, and every level of them.** WebDAV has
        // no « create intermediate folders » flag: a PUT into a folder
        // that is not there answers 409, and the gallery's keys are
        // `{albumId}/med_{mediaId}.jpg`, so at least one level is created
        // on the first write of every album.
        $this->ensureCollectionsFor($key);

        $this->client->put($this->urlFor($key), $this->auth(), $contents, $mimeType);
    }

    public function get(string $key): string
    {
        return $this->client->get($this->urlFor($key), $this->auth());
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        return $this->client->getRange($this->urlFor($key), $this->auth(), $offset, $length);
    }

    public function size(string $key): ?int
    {
        $resource = $this->describe($key);

        return $resource?->contentLength;
    }

    public function exists(string $key): bool
    {
        return $this->describe($key) !== null;
    }

    public function delete(string $key): void
    {
        $this->client->delete($this->urlFor($key), $this->auth());
    }

    public function deletePrefix(string $prefix): void
    {
        $prefix = trim($prefix, '/');
        // An empty prefix would resolve to the share's own root and wipe
        // everything the operator keeps there — including files that have
        // nothing to do with this site. Callers pass "{albumId}".
        if ($prefix === '') {
            return;
        }

        // DELETE on a collection removes it and everything under it, which
        // is one request rather than one per object. A collection that is
        // not there answers 404, which the client reads as success.
        $this->client->delete($this->urlFor($prefix), $this->auth());
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $root = $this->urlFor(trim($prefix, '/'));

        try {
            $resources = $this->client->propfind($root, $this->auth(), 1);
        } catch (WebDavAccessException) {
            // A collection that is not there is an empty listing, not a
            // failure: every caller derives its prefix from something that
            // can be older than the share.
            return new StorageListing([]);
        }

        $base = $this->collectionPath();
        $objects = [];
        foreach ($resources as $resource) {
            if ($resource->isCollection) {
                continue;
            }
            $key = self::keyFrom($resource->href, $base);
            if ($key === null || ($prefix !== '' && !str_starts_with($key, trim($prefix, '/')))) {
                continue;
            }
            $objects[] = new StoredObject(
                $key,
                $resource->contentLength,
                $resource->etag,
                $resource->lastModified
            );
            if (count($objects) >= min($limit, self::LIST_CEILING)) {
                break;
            }
        }

        return new StorageListing($objects);
    }

    /**
     * Null: the bytes are not on this server's disk. A caller moving a
     * large object reads it in slices through {@see getRange()} instead.
     */
    public function localPath(string $key): ?string
    {
        return null;
    }

    /**
     * Null, and deliberately: a WebDAV share answers nobody without the
     * credentials this site holds, so there is no URL to hand a visitor.
     * The consumer serves the object through its own route — the same
     * answer the local disk gives, and the same path it takes.
     */
    public function directUrl(string $key, string $ttl = '+1 hour'): ?string
    {
        return null;
    }

    public function stableDirectUrl(string $key): ?string
    {
        return null;
    }

    /**
     * The `getetag`, but only where it is an MD5 of the content.
     *
     * {@see \Core\Storage\Location\Backend\WebDav\WebDavResource} decides
     * that, and answers null everywhere else: an etag is an opaque
     * validator, and `mod_dav` writes inode-size-mtime into it. A
     * verification comparing a file against that would report corruption
     * on a file that is intact.
     */
    public function announcedChecksum(string $key): ?string
    {
        return $this->describe($key)?->etag;
    }

    /**
     * Asks the share whether it is reachable, and says what is wrong in
     * French when it is not — null when all is well.
     *
     * The three failures the operator actually meets are told apart by
     * {@see WebDavClient}: a wrong password, a path that is not there,
     * and a certificate this server will not verify. A single « the share
     * refused » would send somebody to check their password over a folder
     * they mistyped.
     */
    public function testConnection(): ?string
    {
        if ($this->config->baseUrl === '') {
            return 'Aucune adresse de partage n\'est enregistrée pour cet emplacement.';
        }

        try {
            $this->client->propfind($this->config->baseUrl, $this->auth(), 0);
        } catch (WebDavAccessException $e) {
            // Already a French sentence naming the remedy — that is what
            // marks this exception as user-facing.
            return $e->getMessage();
        } catch (\RuntimeException $e) {
            // **Anything else the transport raised.** This method promises
            // « a French sentence or null » and is called from a
            // configuration page, so a failure it did not anticipate must
            // not escape as a fatal on the screen an operator opened to
            // find out what is wrong. A fresh sentence rather than
            // `$e->getMessage()`: re-labelling a library's English words
            // as user-facing is the laundering `UserFacingException`
            // exists to forbid, and the cause carries them to the journal.
            return 'Le partage n\'a pas pu être contacté. Vérifiez l\'adresse, et que son certificat est '
                . 'valide et reconnu par ce serveur.';
        }

        return null;
    }

    /**
     * What the share says is left, or null when it will not say.
     *
     * RFC 4331 asks the collection itself, with `Depth: 0`. A server with
     * no notion of quotas omits the properties, and an account with no
     * limit answers a negative sentinel; both arrive here as null, which
     * is « unknown » and never « none ».
     */
    public function quota(): ?StorageQuota
    {
        $resources = $this->client->propfind($this->config->baseUrl, $this->auth(), 0);
        $collection = $resources[0] ?? null;
        if ($collection === null) {
            return null;
        }

        $available = $collection->quotaAvailableBytes;
        $used = $collection->quotaUsedBytes;
        if ($available === null || $used === null) {
            return null;
        }

        return new StorageQuota($used, $used + $available);
    }

    /**
     * One `PROPFIND` at depth 0, or null when the object is not there.
     *
     * A refusal is read as absence on purpose: every caller of
     * {@see exists()} and {@see size()} is asking a question that has
     * « no » as an ordinary answer, and turning a 404 into an exception
     * would make each of them wrap this call.
     */
    private function describe(string $key): ?\Core\Storage\Location\Backend\WebDav\WebDavResource
    {
        try {
            $resources = $this->client->propfind($this->urlFor($key), $this->auth(), 0);
        } catch (WebDavAccessException) {
            return null;
        }

        return $resources[0] ?? null;
    }

    /**
     * Creates every collection a key needs, from the outside in.
     *
     * Each level is asked for separately because `MKCOL` makes exactly
     * one: a server answers 409 for a collection whose parent is missing,
     * so creating `a/b/c` bottom-up fails on the first call.
     */
    private function ensureCollectionsFor(string $key): void
    {
        $segments = array_values(array_filter(explode('/', trim($key, '/')), static fn (string $s): bool => $s !== ''));
        array_pop($segments);

        $path = '';
        foreach ($segments as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $this->client->makeCollection($this->urlFor($path), $this->auth());
        }
    }

    /**
     * The full URL of a key.
     *
     * Each segment is encoded on its own: `rawurlencode()` on the whole
     * key would turn the separating slashes into `%2F`, and a share would
     * then hold one file literally named `12/med_3.jpg` instead of a
     * folder holding a file.
     */
    private function urlFor(string $key): string
    {
        $trimmed = trim($key, '/');
        if ($trimmed === '') {
            return $this->config->baseUrl;
        }

        $encoded = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $trimmed)
        );

        return $this->config->baseUrl . '/' . implode('/', $encoded);
    }

    /**
     * Basic authentication, built here rather than handed to cURL's own
     * `CURLOPT_USERPWD`: the transport is injectable, and a test double
     * that never sees the header could not assert that credentials travel
     * at all.
     */
    private function auth(): string
    {
        return 'Basic ' . base64_encode($this->config->username . ':' . $this->password);
    }

    /** The path part of the configured collection, for turning hrefs back into keys. */
    private function collectionPath(): string
    {
        $path = parse_url($this->config->baseUrl, PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * An `href` turned back into a key relative to the collection.
     *
     * **A server may answer an absolute URL or an absolute path**, and
     * both are legal. Null for anything outside the collection, which a
     * misconfigured proxy can produce and which must not become a key
     * this site believes it owns.
     */
    private static function keyFrom(string $href, string $base): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;

        if ($base !== '' && !str_starts_with($path, $base . '/')) {
            return null;
        }

        $key = trim(substr($path, strlen($base)), '/');

        return $key === '' ? null : $key;
    }
}
