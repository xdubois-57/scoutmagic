<?php

declare(strict_types=1);

namespace Tests\Core\Member\Pdf;

use Core\Member\Movement\MemberMovementStatus;
use Core\Member\Pdf\RosterMemberView;
use Core\Member\Pdf\RosterSectionView;
use Core\Member\Pdf\SectionRosterHtmlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The roll-call sheet's markup, asserted as a string — the layer below
 * dompdf, so what the document says can be checked without rendering a
 * PDF to look at it.
 */
class SectionRosterHtmlBuilderTest extends TestCase
{
    private function member(
        string $last,
        string $first,
        ?string $totem = null,
        MemberMovementStatus $movement = MemberMovementStatus::CONTINUING
    ): RosterMemberView {
        return new RosterMemberView($last, $first, $totem, $movement);
    }

    /**
     * @param RosterMemberView[] $leaders
     * @param RosterMemberView[] $youthMembers
     */
    private function section(
        string $name,
        string $color = '#198754',
        array $leaders = [],
        array $youthMembers = []
    ): RosterSectionView {
        return new RosterSectionView($name, 'Louveteaux', $color, $leaders, $youthMembers);
    }

    /** @param RosterSectionView[] $sections */
    private function build(array $sections): string
    {
        return (new SectionRosterHtmlBuilder())
            ->build($sections, 'Unité SV025', '2026-2027', 'www.sv025.be');
    }

    /**
     * The document minus its stylesheet. Several assertions below are
     * about what the sheet SAYS, and the CSS legitimately contains the
     * same words (`@page`, the `.page-break` rule itself) — matching
     * against it would make them pass or fail for the wrong reason.
     *
     * @param RosterSectionView[] $sections
     */
    private function buildBody(array $sections): string
    {
        return (string) preg_replace('~<style>.*?</style>~s', '', $this->build($sections));
    }

    /**
     * Section names are copied from the import untouched — normalizeName()
     * only ever sees member names — so a file saved as Latin-1 can put a
     * lone malformed byte in one. Escaped without ENT_SUBSTITUTE, that one
     * byte makes htmlspecialchars() return '' and the whole name leaves
     * the sheet: the animateur is handed a roll call with a blank title.
     */
    public function testAMalformedByteInASectionNameCostsOnlyThatCharacter(): void
    {
        $html = $this->buildBody([$this->section("Zephyrs \xB1 Astral")]);

        $this->assertStringContainsString('Zephyrs', $html);
        $this->assertStringContainsString('Astral', $html);
    }

    public function testEachSectionGetsItsOwnSheet(): void
    {
        $html = $this->build([
            $this->section('Louveteaux 1'),
            $this->section('Éclaireurs 1', '#fd7e14'),
        ]);

        $this->assertStringContainsString('Louveteaux 1', $html);
        $this->assertStringContainsString('Éclaireurs 1', $html);
        // One break, not two: the first sheet starts the document.
        $this->assertSame(1, substr_count($html, 'class="sheet page-break"'));
    }

    /**
     * A sheet is torn off and handed to one section's animateur, so two
     * sections never share one.
     */
    public function testASingleSectionNeverStartsWithAPageBreak(): void
    {
        $this->assertStringNotContainsString(
            'class="sheet page-break"',
            $this->build([$this->section('Louveteaux 1')])
        );
    }

    public function testTheSectionNameIsDrawnInTheSectionsOwnColour(): void
    {
        $html = $this->build([$this->section('Éclaireurs 1', '#fd7e14')]);

        $this->assertStringContainsString('color:#fd7e14', $html);
    }

    /**
     * The colour comes from the page's data (Core\Member\SectionService::
     * colorForSection()), which validates `#RRGGBB` at write time. The
     * guard is for what that cannot cover, and it must never emit the
     * value it was given.
     */
    public function testAnInvalidColourFallsBackInsteadOfBeingEmitted(): void
    {
        $html = $this->build([$this->section('Louveteaux 1', 'red; background:url(x)')]);

        $this->assertStringNotContainsString('url(x)', $html);
        $this->assertStringContainsString('#6c757d', $html);
    }

    /**
     * A hex string of the wrong length is not a colour either, and it is
     * the likelier accident than an injection attempt: a hand-edited row,
     * an import that dropped a character. Emitted, it silently strips the
     * colour from the banner and the border instead of falling back.
     *
     * @return array<string, array{string}>
     */
    public static function malformedHexProvider(): array
    {
        return [
            'five digits' => ['#12345'],
            'seven digits' => ['#1234567'],
            'three digits' => ['#123'],
            'eight digits' => ['#12345678'],
        ];
    }

    #[DataProvider('malformedHexProvider')]
    public function testAHexColourOfTheWrongLengthFallsBackToo(string $color): void
    {
        $html = $this->build([$this->section('Louveteaux 1', $color)]);

        $this->assertStringNotContainsString($color, $html);
        $this->assertStringContainsString('#6c757d', $html);
    }

    public function testEachGroupCarriesAFullColourBannerWithItsHeadcount(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin')],
            [$this->member('Ayoute', 'Dounia'), $this->member('Hargot', 'Basile')]
        )]);

        $this->assertStringContainsString('background-color:#198754', $html);
        $this->assertStringContainsString('Animateurs · 1', $html);
        $this->assertStringContainsString('Animés · 2', $html);
    }

    /**
     * Intendants are absent by construction: RosterSectionView has no
     * bucket for them at all, so there is nothing here that could ever
     * print one.
     */
    public function testTheSheetHasNoIntendantsGroup(): void
    {
        $html = $this->build([$this->section('Louveteaux 1', '#198754', [$this->member('Mommens', 'Pascale')])]);

        $this->assertStringNotContainsString('Intendant', $html);
    }

    public function testEveryNameCarriesATickBox(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [],
            [$this->member('Ayoute', 'Dounia'), $this->member('Hargot', 'Basile')]
        )]);

        $this->assertSame(2, substr_count($html, 'border:0.3mm solid #6c757d'));
    }

    public function testANameIsSurnameFirstThenTotem(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin', 'Chacal')]
        )]);

        $this->assertMatchesRegularExpression('/Grandjean<\/span>, Antonin.*Chacal/s', $html);
    }

    public function testANotableMovementCarriesItsBadgeAndAContinuingOneDoesNot(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [],
            [
                $this->member('Ayoute', 'Dounia', null, MemberMovementStatus::NEW),
                $this->member('Hargot', 'Basile', null, MemberMovementStatus::CONTINUING),
            ]
        )]);

        // Once in the legend plus once on Dounia's row; Basile has none.
        $this->assertSame(2, substr_count($html, MemberMovementStatus::NEW->label()));
        $this->assertStringNotContainsString(MemberMovementStatus::CONTINUING->label(), $html);
    }

    /**
     * The legend sits in the sheet's `<thead>`, which is what makes dompdf
     * repeat it on the second page of a section large enough to overflow —
     * a detached sheet ends up in hands that never had the first one.
     */
    public function testTheLegendSitsInTheRepeatedHeader(): void
    {
        $html = $this->build([$this->section('Louveteaux 1')]);

        $head = substr($html, (int) strpos($html, '<thead>'), (int) strpos($html, '</thead>') - (int) strpos($html, '<thead>'));
        foreach ([MemberMovementStatus::NEW, MemberMovementStatus::SECTION_CHANGE,
                  MemberMovementStatus::BRANCH_CHANGE, MemberMovementStatus::RETURNING] as $status) {
            $this->assertStringContainsString($status->label(), $head);
        }
        $this->assertStringContainsString('sans badge : continuité', $head);
        $this->assertStringContainsString('Louveteaux 1', $head);
    }

    public function testTheHeaderCountsAnimateursAnimesAndMovements(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin')],
            [
                $this->member('Ayoute', 'Dounia', null, MemberMovementStatus::NEW),
                $this->member('Biernaux', 'Gabriel', null, MemberMovementStatus::BRANCH_CHANGE),
                $this->member('Hargot', 'Basile'),
            ]
        )]);

        $this->assertStringContainsString('1 animateur · 3 animés · 2 mouvements', $html);
    }

    /**
     * This document circulates in a local on a rentrée day, in hands that
     * are not all the staff's. It carries no contact — and unlike the
     * printable trombinoscope it has no setting for that, because the data
     * never reaches RosterMemberView in the first place.
     */
    public function testTheSheetCarriesNoContactAtAll(): void
    {
        $body = $this->buildBody([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin', 'Chacal')]
        )]);

        $this->assertStringNotContainsString('@', $body);
        $this->assertStringNotContainsString('mailto', $body);
        $this->assertStringNotContainsString('tel:', $body);
        $this->assertDoesNotMatchRegularExpression('/\b0\d{3}[\s.]?\d{2}[\s.]?\d{2}[\s.]?\d{2}\b/', $body);
    }

    /**
     * Never a height on a table: one given the remaining height
     * distributes the surplus between its rows and produces an enormous
     * line spacing. Under dompdf the composition is tables all the way
     * down, so this has to hold for every one of them.
     */
    public function testNoTableIsEverGivenAHeight(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin')],
            [$this->member('Ayoute', 'Dounia')]
        )]);

        preg_match_all('/<table[^>]*>/', $html, $tables);
        $this->assertNotEmpty($tables[0]);
        foreach ($tables[0] as $table) {
            $this->assertStringNotContainsString('height', $table);
        }
    }

    /**
     * The invariant the overflow rests on, and the one that regressed
     * once: every printed line is a ROW of the sheet's own table, banners
     * and spacer included. dompdf cannot split a table CELL across pages,
     * so a body wrapped in a single `<tr><td>` moved to the next page
     * entire and left the header alone on a blank sheet.
     */
    public function testEveryPrintedLineIsARowOfTheSheetsOwnTable(): void
    {
        $html = $this->build([$this->section(
            'Louveteaux 1',
            '#198754',
            [$this->member('Grandjean', 'Antonin')],
            [$this->member('Ayoute', 'Dounia'), $this->member('Hargot', 'Basile')]
        )]);

        $body = substr(
            $html,
            (int) strpos($html, '<tbody>'),
            (int) strpos($html, '</tbody>') - (int) strpos($html, '<tbody>')
        );

        // Two banners, one spacer, three members — and no table of its
        // own inside, which is what nesting them in a cell would need.
        $this->assertSame(6, substr_count($body, '<tr>'));
        $this->assertStringNotContainsString('<table', $body);
    }

    public function testAnEmptyGroupSaysSoRatherThanDrawingNothing(): void
    {
        $html = $this->build([$this->section('Louveteaux 2')]);

        $this->assertSame(2, substr_count($html, 'Personne dans ce groupe cette année.'));
    }

    public function testTheRunningFooterCarriesTheSiteAndTheDate(): void
    {
        $html = $this->build([$this->section('Louveteaux 1')]);

        $this->assertStringContainsString('www.sv025.be', $html);
        $this->assertStringContainsString('document généré le', $html);
        $this->assertStringContainsString('counter(page)', $html);
    }

    /**
     * dompdf renders `counter(pages)` as 0 — it does not know the total
     * while it lays out — so the footer numbers pages without claiming
     * one. This is the single place the rendering cannot follow the
     * mockup's "page 1 / 2".
     */
    public function testTheFooterNeverClaimsAPageTotal(): void
    {
        $this->assertStringNotContainsString('counter(pages)', $this->build([$this->section('Louveteaux 1')]));
    }

    public function testNamesAreEscaped(): void
    {
        $html = $this->build([$this->section(
            '<script>x</script>',
            '#198754',
            [$this->member('O\'<b>Neil</b>', 'Ana')]
        )]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Neil</b>', $html);
    }

    public function testAnEmptySelectionStillProducesADocument(): void
    {
        $html = $this->build([]);

        $this->assertStringContainsString('Aucune section à imprimer.', $html);
    }
}
