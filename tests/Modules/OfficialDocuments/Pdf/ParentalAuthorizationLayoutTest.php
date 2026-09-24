<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\ParentalAuthorizationLayout;
use Modules\OfficialDocuments\Pdf\StrikeZone;
use Modules\OfficialDocuments\Pdf\TextField;
use PHPUnit\Framework\TestCase;

/**
 * What a test CAN say about a coordinate map, said here — and what it
 * cannot, said out loud so nobody mistakes a green run for a correct
 * document.
 *
 * It can say that every declared position is on the page and that no name
 * the service writes to has gone missing: both are ways the map breaks
 * silently, producing a document that is generated, downloaded, printed and
 * wrong.
 *
 * It cannot say that a value is opposite the right dotted line. That needs
 * `php scripts/pdf-template-grid.php` and a pair of eyes, which is why the
 * template fingerprint test exists beside this one: it refuses to let the
 * background change without somebody looking.
 */
final class ParentalAuthorizationLayoutTest extends TestCase
{
    /**
     * A field partly off the sheet is a value half-printed, and the PDF
     * comes out without an error either way.
     */
    public function testEveryFieldFitsOnThePage(): void
    {
        foreach (ParentalAuthorizationLayout::textFields() as $name => $field) {
            $this->assertGreaterThan(0.0, $field->x, "{$name}: x is off the page");
            $this->assertGreaterThan(0.0, $field->baselineY, "{$name}: the baseline is off the page");
            $this->assertLessThanOrEqual(
                ParentalAuthorizationLayout::PAGE_WIDTH_MM,
                $field->right(),
                "{$name}: the width runs past the right edge"
            );
            $this->assertLessThanOrEqual(
                ParentalAuthorizationLayout::PAGE_HEIGHT_MM,
                $field->baselineY,
                "{$name}: the baseline sits below the bottom edge"
            );
            $this->assertGreaterThan(0.0, $field->width, "{$name}: width is zero or negative");
            $this->assertGreaterThanOrEqual(6.0, $field->fontSize, "{$name}: the font size is unreadable");
        }
    }

    public function testEveryStrikeFitsOnThePage(): void
    {
        foreach (ParentalAuthorizationLayout::strikeZones() as $name => $zone) {
            $this->assertGreaterThan(0.0, $zone->x, "{$name}: x is off the page");
            $this->assertGreaterThan(0.0, $zone->y, "{$name}: y is off the page");
            $this->assertGreaterThan(0.0, $zone->width, "{$name}: width is zero or negative");
            $this->assertLessThanOrEqual(
                ParentalAuthorizationLayout::PAGE_WIDTH_MM,
                $zone->right(),
                "{$name}: the rule runs past the right edge"
            );
            $this->assertLessThanOrEqual(
                ParentalAuthorizationLayout::PAGE_HEIGHT_MM,
                $zone->y,
                "{$name}: the rule sits below the bottom edge"
            );
        }
    }

    /**
     * The map's own list against what it actually contains. A name dropped
     * from one of the two arrays leaves a blank on the form and a PHP notice
     * nobody reads.
     */
    public function testNoExpectedNameIsMissing(): void
    {
        $declared = array_merge(
            array_keys(ParentalAuthorizationLayout::textFields()),
            array_keys(ParentalAuthorizationLayout::strikeZones())
        );

        sort($declared);
        $required = ParentalAuthorizationLayout::requiredNames();
        sort($required);

        $this->assertSame($required, $declared);
    }

    /**
     * The four capacities and the four branches are read side by side on the
     * printed form, so their strokes belong on one line each — a stroke a
     * millimetre off its neighbours reads as a smudge rather than as a
     * cancellation.
     */
    public function testTheMentionsCrossedOutTogetherShareTheirLine(): void
    {
        $zones = ParentalAuthorizationLayout::strikeZones();

        $this->assertSameLine($zones, ['capacity_father', 'capacity_mother', 'capacity_guardian', 'capacity_sponsor']);
        $this->assertSameLine($zones, ['branch_baladins', 'branch_louveteaux', 'branch_eclaireurs', 'branch_pionniers']);
    }

    /**
     * Those same two sets are read left to right, and each word has its own
     * stroke: two that overlap would cross a word nobody asked to cancel.
     */
    public function testTheMentionsCrossedOutTogetherDoNotOverlap(): void
    {
        $zones = ParentalAuthorizationLayout::strikeZones();

        foreach (
            [
                ['capacity_father', 'capacity_mother', 'capacity_guardian', 'capacity_sponsor'],
                ['branch_baladins', 'branch_louveteaux', 'branch_eclaireurs', 'branch_pionniers'],
            ] as $row
        ) {
            for ($i = 1; $i < count($row); $i++) {
                $this->assertGreaterThan(
                    $zones[$row[$i - 1]]->right(),
                    $zones[$row[$i]]->x,
                    "{$row[$i]} starts before {$row[$i - 1]} ends"
                );
            }
        }
    }

    /**
     * @param array<string, StrikeZone> $zones
     * @param list<string> $names
     */
    private function assertSameLine(array $zones, array $names): void
    {
        $first = $zones[$names[0]]->y;
        foreach ($names as $name) {
            $this->assertSame($first, $zones[$name]->y, "{$name}: is not on the same line as its neighbours");
        }
    }

    public function testTheMapDeclaresTheTypesItSaysItDoes(): void
    {
        foreach (ParentalAuthorizationLayout::textFields() as $field) {
            $this->assertInstanceOf(TextField::class, $field);
        }

        foreach (ParentalAuthorizationLayout::strikeZones() as $zone) {
            $this->assertInstanceOf(StrikeZone::class, $zone);
        }
    }
}
