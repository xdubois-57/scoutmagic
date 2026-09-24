<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Volume;

use Core\Storage\Volume\VolumeUsage;
use PHPUnit\Framework\TestCase;

/**
 * One volume's arithmetic, in isolation from the inventory that builds it
 * (ARCHITECTURE.md §8.108).
 *
 * What this file is really about is the rule `ARCHITECTURE.md` §8.47
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
     * **The screen and the enforcer have to answer the same question.**
     * `DiskBudget::availableBytes()` charges the allowance against the
     * whole installation (`StorageUsage::quotaChargedBytes()`), `vendor/`
     * included; the declared directories are a narrower figure. Printing
     * the narrow one under a quota said « il reste de la place » where the
     * budget had less — over-reporting the room left, which is the
     * direction that ends in a truncated write.
     */
    public function testTheQuotaBasisCountsWhatTheBudgetChargesRatherThanTheDeclaredDirectories(): void
    {
        $volume = $this->volume(
            freeBytes: 50 * self::GIB,
            totalBytes: 100 * self::GIB,
            declaredQuotaBytes: 10 * self::GIB,
            occupiedBytes: 2 * self::GIB,
            quotaChargedBytes: 6 * self::GIB
        );

        $this->assertSame(6 * self::GIB, $volume->basisUsedBytes(), 'The installation footprint, not storage/ alone.');
        $this->assertSame(60, $volume->usedPercent());
        $this->assertSame(
            4 * self::GIB,
            $volume->availableBytes(),
            'What is left of the allowance, computed on the same figure the budget subtracts.'
        );
    }

    /**
     * Falls back on the declared directories when the installation could
     * not be walked — narrower than the truth, but it is what there is,
     * and `quotaChargedBytes()` upstream is a `max()` for the same reason.
     */
    public function testTheQuotaBasisFallsBackOnTheDeclaredDirectoriesWhenNothingChargedIsKnown(): void
    {
        $volume = $this->volume(
            freeBytes: 50 * self::GIB,
            totalBytes: 100 * self::GIB,
            declaredQuotaBytes: 10 * self::GIB,
            occupiedBytes: 2 * self::GIB,
            quotaChargedBytes: null
        );

        $this->assertSame(2 * self::GIB, $volume->basisUsedBytes());
    }

    /** Never narrower than the directories themselves, which are inside it. */
    public function testTheQuotaBasisIsNeverLessThanTheDeclaredDirectories(): void
    {
        $volume = $this->volume(
            freeBytes: 50 * self::GIB,
            totalBytes: 100 * self::GIB,
            declaredQuotaBytes: 10 * self::GIB,
            occupiedBytes: 7 * self::GIB,
            quotaChargedBytes: 3 * self::GIB
        );

        $this->assertSame(7 * self::GIB, $volume->basisUsedBytes());
    }

    /**
     * A volume the quota does not cover is untouched by any of this: the
     * hosting contract says nothing about a disk somebody mounted on.
     */
    public function testAVolumeWithoutAQuotaIsUnaffectedByTheChargedFigure(): void
    {
        $volume = $this->volume(
            freeBytes: 3 * self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: null,
            occupiedBytes: self::GIB,
            isPrimary: false
        );

        $this->assertSame(VolumeUsage::BASIS_VOLUME, $volume->basis());
        $this->assertSame(7 * self::GIB, $volume->basisUsedBytes(), 'The system figure, as before.');
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

    /**
     * All three, because the screen used to derive this with a two-way
     * ternary and printed « mesure système » for a volume that reports
     * nothing — contradicting the sentence on the same card.
     */
    public function testEveryBasisHasAShortLabelOfItsOwn(): void
    {
        $quota = $this->volume(
            freeBytes: self::GIB,
            totalBytes: 10 * self::GIB,
            declaredQuotaBytes: 4 * self::GIB
        );
        $system = $this->volume(freeBytes: self::GIB, totalBytes: 10 * self::GIB);
        $nothing = $this->volume(freeBytes: null, totalBytes: null);

        $this->assertSame('quota déclaré', $quota->basisLabel());
        $this->assertSame('mesure système', $system->basisLabel());
        $this->assertSame('aucune mesure', $nothing->basisLabel());

        $this->assertNotSame(
            $system->basisLabel(),
            $nothing->basisLabel(),
            'A volume that reports nothing must not read as one the system measured.'
        );
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
        bool $isPrimary = true,
        ?int $quotaChargedBytes = null
    ): VolumeUsage {
        return new VolumeUsage(
            deviceId: '8',
            directories: [],
            isPrimary: $isPrimary,
            freeBytes: $freeBytes,
            totalBytes: $totalBytes,
            declaredQuotaBytes: $declaredQuotaBytes,
            occupiedBytes: $occupiedBytes,
            quotaChargedBytes: $quotaChargedBytes
        );
    }
}
