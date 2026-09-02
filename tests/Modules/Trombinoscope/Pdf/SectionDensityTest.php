<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Pdf;

use Modules\Trombinoscope\Pdf\SectionDensity;
use PHPUnit\Framework\TestCase;

/**
 * A section page tightens rather than spilling: half a staff on a page
 * nobody has a reason to print is worse than smaller portraits.
 */
class SectionDensityTest extends TestCase
{
    public function testThreeColumnsHoldSixToNineAnimateurs(): void
    {
        $this->assertSame(3, SectionDensity::forStaffCount(6)->columns);
        $this->assertSame(3, SectionDensity::forStaffCount(9)->columns);
    }

    public function testBeyondNineItTightensInsteadOfOverflowing(): void
    {
        $roomy = SectionDensity::forStaffCount(9);
        $tight = SectionDensity::forStaffCount(10);
        $compact = SectionDensity::forStaffCount(17);

        $this->assertSame(4, $tight->columns);
        $this->assertSame(5, $compact->columns);
        $this->assertLessThan($roomy->portrait, $tight->portrait);
        $this->assertLessThan($tight->portrait, $compact->portrait);
    }

    public function testNamesGetShorterAsTheGridGetsDenser(): void
    {
        // Totems and civil names MAY be shortened; addresses never are, and
        // no limit here applies to one.
        $this->assertGreaterThan(
            SectionDensity::forStaffCount(20)->nameLimit,
            SectionDensity::forStaffCount(6)->nameLimit
        );
    }
}
