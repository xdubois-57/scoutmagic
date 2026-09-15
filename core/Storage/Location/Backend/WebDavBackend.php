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

    /**
     * How deep the walk below a prefix may go. The keys this application
     * writes are one level deep (`{albumId}/med_{mediaId}.jpg`) and the
     * inventory's are two; anything past this is a share answering
     * something other than its own contents.
     */
    private const WALK_DEPTH_CEILING = 8;

    /**
     * Collections this instance has already made sure of.
     *
     * **One MKCOL per album, not one per file.** Processing a photograph
     * writes three renditions under the same `{albumId}`, and the factory
     * hands the same backend instance out for the whole request, so
     * without this each write spent a round trip on a folder the previous
     * one had just created — answered `405`, on the ADSL link this type
     * exists to be usable over. {@see put()} empties it and starts again
     * when a write reports the parent gone, so a collection removed on the
     * share mid-request costs one retry rather than a broken instance.
     *
     * @var array<string, true>
     */
    private array $knownCollections = [];

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

        try {
            $this->client->put($this->urlFor($key), $this->auth(), $contents, $mimeType);
        } catch (WebDavAccessException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }

            // **The missing parent the cache promised was there.** The
            // folder was created earlier in this request and has gone
            // since — somebody tidying the share, another site writing
            // into it. Forget what was remembered, make the collections
            // again, and write once more; a second failure is real.
            $this->knownCollections = [];
            $this->ensureCollectionsFor($key);
            $this->client->put($this->urlFor($key), $this->auth(), $contents, $mimeType);
        }
    }

    public function get(string $key): string
    {
        return $this->client->get($this->urlFor($key), $this->auth());
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        return $this->client->getRange($this->urlFor($key), $this->auth(), $offset, $length);
    }

    /**
     * **The one reader that still answers null for a share in trouble.**
     * `StorageBackendInterface` lets this return null for a backend error,
     * and its caller in the gallery is a `Range:` request that falls
     * through to an ordinary full read on null — where a throw would turn
     * an outage into a 500 on a visitor's page instead of a 404.
     */
    public function size(string $key): ?int
    {
        try {
            return $this->describe($key)?->contentLength;
        } catch (WebDavAccessException) {
            return null;
        }
    }

    /** @throws WebDavAccessException when the share cannot be asked at all */
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

    /**
     * Every object under $prefix, one page at a time.
     *
     * **Two things WebDAV does not give and this has to build.**
     *
     * `PROPFIND` at `Depth: 1` answers one collection's direct children
     * and nothing below them, and `Depth: infinity` is refused outright by
     * Nextcloud and most of its peers. The gallery's keys are
     * `{albumId}/med_{mediaId}.jpg`, so a single depth-1 call on the share
     * root sees album FOLDERS and not one media file — a safety copy
     * reading it would have copied nothing and reported success. So the
     * tree is walked, one request per collection.
     *
     * And there is no cursor in the protocol, so the cursor here is the
     * last key already handed out and resuming means walking again and
     * skipping past it. That costs a re-walk per page, which is the price
     * of a listing that can be interrupted at all; `ProtectionPass` reads
     * a page, does the work, and comes back — it cannot hold the whole
     * share in memory, and neither can this.
     */
    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $prefix = trim($prefix, '/');
        $ceiling = max(1, min($limit, self::LIST_CEILING));

        $keys = [];
        try {
            $this->walk($prefix, $this->collectionPath(), $keys, 0, $cursor, $ceiling);
        } catch (WebDavAccessException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }

            // A collection that is not there is an empty listing, not a
            // failure: every caller derives its prefix from something that
            // can be older than the share.
            return new StorageListing([]);
        }

        // Sorted so that « after this key » is a stable instruction: the
        // order a server returns children in is its own business, and a
        // cursor against an unstable order skips files or repeats them.
        self::keepTheSmallest($keys, $ceiling + 1);

        $truncated = count($keys) > $ceiling;
        $page = array_values(array_slice($keys, 0, $ceiling));

        // **A truncated page says so.** `StorageListing::isComplete()`
        // reads a null cursor as « that was everything », so returning one
        // here would stop a copy after the first page and call it done.
        return new StorageListing(
            $page,
            $truncated && $page !== [] ? $page[count($page) - 1]->key : null
        );
    }

    /**
     * Sorts what has been collected and keeps the $wanted smallest keys.
     *
     * **This is what makes the walk bounded without making it lie.** The
     * walk used to stop dead once it held `LIST_CEILING` keys and return
     * silently, and because each page re-walks from scratch, every page
     * drew from the same first five thousand keys: once the cursor had
     * passed all of them the next walk found nothing new, the page came
     * back empty with no cursor, and `isComplete()` read that as « the
     * whole share, seen ». `ProtectionPass` would then have treated
     * everything past the five-thousandth object as gone from the source
     * and deleted its backup copies.
     *
     * Pruning to the smallest keys instead is exactly as bounded and
     * cannot silently drop anything: whatever is discarded here is larger
     * than the page being built, so it belongs to a later page and a
     * later walk — which starts after this page's last key — will find it.
     *
     * @param array<string, StoredObject> $keys
     */
    private static function keepTheSmallest(array &$keys, int $wanted): void
    {
        ksort($keys, SORT_STRING);
        if (count($keys) > $wanted) {
            $keys = array_slice($keys, 0, $wanted, true);
        }
    }

    /**
     * Collects every object under one collection, recursively, keeping
     * only what could still belong to the page being built.
     *
     * @param array<string, StoredObject> $keys keyed by key, so a server
     *        that lists an entry twice yields one object
     * @param null|string $cursor the last key already handed out; anything
     *        at or before it belongs to a page that is already gone
     * @param int $ceiling how many keys the page being built wants
     * @throws WebDavAccessException
     */
    private function walk(
        string $prefix,
        string $base,
        array &$keys,
        int $depth,
        ?string $cursor,
        int $ceiling
    ): void {
        // The depth cap is a guard and not a feature: a share that answers
        // its own path as a child — a misconfigured proxy, a symlink loop
        // — would otherwise walk for ever on a page somebody is waiting on.
        if ($depth > self::WALK_DEPTH_CEILING) {
            return;
        }

        $resources = $this->client->propfind($this->urlFor($prefix), $this->auth(), 1);
        foreach ($resources as $resource) {
            $key = self::keyFrom($resource->href, $base);
            if ($key === null || $key === $prefix) {
                // The collection describes itself in its own answer; only
                // its children are of interest.
                continue;
            }
            if ($prefix !== '' && !str_starts_with($key, $prefix . '/')) {
                continue;
            }

            if ($resource->isCollection) {
                $this->walk($key, $base, $keys, $depth + 1, $cursor, $ceiling);
                continue;
            }

            if ($cursor !== null && strcmp($key, $cursor) <= 0) {
                continue;
            }

            $keys[$key] = new StoredObject(
                $key,
                $resource->contentLength,
                $resource->contentMd5,
                $resource->lastModified
            );

            // Pruned as we go rather than at the end, so the whole share
            // is never held at once — the point of paging at all. Twice
            // the page is the slack that keeps the sort from running on
            // every single entry.
            if (count($keys) > 2 * ($ceiling + 1)) {
                self::keepTheSmallest($keys, $ceiling + 1);
            }
        }
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
     * A digest of the content when the share states one as a digest —
     * `<oc:checksums>` — and null everywhere else.
     *
     * **Never the `getetag`.** An etag is an opaque validator, and
     * Nextcloud's is `md5(mtime . inode . dev . size)`: 32 hexadecimal
     * characters that describe an inode. A verification comparing a file
     * against that finds a mismatch on every file, deletes the copy it has
     * just made, and reports the album as corrupt.
     * {@see \Core\Storage\Location\Backend\WebDav\WebDavResource} is
     * where that decision lives.
     */
    public function announcedChecksum(string $key): ?string
    {
        // Not wrapped, unlike size(): null here means « this destination
        // says nothing comparable », and a verification reads that as
        // « skip the comparison ». A share that is down would then have
        // every file pass unverified.
        return $this->describe($key)?->contentMd5;
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
            $this->proveItCanServeASlice();
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
     * Writes a witness file, reads a few bytes out of the middle of it,
     * and removes it.
     *
     * **Because `PROPFIND` answering is not the promise this type makes.**
     * `StorageLocationType::capabilities()` declares `RangeRead` for every
     * WebDAV location, and the Emplacements screen turns that into
     * « Vidéos : oui — une vidéo se lit, et on peut avancer dedans ». A
     * server can answer `PROPFIND` perfectly and ignore `Range:`, and an
     * administrator who read that sentence would find out on the evening a
     * parent tries to skip to the end of the camp film. The capability is
     * checked where the sentence is earned, at declaration time.
     *
     * The witness is removed whatever happens, including when the read
     * refuses: leaving files behind on somebody's cloud is not something a
     * connection test may do.
     *
     * @throws WebDavAccessException
     */
    private function proveItCanServeASlice(): void
    {
        $key = self::WITNESS_PREFIX . bin2hex(random_bytes(8));
        $contents = str_repeat('scoutmagic', 16);

        $this->client->put($this->urlFor($key), $this->auth(), $contents, 'application/octet-stream');

        try {
            $slice = $this->client->getRange($this->urlFor($key), $this->auth(), 10, 10);
        } finally {
            $this->client->delete($this->urlFor($key), $this->auth());
        }

        if ($slice !== substr($contents, 10, 10)) {
            throw WebDavAccessException::of(
                'Ce partage n\'a pas renvoyé l\'extrait de fichier demandé : les vidéos n\'y seraient pas '
                . 'parcourables.'
            );
        }
    }

    /**
     * The witness file's name. Prefixed and random: a share is somebody's
     * own folder, and a test that collided with a file already there would
     * overwrite it and then delete it.
     */
    private const WITNESS_PREFIX = '.scoutmagic-test-';

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
     * **Absence is null; everything else is raised.** This used to catch
     * every `WebDavAccessException` and answer null, which reads the same
     * as « not there » for a share that is refusing the password, out of
     * space, or simply down. The callers act on that answer: a
     * repatriation concludes the source lost a file it still holds, and a
     * safety copy re-sends objects that are sitting there intact. A
     * failure that is not an absence has to reach them as a failure.
     *
     * @throws WebDavAccessException
     */
    private function describe(string $key): ?\Core\Storage\Location\Backend\WebDav\WebDavResource
    {
        try {
            $resources = $this->client->propfind($this->urlFor($key), $this->auth(), 0);
        } catch (WebDavAccessException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
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
        $segments = array_values(array_filter(
            explode('/', trim($key, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));
        array_pop($segments);

        $path = '';
        foreach ($segments as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            if (isset($this->knownCollections[$path])) {
                continue;
            }

            $this->client->makeCollection($this->urlFor($path), $this->auth());
            $this->knownCollections[$path] = true;
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

    /**
     * The path part of the configured collection, for turning hrefs back
     * into keys — **decoded, because that is how the hrefs arrive.**
     *
     * {@see WebDavResource} runs `rawurldecode()` over every `href`, so a
     * base left percent-encoded matches none of them. A Nextcloud address
     * carrying an account name with a space —
     * `…/dav/files/marie%20dupont/scoutmagic` — would make every listing
     * come back empty while writes and reads went on working, which is
     * the shape of failure that gets discovered by a migration that
     * copied nothing and said it was done.
     */
    private function collectionPath(): string
    {
        $path = parse_url($this->config->baseUrl, PHP_URL_PATH);

        return is_string($path) ? rtrim(rawurldecode($path), '/') : '';
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
