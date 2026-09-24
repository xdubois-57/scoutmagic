<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskCsvParser;
use Core\Import\ImportException;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class DeskCsvParserTest extends TestCase
{
    private DeskCsvParser $parser;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->parser = new DeskCsvParser();
        $this->fixturePath = dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv';
    }

    public function testParseValidCsvProducesCorrectParsedImport(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        $this->assertSame(5, $result->lineCount);
        $this->assertCount(3, $result->members);
    }

    public function testMembersAreGroupedByTiers(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        $deskIds = array_map(fn($m) => $m->deskId, $result->members);
        $this->assertContains('T001', $deskIds);
        $this->assertContains('T002', $deskIds);
        $this->assertContains('T003', $deskIds);
    }

    public function testMultipleFunctionsForOneMemberAreCaptured(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        // T002 (Sophie Martin) has Animateur + Intendant d'unité
        $sophie = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T002') {
                $sophie = $m;
                break;
            }
        }
        $this->assertNotNull($sophie);
        $this->assertCount(2, $sophie->functions);

        $fnCodes = array_map(fn($f) => $f->functionCode, $sophie->functions);
        $this->assertContains('Animateur', $fnCodes);
        $this->assertContains("Intendant d'unité", $fnCodes);
    }

    public function testMultipleAddressesDeduplicatedByType(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        // T001 (Jean Dupont) has Domicile + Adresse secondaire
        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $this->assertCount(2, $jean->addresses);

        $types = array_map(fn($a) => $a->type, $jean->addresses);
        $this->assertContains('Domicile', $types);
        $this->assertContains('Adresse secondaire', $types);
    }

    public function testBooleanFieldsParsedCorrectly(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        // T001 has federationMailConsent=true, unitMailConsent=true
        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $this->assertTrue($jean->federationMailConsent);
        $this->assertTrue($jean->unitMailConsent);

        // T002 has federationMailConsent=false
        $sophie = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T002') {
                $sophie = $m;
                break;
            }
        }
        $this->assertNotNull($sophie);
        $this->assertFalse($sophie->federationMailConsent);
        $this->assertTrue($sophie->unitMailConsent);
    }

    public function testFonctionPrincipaleParsedCorrectly(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        // T001 first function has Fonction principale = "true"
        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $this->assertTrue($jean->functions[0]->isMainFunction);
    }

    public function testInvalidHeadersThrowImportException(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmpFile, "Nom;Prenom;WrongHeader\nDupont;Jean;Test\n");

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('En-têtes CSV manquants');

        try {
            $this->parser->parse($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testEmptyFileThrowsImportException(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmpFile, '');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('vide');

        try {
            $this->parser->parse($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testUtf8BomHandling(): void
    {
        // Read fixture and prepend BOM
        $content = file_get_contents($this->fixturePath);
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmpFile, "\xEF\xBB\xBF" . $content);

        try {
            $result = $this->parser->parse($tmpFile);
            $this->assertCount(3, $result->members);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testMemberIdentityFieldsExtracted(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $this->assertSame('Dupont', $jean->lastName);
        $this->assertSame('Jean', $jean->firstName);
        $this->assertSame('M', $jean->gender);
        $this->assertSame('15/03/2012', $jean->birthDate);
        $this->assertSame('jean.dupont@example.com', $jean->email);
        $this->assertSame('Baloo', $jean->totem);
        $this->assertSame('Joyeux', $jean->quali);
        $this->assertSame('Les Tigres', $jean->patrol);
        $this->assertSame('N_COTISATION_NORMALE', $jean->feeCode);
        $this->assertSame('true', $jean->handicap);
        $this->assertSame('Assurance sport', $jean->supplementaryInsurance);
    }

    public function testFunctionSectionAndBranchExtracted(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $fn = $jean->functions[0];
        $this->assertSame('Animé', $fn->functionCode);
        $this->assertSame('Louveteaux', $fn->branchCode);
        // The section code always comes from the "Section" column, never
        // "SECTION" (all-caps), which can hold incorrect Desk export data.
        // In a real export "Section" holds the code (SV025L1) and "SECTION"
        // the display label ("Meute Akela") — desk_export_comma.csv shows
        // the same shape.
        $this->assertSame('SV025L1', $fn->sectionCode);
        $this->assertSame('SV025L1', $fn->sectionName);
    }

    public function testFunctionSectionCodeIgnoresUppercaseSectionColumn(): void
    {
        // T001's "SECTION" (all-caps) column holds "Meute Akela", a
        // different value from "Section" ("SV025L1") — the parser must
        // ignore it.
        $result = $this->parser->parse($this->fixturePath);

        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);
        $this->assertNotSame('Meute Akela', $jean->functions[0]->sectionCode);
    }

    public function testAddressFieldsExtracted(): void
    {
        $result = $this->parser->parse($this->fixturePath);

        $jean = null;
        foreach ($result->members as $m) {
            if ($m->deskId === 'T001') {
                $jean = $m;
                break;
            }
        }
        $this->assertNotNull($jean);

        $domicile = null;
        foreach ($jean->addresses as $a) {
            if ($a->type === 'Domicile') {
                $domicile = $a;
                break;
            }
        }
        $this->assertNotNull($domicile);
        $this->assertSame('Rue de la Liberté', $domicile->street);
        $this->assertSame('12', $domicile->number);
        $this->assertSame('Apt 3', $domicile->complement);
        $this->assertSame('1000', $domicile->postalCode);
        $this->assertSame('Bruxelles', $domicile->city);
        $this->assertSame('Belgique', $domicile->country);
    }

    public function testParseCommaDelimitedCsv(): void
    {
        $commaFixture = dirname(__DIR__, 2) . '/fixtures/desk_export_comma.csv';
        $result = $this->parser->parse($commaFixture);

        $this->assertSame(2, $result->lineCount);
        $this->assertCount(2, $result->members);

        $selim = null;
        foreach ($result->members as $m) {
            if ($m->deskId === '2028823') {
                $selim = $m;
                break;
            }
        }
        $this->assertNotNull($selim);
        $this->assertSame('Agram', $selim->lastName);
        $this->assertSame('Sélim', $selim->firstName);
        $this->assertSame('selim@example.com', $selim->email);
        $this->assertCount(1, $selim->functions);
        $this->assertSame('Scout', $selim->functions[0]->functionCode);
        $this->assertSame('Baladins', $selim->functions[0]->branchCode);
        // Section identity comes from "Section", not "SECTION" (all-caps) —
        // this fixture deliberately has different values in each column to
        // prove the right one is used.
        $this->assertSame('SV025B1', $selim->functions[0]->sectionCode);
        $this->assertSame('SV025B1', $selim->functions[0]->sectionName);
    }

    /**
     * Issue #356. The exception can only name the columns that are ABSENT,
     * and a federation renaming « Email Tiers » to « Courriel » produces
     * one absent name and not one mention of the word that arrived
     * instead — which is the only thing a maintainer can act on.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testARenamedColumnIsJournalledUnderTheNameActuallySeen(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $parser = new DeskCsvParser(new JournalService(new JournalRepository($pdo)));

        $this->refuse($parser, self::headerLineWith('Email Tiers', 'Courriel'));

        $context = $this->journalledContext($pdo);
        $this->assertContains('Courriel', $context['unexpected']);
        $this->assertContains('Email Tiers', $context['missing']);
    }

    /**
     * And the reason that value may be written down at all: the line was
     * PROVEN to be the schema row. `parse()` takes line 0 as the header
     * line with nothing to go on, so a file whose header row was stripped
     * — or whose delimiter was misdetected — hands `validateHeaders()` a
     * MEMBER. Journalling those cells would put a surname, a birth date,
     * a phone number and an address into `event_log.context`, which
     * `EventJournalCollector` copies verbatim into a support package that
     * leaves the installation. SECURITY.md §13: « Journal stores only
     * metadata — never raw CSV content ».
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testAFileWithNoHeaderRowNeverPutsItsFirstMemberInTheJournal(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $parser = new DeskCsvParser(new JournalService(new JournalRepository($pdo)));

        // A real export's first data row, in the real column order.
        $this->refuse($parser, 'Dupont;Marie;F;12/03/2011;+3281234567;+32470123456;'
            . "marie.dupont@example.be;Rue du Scout;12;;;5000;Namur;Belgique\n");

        $context = $this->journalledContext($pdo);
        $this->assertArrayNotHasKey(
            'unexpected',
            $context,
            'not one cell of a line that is not a header line may be written down'
        );
        $this->assertSame(14, $context['unexpected_count'], 'the count still says what happened');

        $stmt = $pdo->query("SELECT description FROM event_log WHERE event_type = 'desk_csv_header_unexpected'");
        $description = $stmt === false ? '' : (string) $stmt->fetchColumn();
        foreach (['Dupont', 'Marie', 'marie.dupont@example.be', '+32470123456', 'Namur'] as $personal) {
            $this->assertStringNotContainsString($personal, $description);
        }
    }

    /**
     * @param string $line the header line to hand the parser
     */
    private function refuse(DeskCsvParser $parser, string $line): void
    {
        $path = tempnam(sys_get_temp_dir(), 'desk') . '.csv';
        file_put_contents($path, $line);

        try {
            $parser->parse($path);
            $this->fail('A header line missing an expected column must be refused.');
        } catch (ImportException) {
            // The refusal is the existing behaviour; what is new is the
            // journal entry the tests above read.
        } finally {
            unlink($path);
        }
    }

    /**
     * The real 35-column header line, with one name replaced — the case
     * this journal entry exists for.
     */
    private static function headerLineWith(string $replaced, string $replacement): string
    {
        $reflected = new \ReflectionClass(DeskCsvParser::class);
        /** @var string[] $headers */
        $headers = $reflected->getConstant('EXPECTED_HEADERS');

        return implode(';', array_map(
            static fn(string $header): string => $header === $replaced ? $replacement : $header,
            $headers
        )) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function journalledContext(\PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT context FROM event_log WHERE event_type = 'desk_csv_header_unexpected'");
        $context = $stmt === false ? null : $stmt->fetchColumn();
        $this->assertIsString($context, 'A refused header line must leave a journal entry.');

        $decoded = json_decode($context, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * And the case that is NOT a defect: Desk adds a column this parser
     * has no use for. Nothing is missing, the import runs, and the journal
     * stays quiet — the roadmap for #356 had this the other way round, and
     * the parser is what decides.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testAnExtraColumnAloneIsNeitherRefusedNorJournalled(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $parser = new DeskCsvParser(new JournalService(new JournalRepository($pdo)));

        $original = (string) file_get_contents($this->fixturePath);
        $lines = explode("\n", $original);
        $lines[0] .= ';Courriel';
        $path = tempnam(sys_get_temp_dir(), 'desk') . '.csv';
        file_put_contents($path, implode("\n", $lines));

        try {
            $result = $parser->parse($path);
            $this->assertCount(3, $result->members);
        } finally {
            unlink($path);
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'desk_csv_header_unexpected'");
        $this->assertSame(0, $stmt === false ? -1 : (int) $stmt->fetchColumn());
    }
}
