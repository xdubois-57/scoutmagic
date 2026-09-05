<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Service;

use Core\File\PdfTextExtractor;
use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Modules\Trombinoscope\Pdf\StaffPhotoEmbedder;
use Modules\Trombinoscope\Pdf\TrombinoscopeHtmlBuilder;
use Modules\Trombinoscope\Service\TrombinoscopePdfService;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use PHPUnit\Framework\TestCase;

/**
 * The printable trombinoscope end to end, dompdf included — the one test
 * that proves the layout actually renders rather than merely being valid
 * markup, and that the directory really does hold on a single sheet.
 */
class TrombinoscopePdfServiceTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sections = [];

    /** @var array<int, array{lead: ?MemberProfile, staff: MemberProfile[]}> */
    private array $staffBySection = [];

    private function profile(int $id, string $first, string $last, ?string $totem, ?string $mobile = '0496 88 41 20', ?string $email = 'antonin@sv025.be'): MemberProfile
    {
        return new MemberProfile(
            memberYearId: $id,
            memberId: $id,
            deskId: 'D' . $id,
            firstName: $first,
            lastName: $last,
            totem: $totem,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: $mobile,
            email: $email,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    private function addSection(int $id, string $name, string $deskCode, int $sortOrder, ?string $email, ?MemberProfile $lead, array $staff = []): void
    {
        $this->sections[] = [
            'id' => $id,
            'desk_code' => $deskCode,
            'name' => $name,
            'email' => $email,
            'age_branch_id' => 1,
            'branch_name' => 'Louveteaux',
            'branch_sort_order' => $sortOrder,
            'is_visible' => true,
            'is_active' => true,
            'color' => null,
        ];
        $this->staffBySection[$id] = ['lead' => $lead, 'staff' => $staff];
    }

    private function service(): TrombinoscopePdfService
    {
        $sections = $this->sections;
        $staff = $this->staffBySection;

        $sectionService = new class($sections) extends SectionService {
            public function __construct(private array $sections)
            {
            }

            public function getAllWithBranches(bool $includeHidden = false): array
            {
                return $this->sections;
            }
        };

        $trombinoscopeService = new class($staff) extends TrombinoscopeService {
            public function __construct(private array $staff)
            {
            }

            public function getSectionStaff(int $sectionId, int $scoutYearId): array
            {
                return $this->staff[$sectionId] ?? ['lead' => null, 'staff' => []];
            }

            public function getSectionStaffForSections(array $sectionIds, int $scoutYearId): array
            {
                $result = [];
                foreach ($sectionIds as $id) {
                    $result[$id] = $this->getSectionStaff($id, $scoutYearId);
                }

                return $result;
            }
        };

        $embedder = new class extends StaffPhotoEmbedder {
            public function __construct()
            {
            }

            public function prime(array $memberIds, int $scoutYearId): void
            {
            }


            public function fileIdFor(int $memberId, int $scoutYearId): ?int

            {

                return null;

            }

            public function dataUriFor(int $memberId, int $scoutYearId): ?string
            {
                return null;
            }
        };

        return new TrombinoscopePdfService($trombinoscopeService, $sectionService, $embedder, new TrombinoscopeHtmlBuilder());
    }

    private function generate(bool $showContacts = true): string
    {
        return $this->service()->generate(1, '2025-2026', 'Unité SV025', 'www.sv025.be', $showContacts);
    }

    /**
     * Everything the PDF's text layer holds, as one string — the site's
     * own extractor (Core\File\PdfTextExtractor, pure PHP), so this test
     * needs no external binary. What it reads is exactly what a reader can
     * select, copy or extract out of the delivered file, which is the
     * point when asserting that a hidden contact detail is really absent.
     */
    private function textOf(string $pdf): string
    {
        $text = (new PdfTextExtractor())->extractText($pdf);
        $this->assertNotNull($text, 'The generated PDF carries no readable text layer.');

        return $text;
    }

    private function pageCount(string $pdf): int
    {
        return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
    }

    protected function setUp(): void
    {
        $this->sections = [];
        $this->staffBySection = [];
    }

    public function testItProducesOnePageForTheDirectoryPlusOnePerSection(): void
    {
        $this->addSection(1, 'Louveteaux 1', 'LOU01', 20, 'louveteaux1@sv025.be', $this->profile(10, 'Antonin', 'Grandjean', 'Chacal'));
        $this->addSection(2, 'Baladins 1', 'BAL01', 10, 'baladins1@sv025.be', $this->profile(11, 'Lucie', 'Crijns', 'Alouette'));
        $this->addSection(3, "Staff d'U", 'STAFFDU', 90, 'unite@sv025.be', $this->profile(12, 'Xavier', 'Dubois', 'Bouquetin'));

        $pdf = $this->generate();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(4, $this->pageCount($pdf));
    }

    public function testTheDirectoryHoldsOnOneSheetWhateverTheSectionCount(): void
    {
        // Fourteen sections is well past any real unit, and the third
        // density step still fits it on the single sheet a parent puts on
        // their fridge — page two is already the first section's page.
        for ($i = 1; $i <= 14; $i++) {
            $this->addSection($i, 'Section ' . $i, 'S' . $i, $i, 'section' . $i . '@sv025.be', $this->profile(100 + $i, 'Prénom' . $i, 'Nom' . $i, 'Totem' . $i));
        }

        $this->assertSame(15, $this->pageCount($this->generate()));
    }

    public function testTheStaffDUniteIsASectionLikeAnyOther(): void
    {
        // It has its page and it sits on the directory, and with nobody
        // designated it carries the same mention as anybody else.
        $this->addSection(1, "Staff d'U", 'STAFFDU', 90, 'unite@sv025.be', null);

        $text = $this->textOf($this->generate());

        $this->assertStringContainsString("STAFF D'U", $text);
        $this->assertStringContainsString('Responsable non désigné', $text);
    }

    public function testContactsReachTheDocumentWhenTheSettingIsOn(): void
    {
        $this->addSection(1, 'Louveteaux 1', 'LOU01', 20, 'louveteaux1@sv025.be', $this->profile(10, 'Antonin', 'Grandjean', 'Chacal'));

        $text = $this->textOf($this->generate());

        $this->assertStringContainsString('0496 88 41 20', $text);
        $this->assertStringContainsString('antonin@sv025.be', $text);
        $this->assertStringContainsString('louveteaux1@sv025.be', $text);
    }

    public function testWithTheSettingOffNoPersonalContactIsInTheDocumentAtAll(): void
    {
        // Not hidden behind a layer: absent from the text a reader can
        // select, copy or extract out of the file.
        $this->addSection(1, 'Louveteaux 1', 'LOU01', 20, 'louveteaux1@sv025.be', $this->profile(10, 'Antonin', 'Grandjean', 'Chacal'));

        $text = $this->textOf($this->generate(false));

        $this->assertStringNotContainsString('0496 88 41 20', $text);
        $this->assertStringNotContainsString('antonin@sv025.be', $text);
        // The section's own address is organizational and survives.
        $this->assertStringContainsString('louveteaux1@sv025.be', $text);
    }

    public function testASectionWithNoNameFallsBackToItsDeskCode(): void
    {
        $this->addSection(1, '', 'LOU01', 20, null, null);
        $this->sections[0]['name'] = null;

        $this->assertStringContainsString('LOU01', $this->textOf($this->generate()));
    }

    public function testTheFileNameCarriesTheScoutYearAndNothingElse(): void
    {
        // Nothing a mail client would show before the file is opened: no
        // unit name, no member name.
        $this->assertSame('trombinoscope-2025-2026.pdf', $this->service()->fileName('2025-2026'));
        $this->assertSame('trombinoscope-annuaire.pdf', $this->service()->fileName('///'));
    }

    public function testAUnitWithNoSectionStillProducesAReadableDocument(): void
    {
        $pdf = $this->generate();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * With a cache directory, the document is rendered once and served
     * from disk while its inputs hold; any input change is a different
     * file name, so a stale copy cannot exist.
     */
    public function testTheDocumentIsRenderedOnceAndServedFromTheCacheUntilAnInputChanges(): void
    {
        $cacheDir = sys_get_temp_dir() . '/scoutmagic-trombi-pdf-' . bin2hex(random_bytes(4));
        $staff = ['1' => ['lead' => $this->profile(10, 'Akela', 'Akela', 'Akela'), 'staff' => []]];
        $sections = [['id' => 1, 'desk_code' => 'LOU', 'name' => 'Louveteaux', 'branch_name' => 'Louveteaux', 'color' => '#00aa00', 'email' => null, 'sort_order' => 20]];
        $sectionService = new class($sections) extends SectionService {
            public function __construct(private array $sections)
            {
            }

            public function getAllWithBranches(bool $includeHidden = false): array
            {
                return $this->sections;
            }
        };
        $trombinoscopeService = new class($staff) extends TrombinoscopeService {
            public function __construct(private array $staff)
            {
            }

            public function getSectionStaffForSections(array $sectionIds, int $scoutYearId): array
            {
                $result = [];
                foreach ($sectionIds as $id) {
                    $result[$id] = $this->staff[$id] ?? ['lead' => null, 'staff' => []];
                }

                return $result;
            }
        };
        $embedder = new class extends StaffPhotoEmbedder {
            public int $renders = 0;

            public function __construct()
            {
            }

            public function prime(array $memberIds, int $scoutYearId): void
            {
            }

            public function fileIdFor(int $memberId, int $scoutYearId): ?int
            {
                return 42;
            }

            public function dataUriFor(int $memberId, int $scoutYearId): ?string
            {
                $this->renders++;

                return null;
            }
        };
        $service = new TrombinoscopePdfService($trombinoscopeService, $sectionService, $embedder, new TrombinoscopeHtmlBuilder(), $cacheDir);

        try {
            $first = $service->generate(1, '2025-2026', 'Unité', 'www.example.invalid', true);
            $this->assertSame(1, $embedder->renders);
            $this->assertCount(1, glob($cacheDir . '/trombinoscope/1-*.pdf') ?: []);

            $second = $service->generate(1, '2025-2026', 'Unité', 'www.example.invalid', true);
            $this->assertSame($first, $second);
            $this->assertSame(1, $embedder->renders, 'served from disk, no portrait re-encoded');

            $service->generate(1, '2025-2026', 'Unité', 'www.example.invalid', false);
            $this->assertSame(2, $embedder->renders, 'a different setting is a different document');
            $this->assertCount(1, glob($cacheDir . '/trombinoscope/1-*.pdf') ?: [], 'the superseded copy is removed');
        } finally {
            foreach (glob($cacheDir . '/trombinoscope/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($cacheDir . '/trombinoscope');
            @rmdir($cacheDir);
        }
    }
}
