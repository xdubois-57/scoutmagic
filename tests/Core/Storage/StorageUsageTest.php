<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Storage\StorageUsage;
use PHPUnit\Framework\TestCase;

class StorageUsageTest extends TestCase
{
    private const GIB = 1024 * 1024 * 1024;

    public function testADeclaredQuotaIsTheBasisAndTheSiteIsWhatIsMeasured(): void
    {
        $usage = $this->usage(storageBytes: 2 * self::GIB, quota: 4 * self::GIB, free: 900 * self::GIB,
            total: 1000 * self::GIB);

        $this->assertSame(StorageUsage::BASIS_QUOTA, $usage->basis());
        $this->assertSame(2 * self::GIB, $usage->usedBytes());
        $this->assertSame(4 * self::GIB, $usage->totalBytes());
        $this->assertSame(50, $usage->usedPercent());
    }

    /**
     * Without a declared quota the only figure the host offers is the whole
     * volume's — which is shared, and enormous. The object still reports
     * it, and `basisSentence()` is what stops it being read as this
     * account's.
     */
    public function testWithoutAQuotaTheVolumeIsTheBasis(): void
    {
        $usage = $this->usage(storageBytes: 2 * self::GIB, quota: null, free: 400 * self::GIB,
            total: 1000 * self::GIB);

        $this->assertSame(StorageUsage::BASIS_VOLUME, $usage->basis());
        $this->assertSame(600 * self::GIB, $usage->usedBytes());
        $this->assertSame(60, $usage->usedPercent());
        $this->assertStringContainsString('volume de l\'hébergeur', $usage->basisSentence());
    }

    /**
     * A host that reports nothing has not said the disk is empty. Anything
     * other than null here would be a fabricated 0 % — the same
     * "unavailable is null, never 0" rule §8.47 applies everywhere else.
     */
    public function testNothingKnownIsNullRatherThanZeroPercent(): void
    {
        $usage = $this->usage(storageBytes: 2 * self::GIB, quota: null, free: null, total: null);

        $this->assertSame(StorageUsage::BASIS_UNKNOWN, $usage->basis());
        $this->assertNull($usage->usedPercent());
        $this->assertNull($usage->totalBytes());
        $this->assertSame('', $usage->totalLabel());
    }

    public function testAvailableIsTheMoreConstrainingOfQuotaAndVolume(): void
    {
        // Quota leaves 2 GiB, the volume only 1 — the volume wins.
        $tight = $this->usage(storageBytes: 2 * self::GIB, quota: 4 * self::GIB, free: 1 * self::GIB,
            total: 1000 * self::GIB);
        $this->assertSame(1 * self::GIB, $tight->availableBytes());

        // The other way round.
        $loose = $this->usage(storageBytes: 3 * self::GIB, quota: 4 * self::GIB, free: 500 * self::GIB,
            total: 1000 * self::GIB);
        $this->assertSame(1 * self::GIB, $loose->availableBytes());
    }

    public function testAQuotaAlreadyExceededLeavesNothingRatherThanANegativeAmount(): void
    {
        $usage = $this->usage(storageBytes: 6 * self::GIB, quota: 4 * self::GIB, free: null, total: null);

        $this->assertSame(0, $usage->availableBytes());
        $this->assertSame(100, $usage->usedPercent());
    }

    public function testAvailableIsNullWhenNothingIsKnown(): void
    {
        $usage = $this->usage(storageBytes: 1, quota: null, free: null, total: null);

        $this->assertNull($usage->availableBytes());
    }

    public function testBreakdownIsOrderedLargestFirstAndNamedInFrench(): void
    {
        $usage = new StorageUsage(
            storageBytes: 1000,
            breakdown: [
                StorageUsage::AREA_GALLERY => 600,
                StorageUsage::AREA_BACKUPS => 300,
                StorageUsage::AREA_TEMP => 0,
                StorageUsage::AREA_OTHER => 100,
            ],
            declaredQuotaBytes: null,
            volumeFreeBytes: null,
            volumeTotalBytes: null,
            measuredAt: '2026-09-09 10:00:00'
        );

        $this->assertSame(
            ['galerie 600 o', 'sauvegardes 300 o', 'pièces jointes et divers 100 o'],
            $usage->breakdownLabels()
        );
    }

    private function usage(int $storageBytes, ?int $quota, ?int $free, ?int $total): StorageUsage
    {
        return new StorageUsage(
            storageBytes: $storageBytes,
            breakdown: [],
            declaredQuotaBytes: $quota,
            volumeFreeBytes: $free,
            volumeTotalBytes: $total,
            measuredAt: '2026-09-09 10:00:00'
        );
    }
}
