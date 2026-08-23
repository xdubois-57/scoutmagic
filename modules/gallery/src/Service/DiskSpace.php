<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * How much room is left on the volume a local storage location writes to,
 * as the Galerie configuration page shows it.
 *
 * Deliberately a value object rather than three numbers in the template:
 * choosing between « 512 Mo » and « 1,2 Go », deciding what counts as
 * "running out", and knowing that an unknown total is not a total of zero
 * are all decisions, and a decision in a Twig file is a decision nobody
 * can test (AGENTS.md § Architecture).
 *
 * **The measurement is the volume's, not the gallery's.** `disk_free_space()`
 * reports the filesystem the directory sits on — shared with the database,
 * the backups and everything else on the host, and on shared hosting often
 * far larger than the quota the account actually gets. The page says so;
 * this class only refuses to pretend otherwise.
 */
final class DiskSpace
{
    /** 1024, like every other size in this module: `Mo` here means MiB, as it does for the upload limits. */
    private const STEP = 1024;

    /** @var array<int, string> */
    private const UNITS = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];

    /**
     * @param int $freeBytes      bytes still available on the volume
     * @param int $totalBytes     the volume's size, or 0 when the host would not say
     * @param int $warnBelowBytes free space under which the page warns — the largest
     *                            single file the gallery is currently allowed to accept,
     *                            so "less than this left" means "the next upload can fail"
     */
    public function __construct(
        public readonly int $freeBytes,
        public readonly int $totalBytes,
        public readonly int $warnBelowBytes
    ) {
    }

    public function usedBytes(): int
    {
        return max(0, $this->totalBytes - $this->freeBytes);
    }

    /**
     * Share of the volume in use, 0-100 — or null when the total is unknown.
     * Never 0%: a host that will not report its volume size has not told us
     * the disk is empty (§8.47's "unavailable is null, never 0" rule, applied
     * to the one metric on this page that has the same trap).
     */
    public function usedPercent(): ?int
    {
        if ($this->totalBytes <= 0) {
            return null;
        }

        return (int) round($this->usedBytes() / $this->totalBytes * 100);
    }

    /**
     * Whether what is left is smaller than the biggest file the gallery
     * would accept right now. An absolute threshold (« under 1 Go ») would
     * be arbitrary and wrong in both directions: alarming on a host where
     * only 30 Mo photos are allowed, silent on one accepting 2 Go videos.
     */
    public function isLow(): bool
    {
        return $this->warnBelowBytes > 0 && $this->freeBytes < $this->warnBelowBytes;
    }

    public function freeLabel(): string
    {
        return self::format($this->freeBytes);
    }

    /** Empty when the host would not report the volume's size — never « 0 o ». */
    public function totalLabel(): string
    {
        return $this->totalBytes > 0 ? self::format($this->totalBytes) : '';
    }

    public function warnBelowLabel(): string
    {
        return self::format($this->warnBelowBytes);
    }

    /**
     * A byte count in French: one decimal from « Go » up to 100, none
     * anywhere else. « 1,5 Go » is worth the digit; « 340,0 Mo » and
     * « 512,0 Go » only look more precise than the measurement is.
     */
    public static function format(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= self::STEP && $unit < count(self::UNITS) - 1) {
            $value /= self::STEP;
            $unit++;
        }

        $decimals = $unit >= 3 && $value < 100 ? 1 : 0;

        return number_format($value, $decimals, ',', ' ') . ' ' . self::UNITS[$unit];
    }
}
