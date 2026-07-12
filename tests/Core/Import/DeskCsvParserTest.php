<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskCsvParser;
use Core\Import\ImportException;
use PHPUnit\Framework\TestCase;

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
        $this->assertSame('Tarif normal', $jean->feeCode);
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
        $this->assertSame('SV025L1', $fn->sectionCode);
        $this->assertSame('Meute Akela', $fn->sectionName);
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
        $this->assertSame('SV025 BALADINS1', $selim->functions[0]->sectionCode);
        $this->assertSame('SV025B1', $selim->functions[0]->sectionName);
    }
}
