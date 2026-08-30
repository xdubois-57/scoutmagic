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
 * cannot be transmitted, which would defeat the whole point; a truncated file
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

    /** Reserved for the same reason as SUMMARY_NAME above. */
    private const DIGEST_NAME = 'errors-resume.txt';

    /** @var array<string, array{count: int, example: string}> keyed by signature */
    private array $fatals = [];

    /** @var array<string, int> level => occurrences, for the noise ratio */
    private array $noise = [];

    private int $fatalCount = 0;

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
        // The copied lines keep whatever clock wrote them, which is local
        // time and not the UTC of this archive's JSON files. Both zones are
        // named because they can differ: PHP runs on the application clock
        // (Core\Config\AppClock), while the web server writes its own lines
        // on the host's. Said here as well as in collection-status.json,
        // because this is the file someone reads just before opening a log.
        $hostTimezone = trim((string) ini_get('date.timezone'));
        $summary[] = '# Horodatages : heure locale, jamais UTC. Application : '
            . date_default_timezone_get() . ' (UTC' . (new \DateTimeImmutable('now'))->format('P') . ').'
            . ($hostTimezone !== '' ? ' Hébergement (PHP) : ' . $hostTimezone . '.' : '');
        $summary[] = '';

        $collected = 0;
        $skippedForBudget = 0;
        $totalBytes = 0;
        $usedNames = [self::SUMMARY_NAME => true, self::DIGEST_NAME => true];

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
            $this->accumulateDigest($extract['content']);
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
        $context->addFileFromContent('logs/' . self::DIGEST_NAME, $this->renderDigest($context));

        if ($this->fatalCount > 0) {
            $context->addNote($this->fatalCount . ' erreur(s) fatale(s) dans les journaux — voir logs/' . self::DIGEST_NAME);
        }

        if ($collected === 0) {
            $context->markUnavailable('no_readable_log_file');
        }
    }

    /**
     * Counts and groups the lines that mean something broke, as each file
     * is copied.
     *
     * Copying the logs is not the same as making them readable. The
     * archive that prompted this carried 233 error-log lines, of which 230
     * were the same PHP deprecation repeating and exactly one was an
     * uncaught exception that had been returning 500 on every route —
     * findable only by reading all 233. A support reader should meet the
     * fatal first and the noise as a number.
     *
     * Deliberately signature-based rather than a parser: these files are
     * written by whatever the host runs (Apache wrapping PHP messages,
     * php-fpm, a bare error_log), and there is no format to parse. What
     * every one of them has in common is the words PHP itself writes.
     */
    private function accumulateDigest(string $content): void
    {
        foreach (explode("\n", $content) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/\b(PHP )?(Fatal error|Parse error|Recoverable fatal error)\b|\bUncaught\b/i', $line) === 1) {
                $this->fatalCount++;
                $key = self::signature($line);
                $this->fatals[$key] ??= ['count' => 0, 'example' => self::firstLine($line)];
                $this->fatals[$key]['count']++;
                continue;
            }

            if (preg_match('/\bPHP (Deprecated|Warning|Notice)\b/i', $line, $m) === 1) {
                $level = ucfirst(strtolower($m[1]));
                $this->noise[$level] = ($this->noise[$level] ?? 0) + 1;
            }
        }
    }

    /**
     * What makes two occurrences of the same fault the same fault: the
     * message with everything that varies between occurrences removed —
     * timestamps, request ids, IP addresses, line numbers, object hashes.
     * Without that, one fault repeating a thousand times is a thousand
     * entries and the digest is as unreadable as the log.
     */
    private static function signature(string $line): string
    {
        $line = self::firstLine($line);
        $line = (string) preg_replace('/\b[0-9a-f]{8,}\b/i', 'X', $line);
        $line = (string) preg_replace('/\b\d+\b/', 'N', $line);
        $line = (string) preg_replace('/\s+/', ' ', $line);

        return mb_substr(trim($line), 0, 400);
    }

    /**
     * A stack trace is one log line with literal `\n` escapes in it. The
     * first frame identifies the fault; the rest belongs in the copied
     * log, not in a summary meant to be read at a glance.
     */
    private static function firstLine(string $line): string
    {
        $line = str_replace(['\\n', "\r"], "\n", $line);
        $first = strtok($line, "\n");

        return mb_substr($first === false ? $line : $first, 0, 500);
    }

    private function renderDigest(SupportCollectorContext $context): string
    {
        $lines = [
            '# Journaux serveur — erreurs regroupées sur ' . self::WINDOW_HOURS . ' h',
            '# Une ligne par erreur distincte, la plus fréquente en premier.',
            '# Le détail complet est dans les fichiers copiés à côté.',
            '',
        ];

        if ($this->fatals === []) {
            $lines[] = 'Aucune erreur fatale ni exception non rattrapée sur la période.';
        } else {
            $lines[] = 'ERREURS FATALES ET EXCEPTIONS NON RATTRAPÉES';
            $lines[] = '---------------------------------------------';
            uasort($this->fatals, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
            foreach ($this->fatals as $entry) {
                $lines[] = sprintf('%6d ×  %s', $entry['count'], $context->redact($entry['example'], 500));
            }
        }

        if ($this->noise !== []) {
            arsort($this->noise);
            $lines[] = '';
            $lines[] = 'AVERTISSEMENTS ET DÉPRÉCIATIONS (volume seulement)';
            $lines[] = '--------------------------------------------------';
            $lines[] = "# Ces lignes n'indiquent pas une panne, mais leur volume peut noyer ce qui en est une.";
            foreach ($this->noise as $level => $count) {
                $lines[] = sprintf('%6d ×  PHP %s', $count, $level);
            }
        }

        return implode("\n", $lines) . "\n";
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
