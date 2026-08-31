<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

use Core\Config\SettingService;
use Core\Database\Connection;

/**
 * Everything a support collector is given, and the only way it writes into
 * the archive (ARCHITECTURE.md §8.48).
 *
 * Deliberately narrow: a collector adds files, reads the application's own
 * services, and can declare itself unavailable with a reason. It never sees
 * the ZipArchive, never learns where the archive will end up, and never
 * decides whether the package is produced.
 */
class SupportCollectorContext
{
    /** @var array<int, string> */
    private array $notes = [];

    private ?string $unavailableReason = null;

    /** @var array<int, string> */
    private array $secretsToRedact;

    /**
     * @param array<int, string> $secretsToRedact literal secret values that
     *        must never reach the archive — see redact()
     */
    public function __construct(
        private \ZipArchive $archive,
        private Connection $connection,
        private SettingService $settingService,
        private string $projectRoot,
        private string $storagePath,
        array $secretsToRedact = []
    ) {
        $this->secretsToRedact = array_values(array_filter(
            array_map(static fn(string $secret): string => trim($secret), $secretsToRedact),
            static fn(string $secret): bool => strlen($secret) >= 8
        ));
    }

    /**
     * Sanitise a free-text value before it goes into the archive: every
     * known secret replaced, then control characters collapsed and the
     * length capped.
     *
     * The canonical case is a scheduled task's `last_error` — a PDO failure
     * message routinely quotes the credentials it failed with, and this
     * archive leaves the installation. The same routine sanitises collector
     * failure reasons in `collection-status.json`.
     *
     * **Substitution comes first, and the order is the whole point.** The
     * secrets handed in are every value of `secrets.enc` — the database and
     * SMTP passwords among them — and a password may perfectly well contain
     * a space or a tab. Collapsing whitespace before searching for it turns
     * "hunter  2" in the message into "hunter 2", which no longer matches
     * the needle, and the credential rides into an archive destined for
     * email. Core\Statistics\StatisticsSender::redact() has always redacted
     * first for exactly this reason; this method now agrees with it.
     */
    public function redact(string $value, int $maxLength = 300): string
    {
        foreach ($this->secretsToRedact as $secret) {
            $value = str_ireplace($secret, '[REDACTED]', $value);
        }

        $value = self::collapseWhitespace($value);

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }

    /**
     * Control characters and runs of whitespace collapsed to single spaces.
     *
     * Deliberately byte-oriented rather than `/u`: a PDO driver error, a
     * log line or a command banner is routinely *not* valid UTF-8, and `preg_replace()`
     * with the `/u` flag returns `null` on such a subject — which the old
     * `(string)` cast turned into an empty string, silently discarding the
     * one clue the archive existed to carry. The ASCII ranges being matched
     * never occur inside a multi-byte UTF-8 sequence, so dropping `/u`
     * costs nothing and cannot corrupt a valid one.
     */
    public static function collapseWhitespace(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);
        $value = trim($value);

        // …and then made valid UTF-8, because the destination is JSON.
        // `json_encode()` returns `false` — not a throw, not a partial
        // string — on a subject carrying an invalid byte sequence, and
        // SupportPackageService casts that result to string: one collector
        // reporting a latin-1 driver error would have emptied the whole of
        // `collection-status.json`, taking every other collector's status
        // with it. Dropping the offending bytes keeps the readable part,
        // which is the part worth having.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }

    /**
     * Add a file to the archive from a string.
     *
     * Paths inside the archive are normalised and rejected if they try to
     * escape: a collector composing a path from a filesystem entry it just
     * scanned must not be able to write outside the archive's own tree.
     */
    public function addFileFromContent(string $pathInArchive, string $content): void
    {
        $path = self::normalizeArchivePath($pathInArchive);
        $this->archive->addFromString($path, $content);
    }

    /**
     * Add a file to the archive by copying a readable file from disk.
     * Returns false — never throws — when the source cannot be read, so a
     * best-effort collector can simply move on to its next candidate.
     */
    public function addFileFromPath(string $pathInArchive, string $sourcePath): bool
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return false;
        }

        $content = @file_get_contents($sourcePath);
        if ($content === false) {
            return false;
        }

        $this->addFileFromContent($pathInArchive, $content);

        return true;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function pdo(): \PDO
    {
        return $this->connection->getPdo();
    }

    public function settings(): SettingService
    {
        return $this->settingService;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Declare that this collector had nothing to gather for a reason that
     * is not a fault — a tool that is not installed, a path this host does
     * not expose, a platform feature that does not exist here.
     */
    public function markUnavailable(string $reason): void
    {
        $this->unavailableReason = $reason;
    }

    /**
     * Record a detail worth surfacing next to the collector's status —
     * a truncated file, a skipped candidate, a partial result.
     *
     * Redacted on the way in, like every other free text that reaches
     * `collection-status.json`. Today's notes are counts and paths a
     * collector composed itself, but "notes are the one string nobody
     * scrubs" is not a property worth relying on the next collector to
     * remember.
     */
    public function addNote(string $note): void
    {
        $this->notes[] = $this->redact($note);
    }

    public function unavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Reset the per-collector state between two collectors sharing this
     * context — notes and an "unavailable" verdict belong to one collector,
     * not to the run.
     */
    public function resetCollectorState(): void
    {
        $this->notes = [];
        $this->unavailableReason = null;
    }

    /**
     * A safe, relative path inside the archive: no leading slash, no drive
     * prefix, no `..` segment, no backslash.
     */
    private static function normalizeArchivePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? 'unnamed' : implode('/', $segments);
    }
}
