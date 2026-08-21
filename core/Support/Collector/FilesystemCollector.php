<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `filesystem.txt` — a listing with, where the host exposes them, type,
 * permissions, owner, group, size, modification time and link target.
 *
 * Depth is uneven on purpose, because the diagnostic value is:
 *
 * - `storage/` in **full depth** — this is where real permission problems
 *   live (a directory the web user cannot write, a file owned by the wrong
 *   account after an FTP upload), and it is the one tree worth walking
 *   entirely;
 * - the project root, `core/`, `modules/`, `public/`, `schema/`, `config/`
 *   at **depth 2** — enough to see what is installed and what is missing,
 *   without thousands of lines of source files nobody will read;
 * - `vendor/` **not walked at all** — only its presence and its top-level
 *   entry count. A full listing there is tens of thousands of lines that
 *   answer nothing.
 *
 * Pure PHP (`RecursiveDirectoryIterator`, `stat()`, `posix_getpwuid()` when
 * available): shelling out to `ls` would add nothing and is blocked on
 * exactly the hosts where this collector matters most.
 */
class FilesystemCollector implements SupportCollectorInterface
{
    private const SHALLOW_DEPTH = 2;

    /**
     * How many entries the full-depth `storage/` walk may list.
     *
     * `storage/` is walked entirely because that is where permission
     * problems live — but it is also where every uploaded photo, every
     * generated variant and every cached thumbnail lives, so on a unit with
     * an active gallery "entirely" is tens of thousands of entries, each
     * costing an `lstat()` and two passwd lookups, all held in memory as
     * one string. That is how a diagnostic that must always be produced
     * (ARCHITECTURE.md §8.48) instead exhausts `memory_limit` and produces
     * nothing. The cap is generous enough that an ordinary install is
     * listed in full, and reaching it is reported in the file and as a
     * note rather than applied silently.
     */
    private const MAX_STORAGE_ENTRIES = 5000;

    /** @var array<int, string> */
    private const SHALLOW_ROOTS = ['core', 'modules', 'public', 'schema', 'config'];

    public function name(): string
    {
        return 'filesystem';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $projectRoot = rtrim($context->projectRoot(), '/');
        $lines = [];

        $lines[] = '# Racine du projet : ' . $projectRoot;
        $lines[] = '# Colonnes : type | permissions | propriétaire | groupe | taille | modifié le | chemin';
        $lines[] = '';

        // First level only, and the heading now says so. Recursing here
        // would re-list core/, modules/, public/, schema/ and config/ a
        // second time, since each gets its own depth-2 section below.
        $lines[] = '## Racine du projet (premier niveau)';
        foreach ($this->entriesOfDirectory($projectRoot) as $entry) {
            $lines[] = $this->describe($entry, $projectRoot);
        }
        $lines[] = '';

        foreach (self::SHALLOW_ROOTS as $directory) {
            $absolute = $projectRoot . '/' . $directory;
            $lines[] = '## ' . $directory . '/ (profondeur ' . self::SHALLOW_DEPTH . ')';
            if (!is_dir($absolute)) {
                $lines[] = '(absent)';
                $lines[] = '';
                continue;
            }
            foreach ($this->walk($absolute, self::SHALLOW_DEPTH) as $entry) {
                $lines[] = $this->describe($entry, $projectRoot);
            }
            $lines[] = '';
        }

        $storagePath = rtrim($context->storagePath(), '/');
        $lines[] = '## storage/ (profondeur complète)';
        if (is_dir($storagePath)) {
            // One over the cap, so "there was more" is knowable without
            // ever holding the whole list of a gallery-sized storage tree.
            $entries = $this->walk($storagePath, PHP_INT_MAX, self::MAX_STORAGE_ENTRIES + 1);
            $overflowed = count($entries) > self::MAX_STORAGE_ENTRIES;
            if ($overflowed) {
                $entries = array_slice($entries, 0, self::MAX_STORAGE_ENTRIES);
            }
            foreach ($entries as $entry) {
                $lines[] = $this->describe($entry, $projectRoot);
            }
            if ($overflowed) {
                $lines[] = '(… liste tronquée : limite de ' . self::MAX_STORAGE_ENTRIES . ' entrées atteinte)';
                $context->addNote('storage/ tronqué à ' . self::MAX_STORAGE_ENTRIES . ' entrées');
            }
        } else {
            $lines[] = '(absent)';
        }
        $lines[] = '';

        $lines[] = '## vendor/ (non parcouru — présence et entrées de premier niveau uniquement)';
        $lines[] = $this->describeVendor($projectRoot . '/vendor');

        $context->addFileFromContent('filesystem.txt', implode("\n", $lines) . "\n");
    }

    /**
     * Immediate children of one directory, sorted, never recursing.
     *
     * @return array<int, string> absolute paths
     */
    private function entriesOfDirectory(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $paths[] = $directory . '/' . $entry;
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Every entry under $root down to $maxDepth levels, sorted, symlinks
     * never followed (a link into `/` would otherwise walk the whole disk).
     *
     * @param int $maxEntries stop once this many entries have been gathered
     * @return array<int, string> absolute paths
     */
    private function walk(string $root, int $maxDepth, int $maxEntries = PHP_INT_MAX): array
    {
        $paths = [];
        $queue = [[$root, 1]];

        while ($queue !== []) {
            [$directory, $depth] = array_shift($queue);
            foreach ($this->entriesOfDirectory($directory) as $path) {
                $paths[] = $path;
                if (count($paths) >= $maxEntries) {
                    sort($paths);

                    return $paths;
                }
                if ($depth < $maxDepth && is_dir($path) && !is_link($path)) {
                    $queue[] = [$path, $depth + 1];
                }
            }
        }

        sort($paths);

        return $paths;
    }

    private function describe(string $path, string $projectRoot): string
    {
        $relative = str_starts_with($path, $projectRoot . '/')
            ? substr($path, strlen($projectRoot) + 1)
            : $path;

        $stat = @lstat($path);
        if ($stat === false) {
            return sprintf('%-4s | %-9s | %-12s | %-12s | %10s | %-19s | %s',
                '?', '?', '?', '?', '?', '?', $relative);
        }

        $type = is_link($path) ? 'lien' : (is_dir($path) ? 'rép' : 'fich');
        $permissions = substr(sprintf('%o', $stat['mode']), -4);
        $owner = $this->ownerName((int) $stat['uid']);
        $group = $this->groupName((int) $stat['gid']);
        $modified = date('Y-m-d H:i:s', (int) $stat['mtime']);

        $line = sprintf(
            '%-4s | %-9s | %-12s | %-12s | %10d | %-19s | %s',
            $type,
            $permissions,
            $owner,
            $group,
            (int) $stat['size'],
            $modified,
            $relative
        );

        if (is_link($path)) {
            $target = @readlink($path);
            $line .= ' -> ' . ($target === false ? '(cible illisible)' : $target);
        }

        return $line;
    }

    private function describeVendor(string $vendorPath): string
    {
        if (!is_dir($vendorPath)) {
            return 'vendor/ : absent';
        }

        return 'vendor/ : présent, ' . count($this->entriesOfDirectory($vendorPath)) . ' entrée(s) de premier niveau';
    }

    private function ownerName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return (string) $uid;
    }

    private function groupName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $info = @posix_getgrgid($gid);
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return (string) $gid;
    }
}
