<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\DiskSpace;
use PHPUnit\Framework\TestCase;

class DiskSpaceTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sizes(): array
    {
        return [
            'zero' => [0, '0 o'],
            'bytes' => [512, '512 o'],
            'kilobytes' => [2048, '2 Ko'],
            'megabytes' => [30 * 1024 * 1024, '30 Mo'],
            // One decimal from Go upwards, and only up to 100: « 340,0 Mo »
            // and « 512,0 Go » both read as more precise than the
            // measurement is, « 1,5 Go » does not.
            'gigabytes' => [(int) (1.5 * 1024 * 1024 * 1024), '1,5 Go'],
            'large gigabytes' => [512 * 1024 * 1024 * 1024, '512 Go'],
            'terabytes' => [3 * 1024 * 1024 * 1024 * 1024, '3,0 To'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function testBytesAreRenderedInFrench(int $bytes, string $expected): void
    {
        $this->assertSame($expected, DiskSpace::format($bytes));
    }

    /** A negative free space is a host answering nonsense, not a debt. */
    public function testANegativeSizeRendersAsZero(): void
    {
        $this->assertSame('0 o', DiskSpace::format(-1));
    }

    public function testUsedSpaceAndShareAreDerivedFromTheTotal(): void
    {
        $space = new DiskSpace(25 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024, 0);

        $this->assertSame(75 * 1024 * 1024 * 1024, $space->usedBytes());
        $this->assertSame(75, $space->usedPercent());
        $this->assertSame('25,0 Go', $space->freeLabel());
        $this->assertSame('100 Go', $space->totalLabel());
    }

    /**
     * A host that will not report its volume size has not said the disk is
     * empty — the same "unavailable is null, never 0" rule the statistics
     * payload follows (ARCHITECTURE.md §8.47).
     */
    public function testAnUnknownTotalIsNeverRenderedAsZero(): void
    {
        $space = new DiskSpace(4 * 1024 * 1024 * 1024, 0, 0);

        $this->assertNull($space->usedPercent());
        $this->assertSame('', $space->totalLabel());
        $this->assertSame(0, $space->usedBytes());
        $this->assertSame('4,0 Go', $space->freeLabel());
    }

    public function testSpaceIsLowOnlyBelowTheLargestAllowedUpload(): void
    {
        $twoGb = 2048 * 1024 * 1024;

        $this->assertTrue((new DiskSpace($twoGb - 1, 0, $twoGb))->isLow());
        $this->assertFalse((new DiskSpace($twoGb, 0, $twoGb))->isLow());
        $this->assertSame('2,0 Go', (new DiskSpace(0, 0, $twoGb))->warnBelowLabel());
    }

    /** No threshold configured is not "everything is low". */
    public function testNoThresholdNeverReadsAsLow(): void
    {
        $this->assertFalse((new DiskSpace(0, 0, 0))->isLow());
    }
}
