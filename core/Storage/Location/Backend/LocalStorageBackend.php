<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StoredObject;

/**
 * A directory on a filesystem this server can see.
 *
 * Normally under `storage/` — outside the webroot, like every other upload
 * in this application (ARCHITECTURE.md §8.3) — and served back to a
 * browser only through the consumer's own access-controlled route, never a
 * direct path, which is why {@see directUrl()} answers null.
 *
 * `$baseDirectory` is already resolved when it arrives here: relative or
 * absolute is a question
 * {@see \Core\Storage\Location\Backend\StorageBackendFactory} settles once,
 * so this class only ever sees one absolute directory and the rest of the
 * codebase never has to know the rule.
 */
class LocalStorageBackend implements RangeReadableBackend, ResumableUploadBackend, ServerSideCopyBackend
{
    /**
     * Both of these are true of any filesystem and neither of them costs
     * anything: `copy()` never leaves the disk, and reading a slice is
     * what `file_get_contents()`'s offset argument does. What is
     * deliberately ABSENT is as informative: no signed URL (the bytes go
     * out through PHP), no announced checksum (the file is right there —
     * a caller that wants one hashes it), and no quota, because the room
     * left on a directory is a question about a VOLUME, answered by
     * `Core\Storage\DiskBudget`, and a per-location answer would report
     * the same free space three times for three folders on one disk.
     *
     * @return list<StorageCapability>
     */
    public static function declaredCapabilities(): array
    {
        return [
            StorageCapability::RangeRead,
            StorageCapability::ResumableUpload,
            StorageCapability::ServerSideCopy,
        ];
    }

    /**
     * How long the whole health check may take before it gives up and
     * says so. Seconds, and a small number of them on purpose: this runs
     * synchronously on a configuration page, and a page an administrator
     * opened to repair a broken mount is the last page that may hang on
     * it.
     *
     * Five, not one: an ordinary local directory answers in microseconds,
     * so the budget is never near — while a mount that is merely slow,
     * rather than dead, deserves the chance to answer before being called
     * broken.
     */
    public const HEALTH_CHECK_BUDGET_SECONDS = 5.0;

    public function __construct(
        private string $baseDirectory,
        /**
         * Overridable so a test can shrink the budget rather than spend
         * it, and so a caller that knows it is on a background pass — with
         * nobody watching a page — can afford to wait longer.
         */
        private float $healthCheckBudgetSeconds = self::HEALTH_CHECK_BUDGET_SECONDS
    ) {
    }

    public function capabilities(): array
    {
        return self::declaredCapabilities();
    }

    public function supports(StorageCapability $capability): bool
    {
        return in_array($capability, self::declaredCapabilities(), true);
    }

    /**
     * A write that failed must say so.
     *
     * `mkdir()` and `file_put_contents()` return false rather than
     * raising, and ignoring that made this method report success for
     * bytes that never landed — a full disk, a read-only mount, a
     * directory the web user cannot enter. The caller then records a
     * media as processed, or a copy as made, over nothing at all. This is
     * the one failure whose whole cost is that it is silent.
     */
    /**
     * The suffix an interrupted upload is stored under.
     *
     * **Chosen so that it can never be mistaken for content.** A consumer
     * listing this location while a copy is in flight must not meet half a
     * photograph under the name of a whole one — half a JPEG is a JPEG as
     * far as every screen is concerned, and it would be served, copied
     * onward and recorded as protected.
     */
    private const PARTIAL_SUFFIX = '.scoutmagic-part';

    public function partialSize(string $key): int
    {
        $path = $this->fullPath($key . self::PARTIAL_SUFFIX);
        if (!is_file($path)) {
            return 0;
        }

        // `clearstatcache` because this is read immediately after the
        // append that grew the file, inside one request: PHP caches the
        // stat, and a cached size is an offset that re-sends bytes already
        // stored or — worse, on a shrinking file — skips bytes that are
        // not.
        clearstatcache(true, $path);
        $size = @filesize($path);

        return is_int($size) ? $size : 0;
    }

    public function appendToPartial(string $key, string $chunk): void
    {
        $path = $this->fullPath($key . self::PARTIAL_SUFFIX);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage directory could not be created for: {$key}");
        }

        // FILE_APPEND with LOCK_EX rather than an open handle held across
        // calls: a pass writes a chunk, may hand control back to the
        // scheduler, and comes back in another process entirely. There is
        // no handle to keep.
        $written = @file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX);
        if ($written === false || $written !== strlen($chunk)) {
            throw new \RuntimeException("Partial upload could not be extended: {$key}");
        }
    }

    public function promotePartial(string $key, string $mimeType): void
    {
        $partial = $this->fullPath($key . self::PARTIAL_SUFFIX);
        if (!is_file($partial)) {
            throw new \RuntimeException("No partial upload to promote for: {$key}");
        }

        $path = $this->fullPath($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage directory could not be created for: {$key}");
        }

        // `rename` within one filesystem, which this is by construction —
        // both paths are under this location's root — so the object
        // appears whole or not at all.
        if (!@rename($partial, $path)) {
            throw new \RuntimeException("Partial upload could not be promoted: {$key}");
        }
    }

    public function discardPartial(string $key): void
    {
        @unlink($this->fullPath($key . self::PARTIAL_SUFFIX));
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        $path = $this->fullPath($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Storage directory could not be created for: {$key}");
        }

        $written = @file_put_contents($path, $contents);
        if ($written === false || $written !== strlen($contents)) {
            throw new \RuntimeException("Stored file could not be written: {$key}");
        }
    }

    public function get(string $key): string
    {
        $path = $this->fullPath($key);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException("Stored file not found: {$key}");
        }
        return $contents;
    }

    public function localPath(string $key): ?string
    {
        $path = $this->fullPath($key);
        return is_file($path) ? $path : null;
    }

    public function size(string $key): ?int
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        return $size !== false ? $size : null;
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            throw new \RuntimeException("Stored file not found: {$key}");
        }
        if ($length <= 0) {
            return '';
        }

        $contents = file_get_contents($path, false, null, max(0, $offset), $length);
        if ($contents === false) {
            throw new \RuntimeException("Stored file not readable: {$key}");
        }
        return $contents;
    }

    public function copy(string $fromKey, string $toKey): void
    {
        $from = $this->fullPath($fromKey);
        if (!is_file($from)) {
            throw new \RuntimeException("Stored file not found: {$fromKey}");
        }

        $to = $this->fullPath($toKey);
        $dir = dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Destination directory not writable: {$toKey}");
        }
        if (!copy($from, $to)) {
            throw new \RuntimeException("Stored file could not be copied: {$fromKey} -> {$toKey}");
        }
    }

    /**
     * A key that is not there is a success — see the interface. Nothing
     * here ever raised for that case, and nothing should start.
     */
    public function delete(string $key): void
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            return;
        }
        if (!@unlink($path) && is_file($path)) {
            throw new \RuntimeException("Stored file could not be removed: {$key}");
        }
    }

    public function deletePrefix(string $prefix): void
    {
        $prefix = trim($prefix, '/');
        // An empty prefix would resolve to the location's own root and
        // wipe everything in it — callers always pass "{albumId}", never "".
        if ($prefix === '') {
            return;
        }

        $dir = $this->fullPath($prefix);
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = (string) $item;
            $removed = $item->isDir() ? @rmdir($path) : @unlink($path);
            if (!$removed && file_exists($path)) {
                // Partially deleted and reported as done is how a caller
                // comes to believe an album's files are gone while they
                // are still occupying — and still being paid for.
                throw new \RuntimeException("Stored files under the prefix could not all be removed: {$prefix}");
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            throw new \RuntimeException("Storage directory could not be removed: {$prefix}");
        }
    }

    /**
     * One page of the files under $prefix, keys relative to this
     * location's root and sorted, so that paging is stable.
     *
     * A filesystem has no cursor of its own, so the cursor IS the last key
     * returned and the next page resumes after it. Sorting is what makes
     * that work: without a total order, « after this key » means nothing
     * and a resumed walk could skip files or repeat them for ever.
     * Directories are not entries — only files are stored objects.
     */
    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $root = $this->fullPath(trim($prefix, '/'));
        if (!is_dir($root)) {
            return new StorageListing([]);
        }

        $keys = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $key = $this->relativeKey($item->getPathname());
            // **An upload in flight is not content.** A consumer listing
            // this location while a safety copy is being written must not
            // meet a half-written object at all — it would be served,
            // copied onward by whatever else stands here, and recorded as
            // a file this installation holds.
            if (str_ends_with($key, self::PARTIAL_SUFFIX)) {
                continue;
            }
            $keys[] = $key;
        }
        sort($keys, SORT_STRING);

        $objects = [];
        $next = null;
        foreach ($keys as $key) {
            if ($cursor !== null && strcmp($key, $cursor) <= 0) {
                continue;
            }
            if (count($objects) >= max(1, $limit)) {
                $next = $objects[count($objects) - 1]->key;
                break;
            }
            $size = $this->size($key);
            $path = $this->fullPath($key);
            $modified = @filemtime($path);
            $objects[] = new StoredObject(
                key: $key,
                sizeBytes: $size ?? 0,
                announcedChecksum: null,
                lastModifiedAt: is_int($modified) ? date('Y-m-d H:i:s', $modified) : null
            );
        }

        return new StorageListing($objects, $next);
    }

    /**
     * Null, always: a local file announces nothing. A caller that needs an
     * imprint has the file in front of it and can hash it — and one that
     * is copying between two directories of the same filesystem should not
     * be hashing at all.
     */
    public function announcedChecksum(string $key): ?string
    {
        return null;
    }

    /**
     * Null: the local disk cannot hand a visitor anything. The consumer
     * serves this object through its own route — see the interface.
     */
    public function directUrl(string $key, string $ttl = '+1 hour'): ?string
    {
        return null;
    }

    public function stableDirectUrl(string $key): ?string
    {
        return null;
    }

    public function exists(string $key): bool
    {
        return is_file($this->fullPath($key));
    }

    /**
     * « The directory exists » is not a connection test, and on a network
     * mount it is barely even a hint — so this writes, reads back and
     * removes a witness file, the same three operations the application
     * actually performs. A share that has gone read-only, or one that has
     * silently become a different (empty, local) directory, fails here
     * rather than at the first upload.
     *
     * **It runs under a time budget** ({@see HEALTH_CHECK_BUDGET_SECONDS}),
     * and that is not a refinement of the above — it is what makes the
     * check safe to run at all from a page. « Le dossier existe » was
     * enough while every location was a folder under `storage/`; a
     * location may now name a network mount, and a network mount has a
     * third state between working and failing. It can go read-only, it can
     * disappear, and it can become *slow*: seconds per `stat()`, minutes
     * for a directory. A check with no budget turns that into a
     * configuration page that never finishes rendering — so the one screen
     * an administrator would use to repair the mount is the one screen
     * they cannot open.
     *
     * So the elapsed time is measured between every step, and a probe that
     * has spent its budget stops and reports it rather than starting the
     * next operation.
     *
     * **What that does not buy, stated plainly.** A hard-mounted NFS whose
     * server is gone blocks the system call itself, in the kernel,
     * uninterruptibly — PHP never gets its turn back, so no PHP-level
     * budget can end it. Nothing in this application closes that; the
     * remedy is on the mount (`soft`, `timeo=`, `retrans=`), which is
     * where a timeout can actually be enforced. What the budget below does
     * cover is every other shape of « slow »: a degraded link, a `soft`
     * mount returning EIO after its own timeout, a disk thrashing — the
     * cases that are common, and that used to be indistinguishable from a
     * hang.
     *
     * **Every operation is caught, because this method is the one that
     * must not throw.** Its whole job is to turn a failure into a French
     * sentence an administrator can act on; letting one escape hands the
     * caller a generic « l'emplacement n'a pas pu être ouvert » instead of
     * the line naming what actually went wrong — and the caller catching
     * it ({@see StorageLocationService::checkNow()}) is what hides that,
     * not what makes it acceptable.
     */
    public function testConnection(): ?string
    {
        $startedAt = $this->now();
        $spent = fn (): bool => ($this->now() - $startedAt) >= $this->healthCheckBudgetSeconds;

        $dir = $this->baseDirectory;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return "Le dossier n'existe pas et n'a pas pu être créé.";
        }
        if ($spent()) {
            return $this->tookTooLong('en cherchant le dossier');
        }
        if (!is_writable($dir)) {
            return "Le dossier n'est pas accessible en écriture.";
        }
        if ($spent()) {
            return $this->tookTooLong('en vérifiant les droits du dossier');
        }

        $key = '.scoutmagic-healthcheck-' . bin2hex(random_bytes(8));
        $content = 'scoutmagic-healthcheck-' . bin2hex(random_bytes(8));

        try {
            $this->put($key, $content, 'text/plain');
        } catch (\Throwable) {
            return "L'écriture d'un fichier témoin a échoué dans ce dossier.";
        }

        // The witness is on the disk from here on, so every way out below
        // removes it — including this one. A probe that gave up on time
        // and left its file behind would seed the location with one more
        // of them on every visit to the page.
        if ($spent()) {
            $this->removeWitnessQuietly($key);

            return $this->tookTooLong("en écrivant un fichier témoin");
        }

        try {
            $readBack = $this->get($key);
        } catch (\Throwable) {
            // The cleanup is best-effort HERE and nowhere else: what the
            // administrator needs to know is that the file could not be
            // read back, and a deletion failure on top of it must not
            // replace that sentence with a less useful one.
            try {
                $this->delete($key);
            } catch (\Throwable) {
                // Reported through the read-back failure above.
            }

            return "Le fichier témoin n'a pas pu être relu juste après avoir été écrit.";
        }

        if ($spent()) {
            $this->removeWitnessQuietly($key);

            return $this->tookTooLong('en relisant le fichier témoin');
        }

        try {
            $this->delete($key);
        } catch (\Throwable) {
            return "Le fichier témoin n'a pas pu être supprimé.";
        }

        if ($readBack !== $content) {
            return 'Le contenu relu après écriture diffère de ce qui a été écrit.';
        }

        // No budget check here, deliberately. Every operation has already
        // succeeded by this point, and a round trip that completed slowly
        // is a location that WORKS — failing it would take a working
        // gallery offline over a disk that was merely busy. The budget
        // exists to refuse to WAIT for the next operation, never to
        // withdraw a success already obtained.
        return null;
    }

    /**
     * The one sentence every expiry returns, naming the step that ran out
     * — « pendant l'écriture » and « pendant la relecture » send an
     * administrator to different places, and a bare « trop lent » sends
     * them nowhere.
     */
    private function tookTooLong(string $step): string
    {
        // « plus de 1 secondes » is the reason this is not written as a
        // count of seconds: the budget is configurable, and a sentence
        // that only reads correctly for some of its values is a sentence
        // that will read wrongly one day. « délai maximal : 5 s » is right
        // for every one of them.
        return sprintf(
            'Le dossier a mis trop de temps à répondre %s (délai maximal : %s s) — le test a été interrompu '
                . 'pour ne pas bloquer cette page. Un dossier réseau peut être devenu très lent, avoir '
                . 'disparu, ou être passé en lecture seule.',
            $step,
            rtrim(rtrim(number_format($this->healthCheckBudgetSeconds, 1, ',', ''), '0'), ',')
        );
    }

    /**
     * Removing the witness on a path out that is not about the deletion.
     * Silent on purpose: the sentence being returned already names what
     * went wrong, and replacing it with « le fichier témoin n'a pas pu
     * être supprimé » would answer a question nobody asked.
     */
    private function removeWitnessQuietly(string $key): void
    {
        try {
            $this->delete($key);
        } catch (\Throwable) {
            // Reported through the sentence the caller is about to return.
        }
    }

    /**
     * The clock the budget is measured on, as a seam a test can move
     * without sleeping.
     *
     * `microtime(true)` rather than `hrtime()` because what is being
     * bounded is a wall-clock wait an administrator is sitting through,
     * and because a test overriding this wants seconds, not nanoseconds.
     *
     * **`@phpstan-impure` states a fact, it does not silence anything.**
     * Nothing here mutates state, so static analysis reads the method as
     * pure and caches its result for the whole call — which turned the
     * second, third and fourth « has the budget run out? » into « always
     * false », since the first one had already answered. That is the
     * analysis being right about purity and wrong about a clock: two reads
     * of the time are two different answers, and this annotation is how
     * that is said.
     *
     * @phpstan-impure
     */
    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * Every key this backend receives is application-generated
     * ("{albumId}/thumb_{mediaId}.jpg"), but the directory below it is
     * half administrator-supplied — so the joined path is CHECKED to stay
     * under the location's own root rather than trusted to. Purely lexical
     * (no `realpath()`): the target of a `put()` does not exist yet, and a
     * missing path must not silently resolve to the root of the whole
     * storage directory.
     *
     * @throws \RuntimeException on any attempt to escape the location's own directory
     */
    private function fullPath(string $key): string
    {
        $base = $this->normalize($this->baseDirectory);
        $full = $this->normalize($base . '/' . ltrim($key, '/'));

        // `$base . '/'` would be `//` for a location at the filesystem
        // root, which nothing starts with — every key would be read as an
        // escape. rtrim() first, and the root becomes the single slash
        // every absolute path does start with.
        if ($full !== $base && !str_starts_with($full, rtrim($base, '/') . '/')) {
            throw new \RuntimeException("Storage key escapes its location: {$key}");
        }

        $this->assertNoSymbolicEscape($base, $full, $key);

        return $full;
    }

    /**
     * The lexical check above reads the TEXT of the path; the filesystem
     * reads its links. A directory below the root that is a symbolic link
     * elsewhere makes a perfectly well-formed key resolve outside the
     * location — and `deletePrefix()` would then remove somebody else's
     * files with this process's permissions.
     *
     * So the deepest ancestor that actually exists is resolved, and has
     * to still be inside the root. Only what exists can be resolved:
     * the target of a `put()` does not yet, which is why the lexical
     * check stays and this one climbs to the first real directory rather
     * than failing on a missing leaf.
     *
     * @throws \RuntimeException when a real path below the root leads out of it
     */
    private function assertNoSymbolicEscape(string $base, string $full, string $key): void
    {
        $realBase = realpath($base);
        if ($realBase === false) {
            // The location's own directory does not exist yet — there is
            // no link to follow, and creating it is put()'s business.
            return;
        }

        $existing = $full;
        while ($existing !== $base && !file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                return;
            }
            $existing = $parent;
        }

        $realExisting = realpath($existing);
        if ($realExisting === false) {
            return;
        }

        if ($realExisting !== $realBase && !str_starts_with($realExisting, rtrim($realBase, '/') . '/')) {
            throw new \RuntimeException("Storage key resolves outside its location through a link: {$key}");
        }
    }

    /** The inverse of {@see fullPath()}, for a path this walk produced. */
    private function relativeKey(string $path): string
    {
        $base = $this->normalize($this->baseDirectory);
        $full = $this->normalize($path);

        return str_starts_with($full, $base . '/') ? substr($full, strlen($base) + 1) : ltrim($full, '/');
    }

    /**
     * Collapses "." / ".." segments and duplicate separators lexically,
     * preserving a leading "/" — `realpath()` cannot be used here (see
     * {@see fullPath()}), and a ".." that walks past the root is clamped
     * rather than allowed to climb further.
     */
    private function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }
}
