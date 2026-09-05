<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberRosterRow;
use Core\Member\Movement\MemberMovementResult;
use Core\Member\Movement\MemberMovementStatus;
use Core\Member\Pdf\SectionRosterHtmlBuilder;
use Core\Member\SectionRosterPdfService;
use Core\Service\TextNormalizerService;
use PHPUnit\Framework\TestCase;

/**
 * The service that drives dompdf and owns the disk cache. The markup
 * itself is asserted a layer down, in Pdf\SectionRosterHtmlBuilderTest —
 * what is checked here is what only this class decides: which rows reach
 * the document, what the cache is keyed on, and what the file is called.
 */
class SectionRosterPdfServiceTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/roster-pdf-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory . '/section-roster/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDirectory . '/section-roster');
        @rmdir($this->cacheDirectory);
    }

    private function service(?string $cacheDirectory = null): SectionRosterPdfService
    {
        return new SectionRosterPdfService(new SectionRosterHtmlBuilder(), $cacheDirectory);
    }

    /** @return array<string, mixed> */
    private function section(int $id, string $name, string $color = '#198754'): array
    {
        return ['id' => $id, 'name' => $name, 'desk_code' => 'LOU0' . $id,
                'branch_name' => 'Louveteaux', 'color' => $color];
    }

    private function row(
        string $last,
        string $first,
        string $bucket = 'animes',
        ?string $totem = null,
        MemberMovementStatus $status = MemberMovementStatus::CONTINUING
    ): MemberRosterRow {
        return new MemberRosterRow(
            memberYearId: 1,
            memberId: 1,
            firstName: $first,
            lastName: $last,
            totem: $totem,
            functionLabel: 'Animé',
            bucket: $bucket,
            emails: ['parent@example.test'],
            phones: [['label' => 'GSM', 'value' => '0496 88 41 20']],
            movement: new MemberMovementResult($status)
        );
    }

    /**
     * @param MemberRosterRow[] $animateurs
     * @param MemberRosterRow[] $intendants
     * @param MemberRosterRow[] $animes
     * @return array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[], animes: MemberRosterRow[]}>
     */
    private function roster(int $sectionId, array $animateurs = [], array $intendants = [], array $animes = []): array
    {
        return [$sectionId => ['animateurs' => $animateurs, 'intendants' => $intendants, 'animes' => $animes]];
    }

    public function testItProducesARealPdfDocument(): void
    {
        $pdf = $this->service()->generate(
            1,
            '2026-2027',
            'Unité SV025',
            'www.sv025.be',
            [$this->section(7, 'Louveteaux 1')],
            $this->roster(7, [$this->row('Grandjean', 'Antonin', 'animateurs')], [], [$this->row('Ayoute', 'Dounia')])
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    /**
     * A section too large for one sheet continues on a second — and never
     * shares a page with another section.
     *
     * Rendered rather than asserted on the markup, because the failure
     * this pins is dompdf's own: a body wrapped in a single table cell
     * cannot be split, so it moved to the next page entire and left the
     * header alone on a blank sheet. Only the engine shows that.
     */
    public function testALargeSectionContinuesOnASecondSheetOfItsOwn(): void
    {
        $many = [];
        for ($i = 1; $i <= 40; $i++) {
            $many[] = $this->row('Nom' . $i, 'Prenom' . $i);
        }

        $pages = $this->pageCountFor(
            [$this->section(7, 'Pionniers 1')],
            $this->roster(7, [$this->row('Dupuis', 'Marie', 'animateurs')], [], $many)
        );
        $this->assertSame(2, $pages);

        // And a small one still fits on exactly one.
        $this->assertSame(
            1,
            $this->pageCountFor([$this->section(7, 'Louveteaux 1')], $this->roster(7, [], [], [$this->row('A', 'B')]))
        );
    }

    /**
     * The one exclusion this document makes, and it belongs to the
     * document alone: intendants do not take part in a passage, and
     * including them would lengthen a sheet somebody holds in one hand.
     * They stay on the screen and in the spreadsheet export.
     */
    public function testIntendantsNeverReachTheDocument(): void
    {
        $html = $this->htmlFor(
            [$this->section(7, 'Louveteaux 1')],
            $this->roster(
                7,
                [$this->row('Grandjean', 'Antonin', 'animateurs')],
                [$this->row('Mommens', 'Pascale', 'intendants')],
                [$this->row('Ayoute', 'Dounia')]
            )
        );

        $this->assertStringContainsString('Grandjean', $html);
        $this->assertStringContainsString('Ayoute', $html);
        $this->assertStringNotContainsString('Mommens', $html);
    }

    /**
     * MemberRosterRow carries every email and phone the screen shows.
     * None of it may cross into a view: this document circulates in a
     * local, in hands that are not all the staff's.
     */
    public function testNoContactCrossesIntoTheDocument(): void
    {
        $html = $this->htmlFor(
            [$this->section(7, 'Louveteaux 1')],
            $this->roster(7, [], [], [$this->row('Ayoute', 'Dounia')])
        );

        $this->assertStringNotContainsString('parent@example.test', $html);
        $this->assertStringNotContainsString('0496', $html);
    }

    /**
     * The same normalisation the screen applies through its
     * `normalize_name`/`normalize_totem` filters, so a name is spelled
     * identically on the sheet and on the page it was printed from.
     */
    public function testNamesAreNormalisedExactlyAsTheScreenDoes(): void
    {
        $html = $this->htmlFor(
            [$this->section(7, 'Louveteaux 1')],
            $this->roster(7, [], [], [$this->row('  van   DEN  BERGHE ', 'isao', 'animes', 'sittelle')])
        );

        // Whatever the shared normaliser makes of it, the sheet and the
        // page make the same thing of it — that is the property under
        // test, so the expectation is read from the normaliser rather
        // than restated here and left to drift from it.
        $this->assertStringContainsString(
            TextNormalizerService::normalizeName('  van   DEN  BERGHE '),
            $html
        );
        $this->assertStringContainsString('Isao', $html);
        $this->assertStringContainsString('Sittelle', $html);
        // And it is genuinely normalised, not passed through.
        $this->assertStringNotContainsString('DEN  BERGHE', $html);
    }

    public function testASectionWithNoNameFallsBackToItsDeskCode(): void
    {
        $section = $this->section(7, 'Louveteaux 1');
        $section['name'] = '';

        $html = $this->htmlFor([$section], $this->roster(7));

        $this->assertStringContainsString('LOU07', $html);
    }

    /**
     * The colour is taken from the page's data and never recomputed: a
     * colour set by hand in Configuration > Config Desk wins over the
     * branch's, so re-deriving it would print something different from
     * the screen for any unit that has customised a section.
     */
    public function testTheSectionColourIsTakenFromTheDataAsGiven(): void
    {
        $html = $this->htmlFor([$this->section(7, 'Staff d\'Unité', '#6f42c1')], $this->roster(7));

        $this->assertStringContainsString('#6f42c1', $html);
    }

    // --- the disk cache ------------------------------------------------

    public function testNoCacheDirectoryMeansEveryCallRenders(): void
    {
        $this->service()->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertSame([], glob($this->cacheDirectory . '/section-roster/*.pdf') ?: []);
    }

    public function testARenderedDocumentIsKeptOnDiskAndServedBack(): void
    {
        $service = $this->service($this->cacheDirectory);
        $args = [1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7)];

        $first = $service->generate(...$args);
        $files = glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [];
        $this->assertCount(1, $files);

        // Proven to come from disk rather than re-rendered: the file is
        // replaced with a marker, and the marker is what comes back.
        file_put_contents($files[0], '%PDF-cached');
        $this->assertSame('%PDF-cached', $service->generate(...$args));
        $this->assertNotSame('%PDF-cached', $first);
    }

    /**
     * The key covers the whole composition, so a member arriving, leaving
     * or changing situation produces a different document by construction
     * — a stale copy is impossible rather than merely unlikely.
     */
    public function testAChangeInTheRosterIsADifferentDocument(): void
    {
        $service = $this->service($this->cacheDirectory);
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')],
            $this->roster(7, [], [], [$this->row('Ayoute', 'Dounia')]));
        $first = glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [];

        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')],
            $this->roster(7, [], [], [$this->row('Ayoute', 'Dounia', 'animes', null, MemberMovementStatus::NEW)]));
        $second = glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [];

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0], $second[0], 'the movement is part of the signature');
    }

    /**
     * Two filters are two different documents, and confusing them would
     * serve the wrong list — the one failure this cache must not have.
     */
    public function testTheSectionFilterIsPartOfTheCacheKey(): void
    {
        $service = $this->service($this->cacheDirectory);
        $sections = [$this->section(7, 'A')];

        $service->generate(1, '2026-2027', 'U', '', $sections, $this->roster(7), null);
        $all = glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [];

        $service->generate(1, '2026-2027', 'U', '', $sections, $this->roster(7), 7);
        $one = glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [];

        $this->assertNotSame($all[0], $one[0]);
    }

    /**
     * Stale copies for the same year go when a new one is written, so the
     * directory does not grow a file per import.
     */
    public function testWritingANewDocumentPurgesTheYearsStaleCopies(): void
    {
        $service = $this->service($this->cacheDirectory);
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'B')], $this->roster(7));

        $this->assertCount(1, glob($this->cacheDirectory . '/section-roster/*.pdf') ?: []);
    }

    public function testAnotherYearsDocumentIsNotPurged(): void
    {
        $service = $this->service($this->cacheDirectory);
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));
        $service->generate(2, '2027-2028', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertCount(2, glob($this->cacheDirectory . '/section-roster/*.pdf') ?: []);
    }

    /**
     * The cache holds member names in the clear, so its retention is a
     * stated number rather than "until something replaces it". Purging
     * only the same year's superseded copies would leave last season's
     * sheet on disk for ever the day nobody prints that year again.
     */
    public function testASheetOlderThanTheRetentionIsPurgedWhateverYearItBelongsTo(): void
    {
        $service = $this->service($this->cacheDirectory);
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $old = glob($this->cacheDirectory . '/section-roster/*.pdf')[0];
        touch($old, time() - (8 * 24 * 60 * 60));

        // Another year entirely, so the same-year purge cannot be what
        // removes it.
        $service->generate(2, '2027-2028', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertFileDoesNotExist($old);
        $this->assertCount(1, glob($this->cacheDirectory . '/section-roster/*.pdf') ?: []);
    }

    /**
     * The sweep on write cannot be the only deadline. A sheet whose inputs
     * never change is never rewritten, so nothing would ever pass over it
     * and it would be served back for ever — a retention that only holds
     * when somebody happens to print something else is not a retention.
     */
    public function testAnExpiredSheetIsNeitherServedNorKept(): void
    {
        $service = $this->service($this->cacheDirectory);
        $args = [1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7)];
        $service->generate(...$args);

        $cached = glob($this->cacheDirectory . '/section-roster/*.pdf')[0];
        // A marker, so a re-render is distinguishable from a cache read.
        file_put_contents($cached, '%PDF-cached');
        touch($cached, time() - (8 * 24 * 60 * 60));

        $again = $service->generate(...$args);

        $this->assertNotSame('%PDF-cached', $again);
        $this->assertStringStartsWith('%PDF', $again);
    }

    public function testASheetWithinTheRetentionIsStillServedFromDisk(): void
    {
        $service = $this->service($this->cacheDirectory);
        $args = [1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7)];
        $service->generate(...$args);

        $cached = glob($this->cacheDirectory . '/section-roster/*.pdf')[0];
        file_put_contents($cached, '%PDF-cached');
        touch($cached, time() - (6 * 24 * 60 * 60));

        $this->assertSame('%PDF-cached', $service->generate(...$args));
    }

    /**
     * These files are member names in the clear. The reference deployment
     * is shared hosting, where a traversable parent means another local
     * account reads whatever the mode allows.
     */
    public function testTheCacheDirectoryIsReadableByItsOwnerAlone(): void
    {
        $this->service($this->cacheDirectory)
            ->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertSame(0700, fileperms($this->cacheDirectory . '/section-roster') & 0777);
    }

    public function testASheetWithinTheRetentionSurvivesAnotherYearsWrite(): void
    {
        $service = $this->service($this->cacheDirectory);
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $recent = glob($this->cacheDirectory . '/section-roster/*.pdf')[0];
        touch($recent, time() - (6 * 24 * 60 * 60));

        $service->generate(2, '2027-2028', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertFileExists($recent);
    }

    /**
     * Two filters are two documents, and neither supersedes the other.
     * Scoping the same-year purge to the year alone deleted "Toutes" the
     * moment a section sheet was written, so a chief alternating filters
     * paid a full render every time — exactly what this cache exists to
     * avoid.
     */
    public function testWritingOneFiltersSheetKeepsAnothersOnDisk(): void
    {
        $service = $this->service($this->cacheDirectory);
        $sections = [$this->section(7, 'A')];

        $service->generate(1, '2026-2027', 'U', '', $sections, $this->roster(7), null);
        $service->generate(1, '2026-2027', 'U', '', $sections, $this->roster(7), 7);

        $this->assertCount(
            2,
            glob($this->cacheDirectory . '/section-roster/*.pdf') ?: [],
            'the all-sections sheet and the section sheet coexist'
        );

        // And the same filter written again still supersedes itself.
        $service->generate(1, '2026-2027', 'U', '', [$this->section(7, 'B')], $this->roster(7), 7);
        $this->assertCount(2, glob($this->cacheDirectory . '/section-roster/*.pdf') ?: []);
    }

    public function testNoTemporaryFileSurvivesAWrite(): void
    {
        $this->service($this->cacheDirectory)
            ->generate(1, '2026-2027', 'U', '', [$this->section(7, 'A')], $this->roster(7));

        $this->assertSame([], glob($this->cacheDirectory . '/section-roster/*.tmp') ?: []);
    }

    // --- the file name -------------------------------------------------

    /**
     * Built from the scout year and, when the picker names one, the
     * section — organizational both. No unit name and no member name:
     * nothing a mail client would show before the file is opened.
     */
    public function testTheFileNameCarriesOnlyTheYearWhenEverySectionIsPrinted(): void
    {
        $this->assertSame('appel-2026-2027.pdf', $this->service()->fileName('2026-2027'));
    }

    public function testTheFileNameNamesTheSectionWhenTheFilterDoes(): void
    {
        $this->assertSame(
            'appel-2026-2027-louveteaux-1.pdf',
            $this->service()->fileName('2026-2027', 'Louveteaux 1')
        );
    }

    public function testTheFileNameSurvivesAccentsAndPunctuation(): void
    {
        $this->assertSame(
            'appel-2026-2027-staff-d-unit.pdf',
            $this->service()->fileName('2026-2027', "Staff d'Unité")
        );
    }

    /**
     * How many pages the real engine lays the document out on.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[],
     *         animes: MemberRosterRow[]}> $roster
     */
    private function pageCountFor(array $sections, array $roster): int
    {
        $pdf = $this->service()->generate(1, '2026-2027', 'U', '', $sections, $roster);

        // dompdf writes one `/Type /Page` object per page.
        return preg_match_all('~/Type\s*/Page[^s]~', $pdf);
    }

    /**
     * The rendered document, back as HTML. dompdf keeps no readable text
     * layer to assert on, so what reaches the builder is captured through
     * a builder subclass rather than read out of the PDF.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[],
     *         animes: MemberRosterRow[]}> $roster
     */
    private function htmlFor(array $sections, array $roster): string
    {
        $builder = new class extends SectionRosterHtmlBuilder {
            public string $captured = '';

            public function build(array $sections, string $unitName, string $yearLabel, string $siteUrl): string
            {
                return $this->captured = parent::build($sections, $unitName, $yearLabel, $siteUrl);
            }
        };

        (new SectionRosterPdfService($builder))
            ->generate(1, '2026-2027', 'Unité SV025', 'www.sv025.be', $sections, $roster);

        return $builder->captured;
    }
}
