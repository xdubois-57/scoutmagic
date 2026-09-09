<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

use Core\Config\SettingService;

/**
 * The site's disk budget: what it occupies, what it may still write, and
 * the refusal that happens **before** a large write starts.
 *
 * A generalisation of `Modules\Gallery\Service\DiskSpace`, whose docblock
 * documents the trap this class is built around: on shared hosting
 * `disk_free_space()` reports the underlying volume, **not the account's
 * quota**, and therefore says far less than it looks like it says. So this
 * service exposes two measurements and never claims they are the same one
 * — the free space the system reports, and the real size of `storage/` —
 * plus an optional quota the admin declares from their hosting contract
 * (`storage_quota_bytes`, empty by default). {@see StorageUsage::basis()}
 * carries which of the two the occupation figure was computed on, and the
 * screen states it.
 *
 * **The failure mode this closes is not "the disk is full".** It is
 * *reaching the quota in the middle of a write*: a truncated archive, or a
 * half-copied update, instead of a clean refusal.
 * `Core\Maintenance\Task\InstallUpdateHandler` already documents having met
 * « Disk quota exceeded » in production. A truncated backup is worse than
 * no backup, because nothing reveals it until the day it is restored.
 *
 * **Why the measurement is cached.** Measuring `storage/` means walking
 * every file under it — thousands of them once a gallery exists. Doing
 * that on every upload would spend the whole rendering budget
 * (`docs/exigences-non-fonctionnelles.md` §2) on a number that changes by
 * a few megabytes an hour. The walk is therefore cached in
 * `storage/core/disk-usage.json` for {@see CACHE_TTL_SECONDS}, in a file
 * rather than a `settings` row so a background task can refresh it without
 * depending on a row some other entry point registered. Two things follow,
 * both deliberate: the screen calls {@see measureNow()} and always shows a
 * fresh figure, and {@see ensureRoom()} does not walk anything at all when
 * no quota is declared, since `disk_free_space()` alone answers the
 * question then.
 */
final class DiskBudget
{
    /**
     * The declared quota setting. Canonically a byte count — the name says
     * so — but `ByteFormatter::parse()` also accepts « 10 Go », because
     * nobody reads their hosting contract in bytes.
     */
    public const QUOTA_SETTING = 'storage_quota_bytes';

    /** How long a `storage/` measurement is reused before being walked again. */
    public const CACHE_TTL_SECONDS = 900;

    /**
     * Head-room required on top of whatever a caller estimates.
     *
     * An estimate is an estimate: a dump is sized from
     * `information_schema`, an archive from the tree it will read, and both
     * can come out a little bigger than predicted. This margin is what
     * keeps a slightly-low estimate from being the one that truncates the
     * file. It is small enough not to refuse a write a site could really
     * have made, and large enough to cover the difference between a
     * prediction and a filesystem.
     */
    public const SAFETY_MARGIN_BYTES = 50 * 1024 * 1024;

    private const CACHE_RELATIVE_PATH = 'core/disk-usage.json';

    public function __construct(
        private readonly string $storagePath,
        private readonly SettingService $settings
    ) {
    }

    /**
     * The quota the admin declared, in bytes — or null when they have not
     * declared one, which is the default and is not an error. Everything
     * downstream reads this as "we do not know this account's share", never
     * as "there is no limit".
     */
    public function declaredQuotaBytes(): ?int
    {
        $raw = $this->settings->get(self::QUOTA_SETTING);
        if (!is_string($raw)) {
            return null;
        }

        $bytes = ByteFormatter::parse($raw);

        return $bytes !== null && $bytes > 0 ? $bytes : null;
    }

    /**
     * A reading of `storage/`, walking it only if the cached one has aged
     * past {@see CACHE_TTL_SECONDS}. What every non-interactive caller
     * wants.
     */
    public function measure(): StorageUsage
    {
        $cached = $this->readCache();

        return $cached ?? $this->measureNow();
    }

    /**
     * Walks `storage/` now and refreshes the cache. What the Maintenance
     * screen calls: it is an admin page, visited rarely, and reporting the
     * number IS its job — a figure a quarter of an hour old would be the
     * one thing on it nobody could trust.
     */
    public function measureNow(): StorageUsage
    {
        $gallery = DirectorySize::measure($this->storagePath . '/gallery');
        $backups = DirectorySize::measure($this->storagePath . '/maintenance');
        $temp = DirectorySize::measure($this->storagePath . '/temp');
        // The cache file lives inside the tree it describes, so counting it
        // would make every measurement depend on the previous one's size —
        // a reading that never settles, over a couple of hundred bytes.
        $total = DirectorySize::measure($this->storagePath, [$this->cachePath()]);

        $usage = new StorageUsage(
            storageBytes: $total,
            breakdown: [
                StorageUsage::AREA_GALLERY => $gallery,
                StorageUsage::AREA_BACKUPS => $backups,
                StorageUsage::AREA_TEMP => $temp,
                // The remainder, never a sum of named folders: a module
                // that adds a storage directory tomorrow shows up here
                // instead of quietly falling out of the total.
                StorageUsage::AREA_OTHER => max(0, $total - $gallery - $backups - $temp),
            ],
            declaredQuotaBytes: $this->declaredQuotaBytes(),
            volumeFreeBytes: self::volumeFreeBytes($this->storagePath),
            volumeTotalBytes: self::volumeTotalBytes($this->storagePath),
            measuredAt: (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        $this->writeCache($usage);

        return $usage;
    }

    /**
     * Refuses a write that would not fit, **before** a single byte of it is
     * produced.
     *
     * Three outcomes, and the third is the one to read carefully:
     *
     * - enough room → returns, silently;
     * - not enough → throws, saying how much is missing;
     * - **nothing known** → returns. A host that declares no quota and
     *   reports no volume has not told us the disk is full; refusing every
     *   backup on a host like that would break the feature this whole
     *   iteration exists to protect. That is the documented fallback the
     *   spec asks for, not an oversight — and it is exactly why the screen
     *   says which measurement it is using.
     *
     * @throws InsufficientDiskSpaceException
     */
    public function ensureRoom(int $estimatedBytes): void
    {
        $needed = max(0, $estimatedBytes) + self::SAFETY_MARGIN_BYTES;
        $available = $this->availableBytes();

        if ($available === null || $available >= $needed) {
            return;
        }

        throw new InsufficientDiskSpaceException(sprintf(
            'Espace disque insuffisant : cette opération a besoin d\'environ %s et il ne reste que %s. '
                . 'Il manque %s. Supprimez des sauvegardes ou des photos, puis réessayez.',
            ByteFormatter::format($needed),
            ByteFormatter::format($available),
            ByteFormatter::format($needed - $available)
        ));
    }

    /**
     * What is still writable, or null when nothing says. Walks `storage/`
     * only when a quota is declared — without one the volume's own free
     * space is the whole answer, and it costs one syscall.
     */
    public function availableBytes(): ?int
    {
        $quota = $this->declaredQuotaBytes();
        $volumeFree = self::volumeFreeBytes($this->storagePath);

        if ($quota === null) {
            return $volumeFree;
        }

        $quotaLeft = max(0, $quota - $this->measure()->storageBytes);

        return $volumeFree === null ? $quotaLeft : min($quotaLeft, $volumeFree);
    }

    /** Null when the host would not say — never 0, which would mean "full". */
    private static function volumeFreeBytes(string $path): ?int
    {
        $free = @disk_free_space($path);

        return is_float($free) && $free >= 0 ? (int) $free : null;
    }

    /** Null when the host would not say — never 0, which would mean "no disk". */
    private static function volumeTotalBytes(string $path): ?int
    {
        $total = @disk_total_space($path);

        return is_float($total) && $total > 0 ? (int) $total : null;
    }

    private function cachePath(): string
    {
        return $this->storagePath . '/' . self::CACHE_RELATIVE_PATH;
    }

    /**
     * The cached reading, or null when there is none, it is unreadable, it
     * is stale, or the declared quota has changed since it was written —
     * that last case matters because the admin typing their quota expects
     * the next page to reflect it, not the one after the TTL.
     */
    private function readCache(): ?StorageUsage
    {
        $path = $this->cachePath();
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['storage_bytes'], $data['measured_at_unix'])) {
            return null;
        }

        if (time() - (int) $data['measured_at_unix'] > self::CACHE_TTL_SECONDS) {
            return null;
        }

        $quota = $this->declaredQuotaBytes();
        $cachedQuota = isset($data['declared_quota_bytes']) ? (int) $data['declared_quota_bytes'] : null;
        if ($cachedQuota !== $quota) {
            return null;
        }

        /** @var array<string, int> $breakdown */
        $breakdown = [];
        foreach ((array) ($data['breakdown'] ?? []) as $area => $bytes) {
            $breakdown[(string) $area] = (int) $bytes;
        }

        return new StorageUsage(
            storageBytes: (int) $data['storage_bytes'],
            breakdown: $breakdown,
            declaredQuotaBytes: $quota,
            volumeFreeBytes: self::volumeFreeBytes($this->storagePath),
            volumeTotalBytes: self::volumeTotalBytes($this->storagePath),
            measuredAt: (string) ($data['measured_at'] ?? '')
        );
    }

    /**
     * Best effort, and deliberately silent on failure: a read-only
     * `storage/core/` must degrade to "measure every time", never to a
     * fatal on a page that was only reporting a number.
     */
    private function writeCache(StorageUsage $usage): void
    {
        $path = $this->cachePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode([
            'storage_bytes' => $usage->storageBytes,
            'breakdown' => $usage->breakdown,
            'declared_quota_bytes' => $usage->declaredQuotaBytes,
            'measured_at' => $usage->measuredAt,
            'measured_at_unix' => time(),
        ]);
        if ($payload === false) {
            return;
        }

        // Written under a temporary name and renamed, so a concurrent
        // reader never sees half a JSON document.
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $payload) === false) {
            @unlink($temporary);
            return;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }
}
