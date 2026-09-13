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
class LocalStorageBackend implements RangeReadableBackend, ServerSideCopyBackend
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
        return [StorageCapability::RangeRead, StorageCapability::ServerSideCopy];
    }

    public function __construct(
        private string $baseDirectory
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
            $keys[] = $this->relativeKey($item->getPathname());
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
     * What it still cannot do is survive a mount that FREEZES rather than
     * fails: a severed NFS blocks the system call itself, and no PHP-level
     * timeout interrupts that. Closing that hole is what the Stockage
     * screen's own check adds on top, and it is the reason this method
     * returns a sentence instead of a boolean.
     */
    public function testConnection(): ?string
    {
        $dir = $this->baseDirectory;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return "Le dossier n'existe pas et n'a pas pu être créé.";
        }
        if (!is_writable($dir)) {
            return "Le dossier n'est pas accessible en écriture.";
        }

        $key = '.scoutmagic-healthcheck-' . bin2hex(random_bytes(8));
        $content = 'scoutmagic-healthcheck-' . bin2hex(random_bytes(8));

        try {
            $this->put($key, $content, 'text/plain');
        } catch (\Throwable) {
            return "L'écriture d'un fichier témoin a échoué dans ce dossier.";
        }

        try {
            $readBack = $this->get($key);
        } catch (\Throwable) {
            $this->delete($key);
            return "Le fichier témoin n'a pas pu être relu juste après avoir été écrit.";
        }

        $this->delete($key);

        if ($readBack !== $content) {
            return 'Le contenu relu après écriture diffère de ce qui a été écrit.';
        }
        if ($this->exists($key)) {
            return "Le fichier témoin n'a pas pu être supprimé.";
        }

        return null;
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
