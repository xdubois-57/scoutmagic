<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\PhotoMemoryBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1449: a photo under the 50 MP ceiling can still need more memory
 * than memory_limit leaves, and running out is a fatal no catch survives.
 */
final class PhotoMemoryBudgetTest extends TestCase
{
    private const MIB = 1024 * 1024;

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function memoryLimits(): array
    {
        return [
            'plain bytes' => ['268435456', 268435456],
            'kilobytes' => ['512K', 512 * 1024],
            'lowercase kilobytes' => ['512k', 512 * 1024],
            'megabytes' => ['128M', 128 * self::MIB],
            'lowercase megabytes' => ['256m', 256 * self::MIB],
            'gigabytes' => ['1G', 1024 * self::MIB],
            'surrounding spaces' => [' 64M ', 64 * self::MIB],
            'unlimited' => ['-1', null],
            'empty' => ['', null],
            'unreadable' => ['beaucoup', null],
            'zero' => ['0', null],
        ];
    }

    #[DataProvider('memoryLimits')]
    public function testParsesAMemoryLimitTheWayPhpReadsIt(string $value, ?int $expected): void
    {
        $this->assertSame($expected, PhotoMemoryBudget::parseMemoryLimit($value));
    }

    public function testAvailableIsTheLimitMinusWhatIsAlreadyReserved(): void
    {
        $this->assertSame(156 * self::MIB, PhotoMemoryBudget::fixed('256M', 100 * self::MIB)->availableBytes());
    }

    public function testAvailableIsNeverNegative(): void
    {
        $this->assertSame(0, PhotoMemoryBudget::fixed('64M', 100 * self::MIB)->availableBytes());
    }

    public function testAnUnlimitedHostHasNoAvailableFigureAndRefusesNothing(): void
    {
        $budget = PhotoMemoryBudget::fixed('-1', 100 * self::MIB);

        $this->assertNull($budget->availableBytes());
        $this->assertNull($budget->shortfall(8000, 6000, true, 3000));
    }

    public function testTheLimitAndUsageAreReadAtCheckTimeNotAtConstruction(): void
    {
        $usage = 10 * self::MIB;
        $budget = new PhotoMemoryBudget(static fn(): string => '128M', static function () use (&$usage): int {
            return $usage;
        });

        $this->assertSame(118 * self::MIB, $budget->availableBytes());
        $usage = 100 * self::MIB;
        $this->assertSame(28 * self::MIB, $budget->availableBytes());
    }

    public function testTheDefaultReadersAreTheRealIniValueAndUsage(): void
    {
        $limit = PhotoMemoryBudget::parseMemoryLimit((string) ini_get('memory_limit'));
        $available = (new PhotoMemoryBudget())->availableBytes();

        if ($limit === null) {
            $this->assertNull($available);
        } else {
            $this->assertNotNull($available);
            $this->assertLessThanOrEqual($limit, $available);
        }
    }

    /**
     * The estimate's core: a 48 MP bitmap at 4 bytes per pixel is ~192 MB
     * before anything else, which is the order of magnitude the issue
     * describes.
     */
    public function testA48MegapixelBitmapIsCountedAtFourBytesPerPixel(): void
    {
        $required = PhotoMemoryBudget::fixed('-1', 0)->requiredBytes(8000, 6000, false, 3000);

        $this->assertGreaterThanOrEqual(8000 * 6000 * PhotoMemoryBudget::BYTES_PER_PIXEL, $required);
        $this->assertLessThan(8000 * 6000 * PhotoMemoryBudget::BYTES_PER_PIXEL * 1.5, $required);
    }

    public function testARotationCountsASecondBitmapBesideTheFirst(): void
    {
        $budget = PhotoMemoryBudget::fixed('-1', 0);
        $plain = $budget->requiredBytes(8000, 6000, false, 3000);
        $rotated = $budget->requiredBytes(8000, 6000, true, 3000);

        // The second bitmap replaces the (smaller) large canvas as the
        // biggest thing held beside the source bitmap.
        $this->assertGreaterThan($plain + 100 * self::MIB, $rotated);
    }

    /**
     * The file string is counted once, by the usage figure (#1558 review):
     * it is already read when the check runs, so a larger file lowers
     * what is available and adds nothing to what is required.
     */
    public function testTheFileStringIsCountedOnlyThroughTheUsage(): void
    {
        $required = PhotoMemoryBudget::fixed('-1', 0)->requiredBytes(4000, 3000, false, 3000);
        $limit = (string) ($required + 30 * self::MIB);

        $this->assertNull(PhotoMemoryBudget::fixed($limit, 20 * self::MIB)->shortfall(4000, 3000, false, 3000));
        $this->assertNotNull(PhotoMemoryBudget::fixed($limit, 40 * self::MIB)->shortfall(4000, 3000, false, 3000));
    }

    public function testRefusesAPhotoWhoseNeedExceedsTheSimulatedLimit(): void
    {
        // A 48 MP phone photo on a 256 MB host already using 20 MB.
        $shortfall = PhotoMemoryBudget::fixed('256M', 20 * self::MIB)->shortfall(8000, 6000, true, 3000);

        $this->assertNotNull($shortfall);
        $this->assertSame(236 * self::MIB, $shortfall['available']);
        $this->assertGreaterThan($shortfall['available'], $shortfall['required']);
    }

    public function testAcceptsAnOrdinaryPhotoUnderTheSameLimit(): void
    {
        // A 12 MP photo, the common case, on the same host.
        $this->assertNull(
            PhotoMemoryBudget::fixed('256M', 20 * self::MIB)->shortfall(4000, 3000, true, 3000)
        );
    }
}
