<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\HealthSheetLayout;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Pdf\TextField;
use Modules\OfficialDocuments\Pdf\TickBox;
use Modules\OfficialDocuments\Service\HealthSheetFilling;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Sixty positions on a two-page form, checked against the form itself.
 *
 * ---------------------------------------------------------------------
 * WHY THIS READS THE TEMPLATE RATHER THAN A LIST OF EXPECTED NUMBERS
 * ---------------------------------------------------------------------
 *
 * A test that repeated the coordinates would agree with whatever the layout
 * said, including a typo. What can actually go wrong here is a value
 * written three millimetres beside the dotted line it belongs on — the
 * document still generates, still looks plausible in a thumbnail, and is
 * wrong on every copy a unit prints.
 *
 * So the template's OWN printed baselines are extracted from the PDF and
 * every position is checked to land on one of them. That is a fact about
 * the federation's form, not about this code, and it is exactly the fact
 * that stops holding the day the form is republished with a different
 * margin. `TemplateFingerprintTest` catches the replacement; this catches
 * what the replacement did to the alignment.
 *
 * The other half is completeness, and it is not decorative: a layout that
 * simply forgot « allergies_2 » would drop half a parent's answer with no
 * error anywhere.
 */
final class HealthSheetLayoutTest extends TestCase
{
    /**
     * A4 in PDF points, which is what the template's own coordinates are
     * in. 842 pt is 297.04 mm — near enough to
     * `HealthSheetLayout::PAGE_HEIGHT_MM` that the difference is well
     * inside the tolerance below, and using the template's own number keeps
     * the conversion honest.
     */
    private const PAGE_HEIGHT_PT = 842.0;

    /** How far a position may sit from a printed baseline. */
    private const TOLERANCE_MM = 0.4;

    /**
     * Every baseline the template prints, in millimetres from the top,
     * keyed by page.
     *
     * @return array<int, list<float>>
     */
    private static function printedBaselines(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $document = (new Parser())->parseFile(TemplateLibrary::shipped()->path(TemplateLibrary::HEALTH_SHEET));

        $baselines = [];
        foreach ($document->getPages() as $index => $page) {
            $page1Based = $index + 1;
            foreach ($page->getDataTm() as $fragment) {
                [$tm] = $fragment;
                $baselines[$page1Based][] = self::PAGE_HEIGHT_PT * 25.4 / 72 - ((float) $tm[5]) * 25.4 / 72;
            }
        }

        return $cache = $baselines;
    }

    private static function isPrinted(int $page, float $y): bool
    {
        foreach (self::printedBaselines()[$page] ?? [] as $printed) {
            if (abs($printed - $y) <= self::TOLERANCE_MM) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // The form itself
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string, TextField}>
     */
    public static function textFieldProvider(): array
    {
        $cases = [];
        foreach (HealthSheetLayout::textFields() as $name => $field) {
            $cases[$name] = [$name, $field];
        }

        return $cases;
    }

    #[DataProvider('textFieldProvider')]
    public function testEveryLineSitsOnOneTheFormPrints(string $name, TextField $field): void
    {
        $this->assertContains($field->page, [1, 2], $name . ' : page inconnue.');
        $this->assertTrue(
            self::isPrinted($field->page, $field->baselineY),
            sprintf(
                '%s est écrit à %.1f mm sur la page %d, où le gabarit n\'imprime aucune ligne. '
                . 'La fédération a-t-elle republié le formulaire ? Voir le docblock de HealthSheetLayout.',
                $name,
                $field->baselineY,
                $field->page
            )
        );
    }

    /**
     * And inside the paper, with room to write: a field whose width ran
     * past the right margin would print over whatever the form has there.
     */
    #[DataProvider('textFieldProvider')]
    public function testEveryLineStaysOnThePage(string $name, TextField $field): void
    {
        $this->assertGreaterThan(15.0, $field->x, $name . ' starts inside the left margin.');
        $this->assertLessThanOrEqual(
            HealthSheetLayout::PAGE_WIDTH_MM - 15.0,
            $field->right(),
            $name . ' runs over into the right margin.'
        );
        // The shortest printed line on this form is the 11 mm stub of
        // « Mentionnez toute information utile » — anything narrower than
        // that is a coordinate that slipped, not a line.
        $this->assertGreaterThanOrEqual(11.0, $field->width, $name . ' has almost no width at all.');
    }

    /**
     * @return array<string, array{string, TickBox}>
     */
    public static function tickBoxProvider(): array
    {
        $cases = [];
        foreach (HealthSheetLayout::tickBoxes() as $name => $box) {
            $cases[$name] = [$name, $box];
        }

        return $cases;
    }

    /**
     * A square sits ON a printed line too — its bottom edge is that line's
     * baseline, give or take the fraction the glyph is raised by.
     */
    #[DataProvider('tickBoxProvider')]
    public function testEverySquareSitsOnALineTheFormPrints(string $name, TickBox $box): void
    {
        $this->assertContains($box->page, [1, 2], $name . ' : page inconnue.');
        $this->assertTrue(
            self::isPrinted($box->page, $box->y + $box->size),
            sprintf(
                '%s est cochée à %.2f mm sur la page %d, où le gabarit n\'imprime rien.',
                $name,
                $box->y + $box->size,
                $box->page
            )
        );
        // Both sizes the form uses, and nothing in between: a box that
        // came out 0.4 mm wide is a mistyped coordinate.
        $this->assertGreaterThanOrEqual(1.0, $box->size, $name);
        $this->assertLessThanOrEqual(3.0, $box->size, $name);
    }

    /**
     * No two squares in the same place. Two answers sharing a coordinate is
     * a copy-paste that crosses « asthme » when a parent said « diabète »,
     * and nothing downstream would notice.
     */
    public function testNoTwoSquaresShareAPlace(): void
    {
        $seen = [];
        foreach (HealthSheetLayout::tickBoxes() as $name => $box) {
            $key = sprintf('%d:%.2f:%.2f', $box->page, $box->x, $box->y);
            $this->assertArrayNotHasKey($key, $seen, $name . ' sits in the same place as ' . ($seen[$key] ?? ''));
            $seen[$key] = $name;
        }
    }

    /**
     * Same for the lines, which would silently overwrite each other.
     */
    public function testNoTwoLinesShareAPlace(): void
    {
        $seen = [];
        foreach (HealthSheetLayout::textFields() as $name => $field) {
            $key = sprintf('%d:%.2f:%.2f', $field->page, $field->x, $field->baselineY);
            $this->assertArrayNotHasKey($key, $seen, $name . ' sits in the same place as ' . ($seen[$key] ?? ''));
            $seen[$key] = $name;
        }
    }

    // ---------------------------------------------------------------
    // Completeness
    // ---------------------------------------------------------------

    /**
     * Every continuation line a paragraph claims actually exists, and in
     * the order the form prints it — top to bottom, page 1 before page 2.
     */
    public function testEveryParagraphLineExistsAndRunsDownThePage(): void
    {
        $fields = HealthSheetLayout::textFields();

        foreach (HealthSheetLayout::paragraphLines() as $answer => $names) {
            $this->assertNotEmpty($names, $answer . ' has no printed line.');

            $previous = null;
            foreach ($names as $name) {
                $this->assertArrayHasKey($name, $fields, $answer . ' asks for ' . $name . ', which is not in the layout.');
                $field = $fields[$name];
                if ($previous !== null) {
                    $this->assertGreaterThan(
                        [$previous->page, $previous->baselineY],
                        [$field->page, $field->baselineY],
                        $answer . ' — ' . $name . ' is printed above the line before it.'
                    );
                }
                $previous = $field;
            }
        }
    }

    /**
     * **The answer a continuation line belongs to is the name it carries.**
     * The screen reports an overflow under that name, so `allergies_2`
     * belonging to anything but `allergies` would tell a parent to shorten
     * a box that is not the one they filled in.
     */
    public function testEachParagraphLineIsNamedAfterItsAnswer(): void
    {
        foreach (HealthSheetLayout::paragraphLines() as $answer => $names) {
            foreach ($names as $index => $name) {
                $this->assertSame($answer . '_' . ($index + 1), $name);
            }
        }
    }

    /**
     * Every free-text answer the sheet holds has somewhere to go, and every
     * paragraph the layout declares is an answer that exists.
     *
     * The first direction is the one that costs a family something: an
     * answer with no printed line is an answer that never appears on the
     * document, with no error anywhere.
     */
    public function testTheParagraphsAreExactlyTheFreeTextAnswers(): void
    {
        $this->assertSame(
            array_keys(HealthSheetFilling::paragraphs(HealthSheet::empty())),
            array_keys(HealthSheetLayout::paragraphLines())
        );
    }

    /**
     * Every value the filling class produces has a place on the form —
     * counting the continuation lines the paragraphs resolve to.
     */
    public function testEveryValueTheSiteWritesHasAPlace(): void
    {
        $fields = HealthSheetLayout::textFields();

        foreach (array_keys(HealthSheetFilling::values(
            \Tests\Modules\OfficialDocuments\Service\HealthSheetFillingTest::member(),
            HealthSheet::empty()
        )) as $name) {
            $this->assertArrayHasKey($name, $fields, $name . ' is written but has no line.');
        }
    }

    /**
     * And the other way: no line of the plan is left unwritten. A field
     * nobody fills is a dotted line the parent has to complete by hand on a
     * form the site claims to have pre-filled.
     */
    public function testNoLineOfThePlanIsLeftUnwritten(): void
    {
        $written = array_keys(HealthSheetFilling::values(
            \Tests\Modules\OfficialDocuments\Service\HealthSheetFillingTest::member(),
            HealthSheet::empty()
        ));
        foreach (HealthSheetLayout::paragraphLines() as $names) {
            $written = array_merge($written, $names);
        }

        foreach (array_keys(HealthSheetLayout::textFields()) as $name) {
            $this->assertContains($name, $written, $name . ' is in the layout but nothing writes it.');
        }
    }

    /**
     * The twelve conditions have twelve squares, under the names the
     * filling class builds — `condition_` plus the key.
     */
    public function testEveryConditionHasItsOwnSquare(): void
    {
        $boxes = HealthSheetLayout::tickBoxes();

        foreach (HealthSheet::CONDITIONS as $condition) {
            $this->assertArrayHasKey('condition_' . $condition, $boxes, $condition);
        }

        $conditionBoxes = array_filter(
            array_keys($boxes),
            static fn(string $name): bool => str_starts_with($name, 'condition_')
        );
        $this->assertCount(count(HealthSheet::CONDITIONS), $conditionBoxes);
    }

    /**
     * And the five swimming answers, plus the eight OUI/NON pairs the form
     * prints. Named one by one rather than counted: a pair with a missing
     * half crosses nothing for half the families who answer it.
     */
    public function testEverySquareTheFillingCanCrossIsOnThePlan(): void
    {
        $boxes = HealthSheetLayout::tickBoxes();

        $expected = array_merge(
            array_values(HealthSheetFilling::SWIMMING_TICKS),
            [
                'participation_yes', 'participation_no',
                'tetanus_yes', 'tetanus_no',
                'allergic_yes', 'allergic_no',
                'treatment_yes', 'treatment_no',
                'autonomy_yes', 'autonomy_no',
            ]
        );

        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $boxes, $name);
        }
    }

    /**
     * Each swimming level has its own square, and no two share one.
     */
    public function testTheSwimmingLevelsMapOneToOne(): void
    {
        $this->assertSame(
            array_values(array_filter(HealthSheet::SWIMMING_LEVELS)),
            array_keys(HealthSheetFilling::SWIMMING_TICKS)
        );
        $this->assertSame(
            HealthSheetFilling::SWIMMING_TICKS,
            array_unique(HealthSheetFilling::SWIMMING_TICKS)
        );
    }

    /**
     * The identity lines are exactly the ones a parent cannot reach, and
     * they are real lines of this plan.
     *
     * The screen subtracts this list from what overflowed, so a name in it
     * that the plan does not carry would silently stop warning about
     * nothing, and a name missing from it would tell a parent to shorten
     * their own child's street.
     */
    public function testTheSiteSuppliedLinesAreLinesOfThisPlan(): void
    {
        $fields = HealthSheetLayout::textFields();
        $answers = array_keys(HealthSheet::empty()->toArray());

        foreach (HealthSheetLayout::siteSuppliedNames() as $name) {
            $this->assertArrayHasKey($name, $fields, $name . ' is not in the layout.');
            $this->assertNotContains($name, $answers, $name . ' is a form answer, not a line the site supplies.');
        }
    }

    /**
     * Two pages, because the health sheet has two and a monopage
     * assumption is the trap the chantier names.
     */
    public function testTheTemplateReallyHasTheDeclaredNumberOfPages(): void
    {
        $document = (new Parser())->parseFile(TemplateLibrary::shipped()->path(TemplateLibrary::HEALTH_SHEET));

        $this->assertCount(HealthSheetLayout::PAGE_COUNT, $document->getPages());
    }

    /**
     * And the form really does run one answer across the page break, which
     * is the reason `TextField` carries a page at all.
     */
    public function testOneAnswerRunsAcrossThePageBreak(): void
    {
        $fields = HealthSheetLayout::textFields();
        $pages = array_map(
            static fn(string $name): int => $fields[$name]->page,
            HealthSheetLayout::paragraphLines()['illnesses_and_operations']
        );

        $this->assertSame([1, 1, 2], $pages);
    }
}
