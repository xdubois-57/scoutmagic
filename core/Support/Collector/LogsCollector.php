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
 * `logs/` — the last 48 hours of every log file this installation can
 * actually read.
 *
 * One generic collector over a candidate list (D-07), not a per-platform
 * log discovery: the candidates that exist on a given host are whatever
 * that host chose to expose, and a mechanism that "knows about Apache" adds
 * complexity without finding a single extra file on chrooted hosting.
 *
 * Two bounds, both deliberate. **48 hours** because a support request is
 * about something that happened recently, and a month of access logs
 * answers nothing extra. **A hard byte cap per file** because a busy site's
 * access log is measured in hundreds of megabytes and an archive that big
 * cannot be emailed, which would defeat the whole point; a truncated file
 * is reported as such in `collection-status.json` rather than silently
 * shortened.
 *
 * These files are the single strongest reason the Support page and README
 * warn about the archive's contents: server logs contain visitors' IP
 * addresses. **No process or service snapshot is ever taken** — explicitly
 * out of scope.
 */
class LogsCollector implements SupportCollectorInterface
{
    public const WINDOW_HOURS = 48;
    public const MAX_BYTES_PER_FILE = 2 * 1024 * 1024;

    /**
     * Bounds on the collection as a whole, not just on each file.
     *
     * The per-file cap alone is not a bound: a shared host's `~/logs`
     * routinely holds one rotated access and error log per domain per day,
     * so "a few files" is often a few hundred. At 2 MB each that is
     * hundreds of megabytes assembled as PHP strings inside one scheduled
     * task — an archive nobody can email, produced by a process that will
     * more likely hit `memory_limit` first and produce **no archive at
     * all**. "A package is always produced" (ARCHITECTURE.md §8.48) is the
     * contract this collector has to keep, and an unbounded read is the
     * clearest way to break it.
     *
     * What is dropped is reported, never silently omitted: the summary
     * names every candidate that was not copied, and a note says how many.
     */
    public const MAX_FILES = 25;
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024;

    /**
     * Reserved: this collector writes its own summary under that name, and
     * a host log file that happens to be called `summary.txt` would
     * otherwise be copied to `logs/summary.txt` and then overwritten by it.
     */
    private const SUMMARY_NAME = 'summary.txt';

    public function name(): string
    {
        return 'logs';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $candidates = $this->candidatePaths($context);

        $summary = [];
        $summary[] = '# Journaux serveur — ' . self::WINDOW_HOURS . ' dernières heures';
        $summary[] = '# Chaque fichier est tronqué à ' . self::MAX_BYTES_PER_FILE . ' octets au maximum.';
        $summary[] = '';

        $collected = 0;
        $skippedForBudget = 0;
        $totalBytes = 0;
        $usedNames = [self::SUMMARY_NAME => true];

        foreach ($candidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                $summary[] = '- ' . $path . ' : indisponible';
                continue;
            }

            if ($collected >= self::MAX_FILES || $totalBytes >= self::MAX_TOTAL_BYTES) {
                $summary[] = '- ' . $path . ' : non copié (budget global atteint)';
                $skippedForBudget++;
                continue;
            }

            $extract = $this->extractRecent($path, self::MAX_TOTAL_BYTES - $totalBytes);
            if ($extract === null) {
                $summary[] = '- ' . $path . ' : illisible';
                continue;
            }

            $name = $this->uniqueName($path, $usedNames);
            $context->addFileFromContent('logs/' . $name, $extract['content']);
            $collected++;
            $totalBytes += strlen($extract['content']);

            $summary[] = '- ' . $path . ' : copié sous logs/' . $name
                . ' (' . $extract['lines'] . ' ligne(s)'
                . ($extract['truncated'] ? ', tronqué' : '') . ')';

            if ($extract['truncated']) {
                $context->addNote($path . ' tronqué à ' . self::MAX_BYTES_PER_FILE . ' octets');
            }
        }

        if ($skippedForBudget > 0) {
            $summary[] = '';
            $summary[] = '# ' . $skippedForBudget . ' fichier(s) non copié(s) : limite de '
                . self::MAX_FILES . ' fichiers / ' . self::MAX_TOTAL_BYTES . ' octets atteinte.';
            $context->addNote($skippedForBudget . ' journal(aux) non copié(s), budget global atteint');
        }

        $context->addFileFromContent('logs/' . self::SUMMARY_NAME, implode("\n", $summary) . "\n");

        if ($collected === 0) {
            $context->markUnavailable('no_readable_log_file');
        }
    }

    /**
     * @return array<int, string>
     */
    private function candidatePaths(SupportCollectorContext $context): array
    {
        $storagePath = rtrim($context->storagePath(), '/');
        $projectRoot = rtrim($context->projectRoot(), '/');

        $candidates = [];

        $errorLog = (string) ini_get('error_log');
        if (trim($errorLog) !== '' && $errorLog !== 'syslog') {
            $candidates[] = $errorLog;
        }

        foreach (glob($storagePath . '/logs/*') ?: [] as $path) {
            $candidates[] = $path;
        }

        // Typical shared-hosting layouts: a sibling or home-level logs
        // directory next to the document root.
        $home = getenv('HOME');
        $logDirectories = [dirname($projectRoot) . '/logs', $projectRoot . '/../logs'];
        if (is_string($home) && $home !== '') {
            $logDirectories[] = rtrim($home, '/') . '/logs';
        }
        foreach ($logDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob(rtrim($directory, '/') . '/*') ?: [] as $path) {
                $candidates[] = $path;
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            $key = $real !== false ? $real : $candidate;
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * Reads at most MAX_BYTES_PER_FILE from the END of the file (the recent
     * end), then keeps only lines that are provably within the window.
     *
     * A line whose timestamp cannot be parsed is **kept**: log formats vary
     * far too much to treat "I could not read a date here" as "this is
     * old", and dropping a stack trace's continuation lines because they
     * carry no timestamp of their own would mangle exactly the entries
     * worth reading.
     *
     * @param int $byteBudget how much of the run's total budget is left —
     *        the effective cap is the smaller of this and MAX_BYTES_PER_FILE
     * @return array{content: string, lines: int, truncated: bool}|null
     */
    private function extractRecent(string $path, int $byteBudget = self::MAX_BYTES_PER_FILE): ?array
    {
        $size = @filesize($path);
        if ($size === false) {
            return null;
        }

        $cap = max(0, min(self::MAX_BYTES_PER_FILE, $byteBudget));

        $truncated = $size > $cap;
        $offset = $truncated ? $size - $cap : 0;

        $raw = @file_get_contents($path, false, null, $offset);
        if ($raw === false) {
            return null;
        }

        if ($truncated) {
            // The first line is almost certainly cut mid-way.
            $firstNewline = strpos($raw, "\n");
            $raw = $firstNewline === false ? '' : substr($raw, $firstNewline + 1);
        }

        $cutoff = time() - self::WINDOW_HOURS * 3600;
        $kept = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $timestamp = self::timestampOf($line);
            if ($timestamp === null || $timestamp >= $cutoff) {
                $kept[] = $line;
            }
        }

        $header = '# Source : ' . $path . "\n"
            . '# Fenêtre : ' . self::WINDOW_HOURS . ' dernières heures'
            . ($truncated ? ' (fichier tronqué au début)' : '') . "\n\n";

        return [
            'content' => $header . implode("\n", $kept) . "\n",
            'lines' => count($kept),
            'truncated' => $truncated,
        ];
    }

    /**
     * Best-effort timestamp for a log line, covering the formats this
     * project's hosts actually produce: Apache error log, PHP error log,
     * combined access log, and ISO 8601.
     */
    public static function timestampOf(string $line): ?int
    {
        $patterns = [
            // [Wed Aug 20 03:00:00.000000 2026] — Apache error log
            '/^\[([A-Za-z]{3} [A-Za-z]{3} +\d{1,2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\]/' => static fn(array $m): string => $m[1] . ' ' . $m[2],
            // [20-Aug-2026 03:00:00 UTC] — PHP error log
            '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z\/_+\-0-9]+)?)\]/' => static fn(array $m): string => $m[1],
            // 203.0.113.1 - - [20/Aug/2026:03:00:00 +0200] — access log
            '/\[(\d{2}\/[A-Za-z]{3}\/\d{4}):(\d{2}:\d{2}:\d{2}) ([+\-]\d{4})\]/' => static fn(array $m): string => str_replace('/', ' ', $m[1]) . ' ' . $m[2] . ' ' . $m[3],
            // 2026-08-20T03:00:00+00:00 or 2026-08-20 03:00:00
            '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[+\-]\d{2}:?\d{2}|Z)?)/' => static fn(array $m): string => $m[1],
        ];

        foreach ($patterns as $pattern => $normalize) {
            if (preg_match($pattern, $line, $matches) === 1) {
                $timestamp = strtotime($normalize($matches));
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $usedNames
     */
    private function uniqueName(string $path, array &$usedNames): string
    {
        $base = basename($path);
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'log';
        }

        $name = $base;
        $suffix = 2;
        while (isset($usedNames[$name])) {
            $name = $base . '.' . $suffix;
            $suffix++;
        }
        $usedNames[$name] = true;

        return $name;
    }
}
