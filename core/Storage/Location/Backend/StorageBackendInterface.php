<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageListing;

/**
 * Where bytes live, for any consumer in the application — the gallery's
 * renditions today, an off-site backup and a location's own safety copy
 * next.
 *
 * **The floor is small on purpose.** Put, get, size, exists, delete, list
 * and a connection test are what every kind of storage can do: a
 * directory, a bucket, a WebDAV share, a Drive account. Everything beyond
 * that — seeking inside a file, handing the visitor a URL the storage
 * serves itself, resuming an interrupted upload, answering how much room
 * is left — varies, is DECLARED through {@see capabilities()}, and is
 * asked for through {@see \Core\Storage\Location\StorageCapabilities::
 * require()} so that its absence is a French refusal rather than a fatal.
 *
 * An earlier version of this interface put every one of those on the floor
 * and let the backends that could not do them throw; the result was that
 * the screen had no way to say, before an administrator committed to a
 * destination, that videos would not play on it.
 *
 * `$key` is always a relative key such as `"{albumId}/med_{mediaId}.jpg"`
 * — never an absolute path, never a URL. What it is relative to is the
 * location's own root, and only the backend knows where that is.
 */
interface StorageBackendInterface
{
    /**
     * What this backend can do beyond the floor. Static per backend class
     * (see {@see declaredCapabilities()} on each implementation) so a
     * comparison screen can answer for a KIND of storage without holding
     * credentials for one.
     *
     * @return list<StorageCapability>
     */
    public function capabilities(): array;

    public function supports(StorageCapability $capability): bool;

    public function put(string $key, string $contents, string $mimeType): void;

    /**
     * The whole object, in memory.
     *
     * Fine for a thumbnail, wrong for a film: a caller moving large
     * objects reads them in slices instead — {@see localPath()} when the
     * bytes are already on this disk, {@see RangeReadableBackend::
     * getRange()} when the backend can seek.
     *
     * @throws \RuntimeException when $key cannot be read at all
     */
    public function get(string $key): string;

    /**
     * Byte count, or null when it cannot be determined (missing object,
     * backend error) — so a caller can serve an HTTP range or refuse an
     * over-large operation without ever reading the whole object first.
     */
    public function size(string $key): ?int;

    public function exists(string $key): bool;

    /**
     * Removes $key.
     *
     * **A key that is not there is a success, not an error.** Every
     * mechanism that deletes derives its list of keys from something that
     * can be older than the storage — an inventory, a database restored to
     * last week — so « already gone » is the ordinary case and the desired
     * end state either way. A backend that raised here would make every
     * pass following a restore fail on ghosts.
     */
    public function delete(string $key): void;

    /**
     * Removes every object inside the FOLDER $prefix — that is, every key
     * starting with `rtrim($prefix, '/') . '/'`, and nothing else. Used
     * for whole album cleanup; an empty prefix is a no-op, never
     * « everything », and neither is one that trims to nothing.
     *
     * **A folder, and deliberately not « keys beginning with this
     * string ».** Callers pass an album id with no trailing slash, so the
     * looser reading makes `deletePrefix('5')` delete album 50 as well —
     * which is what `GoogleDriveBackend` did, destroying the files of
     * albums nobody had touched (#484). The distinction is invisible
     * until a site has an album whose id is a prefix of another's, which
     * is every site with ten albums, and the damage is silent: the rows
     * remain and the photographs become 404s.
     *
     * This says folder where {@see list()} says prefix, and the two
     * differ on purpose: `list()` is asked for things that are not
     * folders (`StorageInventoryStore`'s reserved prefix), while nothing
     * ever wants to delete « every key beginning with these characters ».
     * `Tests\Core\Storage\Location\Backend\DeletePrefixIsAFolderTest`
     * holds all four backends to it at once, so a fifth cannot diverge in
     * silence.
     */
    public function deletePrefix(string $prefix): void;

    /**
     * One page of the objects under $prefix.
     *
     * Paged rather than exhaustive: the caller that needs all of them runs
     * under a time budget and must be able to stop and resume. $cursor is
     * whatever the previous page returned, and is opaque to the caller.
     */
    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing;

    /**
     * Absolute filesystem path of $key when this backend keeps it as a
     * local file, null when it does not. Lets a caller stream a large
     * local object straight off the disk instead of buffering it whole.
     */
    public function localPath(string $key): ?string;

    /**
     * A URL the STORAGE ITSELF serves $key from, so the bytes never pass
     * through this server — or **null when it cannot do that**, which is
     * the answer for the local disk and for every destination without
     * {@see StorageCapability::SignedUrl}.
     *
     * Null is not a failure and never was: a consumer that gets it serves
     * the object through its own access-controlled route, exactly as the
     * gallery has always done for local media. Making that the shape of
     * the return value is what stops each new backend without signed URLs
     * from inventing a second serving path of its own — there is one, it
     * belongs to the consumer, and it is used whenever this returns null.
     *
     * $ttl is a pre-signing expiry (« +1 hour », « +5 minutes »),
     * meaningful only where a URL is minted per request. A short-lived
     * grant is minted fresh on purpose and never stored or logged.
     */
    public function directUrl(string $key, string $ttl = '+1 hour'): ?string;

    /**
     * The same, but IDENTICAL across renders within a time window, so a
     * browser can actually cache what it points at — null under the same
     * condition as {@see directUrl()}.
     *
     * A pre-signed URL embeds its signing time, so two mints a second
     * apart are two different URLs, which made every page view a fresh
     * cache key and re-downloaded every thumbnail, for ever. Anything a
     * page embeds for every image uses this; a deliberately short-lived
     * grant uses {@see directUrl()} with its own TTL.
     */
    public function stableDirectUrl(string $key): ?string;

    /**
     * The checksum this storage announces for $key, when it announces one
     * that can be compared with an MD5 computed while reading the source —
     * null otherwise, and null is the common answer.
     *
     * **The trap this signature exists for**: S3's ETag equals the MD5
     * only for an object uploaded in one piece. For a multipart upload it
     * is a digest of digests with the part count appended, and comparing
     * that to an MD5 fails every single time — which would have somebody
     * conclude that every copy they own is corrupt. A backend that cannot
     * tell the two apart returns null here, and the caller falls back on
     * comparing sizes, which is the honest answer.
     */
    public function announcedChecksum(string $key): ?string;

    /**
     * Actually exercises this location: not « is it configured » but « can
     * this site write to it, read back what it wrote, and remove it
     * again ».
     *
     * @return string|null null when the location is reachable, otherwise a
     *                     FRENCH sentence naming the operation that failed
     *                     and, where the service said so, why. Never a
     *                     driver's own English, and never a path: this
     *                     string is rendered on a configuration page.
     */
    public function testConnection(): ?string;
}
