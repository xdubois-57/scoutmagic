<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Pdf;

use Modules\Trombinoscope\Pdf\DirectoryDensity;
use PHPUnit\Framework\TestCase;

/**
 * The directory page packs itself in steps rather than by continuous
 * scaling, and this is the test that pays for that choice: a step is
 * something that can be asserted, "shrink by 7%" is not.
 */
class DirectoryDensityTest extends TestCase
{
    public function testUpToSixSectionsGetsTwoColumnsAndTheLargestPortrait(): void
    {
        $small = DirectoryDensity::forSectionCount(4);
        $edge = DirectoryDensity::forSectionCount(6);

        $this->assertSame(2, $small->columns);
        $this->assertSame(2, $edge->columns);
        $this->assertSame($small->portrait, $edge->portrait);
    }

    public function testSevenToTenSectionsStayOnTwoColumnsButTighten(): void
    {
        $large = DirectoryDensity::forSectionCount(6);
        $tight = DirectoryDensity::forSectionCount(7);

        $this->assertSame(2, $tight->columns);
        $this->assertLessThan($large->portrait, $tight->portrait);
        $this->assertLessThan($large->totemSize, $tight->totemSize);
        $this->assertSame($tight->portrait, DirectoryDensity::forSectionCount(10)->portrait);
    }

    public function testBeyondTenSectionsGoesToThreeColumns(): void
    {
        $tight = DirectoryDensity::forSectionCount(10);
        $compact = DirectoryDensity::forSectionCount(11);

        $this->assertSame(3, $compact->columns);
        $this->assertLessThan($tight->portrait, $compact->portrait);
        $this->assertSame(3, DirectoryDensity::forSectionCount(40)->columns);
    }

    public function testEveryStepShrinksMonotonically(): void
    {
        // Whatever the numbers become, denser must never mean bigger — the
        // one property the three tables have to keep between them.
        $steps = [
            DirectoryDensity::forSectionCount(6),
            DirectoryDensity::forSectionCount(10),
            DirectoryDensity::forSectionCount(11),
        ];

        for ($i = 1; $i < count($steps); $i++) {
            $this->assertLessThan($steps[$i - 1]->portrait, $steps[$i]->portrait);
            $this->assertLessThan($steps[$i - 1]->band, $steps[$i]->band);
            $this->assertLessThan($steps[$i - 1]->sectionNameSize, $steps[$i]->sectionNameSize);
            $this->assertLessThan($steps[$i - 1]->contactSize, $steps[$i]->contactSize);
            $this->assertLessThan($steps[$i - 1]->gap, $steps[$i]->gap);
        }
    }

    public function testTheFooterHasItsOwnThreshold(): void
    {
        // Eight, not ten: a footer line is one address while a card is a
        // portrait and up to five lines, so they do not need a third
        // column at the same point.
        $density = DirectoryDensity::forSectionCount(9);

        $this->assertSame(2, $density->footerColumns(8));
        $this->assertSame(3, $density->footerColumns(9));
    }

    public function testTheFooterShrinksItsTypeRatherThanItsAddresses(): void
    {
        $density = DirectoryDensity::forSectionCount(14);

        $this->assertLessThan($density->footerSize(10), $density->footerSize(11));
    }
}
