<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Volume;

use Core\Storage\Volume\VolumeUsage;
use PHPUnit\Framework\TestCase;

/**
 * One volume's arithmetic, in isolation from the inventory that builds it
 * (ARCHITECTURE.md §8.108).
 *
 * What this file is really about is the rule `specifications.md` §8.47
 * states for the installation as a whole and that §8.108 repeats per
 * volume: **« unavailable is null, never 0 »**. Every figure on this
 * object has a « the host would not say » answer, and each of them turns
 * into a wrong number rather than a missing one if it is treated as zero
 * somewhere — « ce volume est plein » being the reading that makes an
 * administrator go and delete photographs.
 */
class VolumeUsageTest extends TestCase
{
    private const GIB = 1073741824;

    public function testTheSystemBasisComputesWhatIsUsedFromTheTwoSystemFigures(): void
    {
        $volume = $this->volume(freeBytes: 3 * self::GIB, totalBytes: 10 * self::GIB);

        $this->assertSame(VolumeUsage::BASIS_VOLUME, $volume->basis());
        $this->assertSame(7 * self::GIB, $volume->basisUsedBytes());
        $this->assertSame(10 * self::GIB, $volume->basisTotalBytes());
        $this->assertSame(70, $volume->usedPercent());
    }

    /**
     * **The one this file exists for.** `VolumeInventory` guards
     * `disk_free_space()` and `disk_total_space()` separately, so a host
     * answering one and refusing the other is a shape that really occurs —
     * and subtracting a missing free space from a known total reads as
     * 100 % used. That is not « we do not know »: it is « delete
     * something », answered about a volume nobody measured.
     */
    public function testAVolumeWhoseFreeSpaceIsUnknownReportsUnknownUsageRatherThanFull(): void
    {
        $volume = $this->volume(freeBytes: null, totalBytes: 10 * self::GIB);

        $this->assertSame(VolumeUsage::BASIS_VOLUME, $volume->basis(), 'The size IS known, so the basis stands.');
        $this->assertNull($volume->basisUsedBytes());
        $this->assertNull($volume->usedPercent());
        $this->assertSame('', $volume->basisUsedLabel(), 'An empty label, never « 0 o », which reads as empty.');
        $this->assertSame(10 * self::GIB, $volume->basisTotalBytes(), 'The total is still worth stating.');
    }

    public function testAVolumeThatReportsNothingAtAllHasNoBasisAndNoPercentage(): void
    {
        $volume = $this->volume(freeBytes: null, totalBytes: null);

        $this->assertSame(VolumeUsage::BASIS_UNKNOWN, $volume->basis());
        $this->assertNull($volume->basisTotalBytes());
        $this->assertNull($volume->basisUsedBytes());
        $this->assertNull($volume->usedPercent());
    }

    /**
     * A declared quota wins, and it is measured on what the site's own
     * directories occupy rather than on the volume — precisely because the
     * volume is somebody else's disk when a quota was needed at all.
     */
    public function testADeclaredQuotaWinsOverTheSystemFigureAndIsMeasuredOnTheOccupation(): void
    {
        $volume = $this->volume(
            freeBytes: 3 * self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: 4 * self::GIB,
            occupiedBytes: self::GIB
        );

        $this->assertSame(VolumeUsage::BASIS_QUOTA, $volume->basis());
        $this->assertSame(self::GIB, $volume->basisUsedBytes());
        $this->assertSame(4 * self::GIB, $volume->basisTotalBytes());
        $this->assertSame(25, $volume->usedPercent());
    }

    /**
     * Under a quota, an unmeasurable occupation is unknown too — the same
     * rule, on the other basis.
     */
    public function testAQuotaWithNothingMeasuredReportsUnknownRatherThanEmpty(): void
    {
        $volume = $this->volume(
            freeBytes: 3 * self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: 4 * self::GIB,
            occupiedBytes: null
        );

        $this->assertNull($volume->basisUsedBytes());
        $this->assertNull($volume->usedPercent());
    }

    /**
     * Whichever of the two binds first, since either can: a quota with
     * room left on a disk that has none is still a disk that has none.
     */
    public function testTheAvailableRoomIsTheSmallerOfTheQuotaAndTheSystem(): void
    {
        $tightQuota = $this->volume(
            freeBytes: 9 * self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: 4 * self::GIB,
            occupiedBytes: 3 * self::GIB
        );
        $this->assertSame(self::GIB, $tightQuota->availableBytes());

        $tightDisk = $this->volume(
            freeBytes: self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: 100 * self::GIB,
            occupiedBytes: 3 * self::GIB
        );
        $this->assertSame(self::GIB, $tightDisk->availableBytes());
    }

    /** Null means *unknown*, never *unlimited* — the caller decides. */
    public function testAvailableRoomIsNullWhenNeitherAQuotaNorTheSystemAnswers(): void
    {
        $this->assertNull($this->volume(freeBytes: null, totalBytes: null)->availableBytes());
    }

    public function testTheBasisSentenceDistinguishesThePrimaryVolumeFromTheOthers(): void
    {
        $primary = $this->volume(freeBytes: self::GIB, totalBytes: 10 * self::GIB, isPrimary: true);
        $other = $this->volume(freeBytes: self::GIB, totalBytes: 10 * self::GIB, isPrimary: false);

        $this->assertStringContainsString('Quota disque déclaré', $primary->basisSentence());
        $this->assertStringNotContainsString('Quota disque déclaré', $other->basisSentence());
    }

    private function volume(
        ?int $freeBytes,
        ?int $totalBytes,
        ?int $declaredQuotaBytes = null,
        ?int $occupiedBytes = 0,
        bool $isPrimary = true
    ): VolumeUsage {
        return new VolumeUsage(
            deviceId: '8',
            directories: [],
            isPrimary: $isPrimary,
            freeBytes: $freeBytes,
            totalBytes: $totalBytes,
            declaredQuotaBytes: $declaredQuotaBytes,
            occupiedBytes: $occupiedBytes
        );
    }
}
