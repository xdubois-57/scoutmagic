<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Service\HealthSheetPdfService;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * The assembly: the shipped two-page template really is imported, the
 * values really do come back as bytes, and an answer that does not fit
 * really is reported.
 *
 * What the document SAYS is `HealthSheetFillingTest`'s business and where
 * it says it is `HealthSheetLayoutTest`'s; this is about the engine and the
 * three things only the engine can get wrong — the second page, the
 * overflow report, and the filesystem.
 */
final class HealthSheetPdfServiceTest extends TestCase
{
    private static function service(): HealthSheetPdfService
    {
        return new HealthSheetPdfService(TemplateLibrary::shipped());
    }

    /**
     * A sheet with something in every kind of field.
     */
    private static function filled(): HealthSheet
    {
        return HealthSheet::fromArray([
            'contact1_name' => 'Marie Dubois',
            'contact1_relationship' => 'Mère',
            'contact1_phone' => '0470 12 34 56',
            'doctor_last_name' => 'Dupont',
            'doctor_first_name' => 'Jean',
            'height' => '148 cm',
            'weight' => '39 kg',
            'participation' => 'yes',
            'participation_details' => 'Pas de course prolongée par forte chaleur.',
            'swimming_level' => 'fair',
            'conditions' => ['asthma' => true, 'headaches' => true],
            'conditions_details' => 'Asthme léger, deux crises par an.',
            'illnesses_and_operations' => 'Appendicite en 2022.',
            'useful_information' => 'Porte des lunettes.',
            'tetanus_vaccinated' => 'yes',
            'tetanus_last_booster' => '12/09/2023',
            'allergies' => 'Arachides.',
            'allergy_consequences' => 'Œdème : EpiPen dans son sac.',
            'diet' => 'Sans porc.',
            'treatment' => 'Ventoline 100 µg au besoin.',
            'treatment_autonomy' => 'yes',
        ]);
    }

    public function testItProducesAPdfFromTheShippedTemplate(): void
    {
        $result = self::service()->render(HealthSheetFillingTest::member(), self::filled());

        $this->assertStringStartsWith('%PDF-', $result['pdf']);
        // The federation's own two pages are in there, so the output is
        // far larger than the few hundred bytes an empty document would be
        // — a template that failed to import would still produce a valid,
        // and entirely blank, PDF.
        $this->assertGreaterThan(100_000, strlen($result['pdf']));
        $this->assertSame([], $result['overflowing']);
    }

    /**
     * **Both pages.** The chantier names the trap by hand — « ne suppose pas
     * qu'un document est monopage » — and a service that imported page 1
     * and stopped would pass every other test in this file.
     */
    public function testTheDocumentCarriesBothPagesOfTheForm(): void
    {
        $result = self::service()->render(HealthSheetFillingTest::member(), self::filled());

        $document = (new Parser())->parseContent($result['pdf']);

        $this->assertCount(2, $document->getPages());
    }

    /**
     * And what belongs on page 2 is ON page 2. Counting pages proves the
     * import; this proves the writing loop did not put every value on the
     * page that happened to be open.
     */
    public function testWhatBelongsOnTheSecondPageIsWrittenThere(): void
    {
        $sheet = HealthSheet::fromArray([
            'tetanus_last_booster' => '12/09/2023',
            'diet' => 'Aucun produit laitier.',
        ]);

        $pages = (new Parser())
            ->parseContent(self::service()->render(HealthSheetFillingTest::member(), $sheet)['pdf'])
            ->getPages();

        $second = $pages[1]->getText();
        $this->assertStringContainsString('12/09/2023', $second);
        $this->assertStringContainsString('Aucun produit laitier', $second);
    }

    /**
     * The identity the site knows reaches the paper — the one thing a
     * family cannot type on this screen and the reason the document is
     * worth pre-filling at all.
     */
    public function testTheMembersOwnIdentityIsWrittenOnTheFirstPage(): void
    {
        $pages = (new Parser())
            ->parseContent(self::service()->render(HealthSheetFillingTest::member(), HealthSheet::empty())['pdf'])
            ->getPages();

        $first = $pages[0]->getText();
        $this->assertStringContainsString('Dubois', $first);
        $this->assertStringContainsString('17/04/2013', $first);
    }

    /**
     * The one reason this module uses tFPDF rather than FPDF: a name or an
     * allergy outside cp1252 must not come out mangled — or take the
     * generation down — on a document a family hands to a first-aider.
     */
    public function testTextOutsideCp1252IsWrittenRatherThanMangled(): void
    {
        $result = self::service()->render(
            HealthSheetFillingTest::member('Bartosz', 'Wiśniewski'),
            HealthSheet::fromArray([
                'contact1_name' => 'Ayşe Gül Çetin',
                'allergies' => 'Œufs, arachides — et l\'iode',
            ])
        );

        $this->assertStringStartsWith('%PDF-', $result['pdf']);
        $this->assertSame([], $result['overflowing']);
    }

    // ---------------------------------------------------------------
    // Overflow
    // ---------------------------------------------------------------

    /**
     * An answer longer than the printed lines is named — under the name the
     * SCREEN gives it, not the name of the continuation line it ran out of.
     * A parent told to shorten « allergies_2 » has been told nothing.
     */
    public function testAnAnswerThatDoesNotFitIsReportedUnderItsOwnName(): void
    {
        $result = self::service()->render(
            HealthSheetFillingTest::member(),
            HealthSheet::fromArray([
                'allergies' => str_repeat('Arachides, fruits à coque, pollen de graminées, acariens, ', 10),
            ])
        );

        $this->assertSame(['allergies'], $result['overflowing']);
        // And the document is still produced: what fits is written, so a
        // family whose list runs long still gets their form.
        $this->assertStringStartsWith('%PDF-', $result['pdf']);
    }

    /**
     * A « Remarque » longer than its printed line is an ANSWER that
     * overflowed, not a stray field name — because it goes through the
     * same wrapping path as every other free-text answer.
     *
     * It used to be written as a single-line value, which on this
     * two-column printed table meant drawing through the frame and into
     * the other contact's cell.
     */
    public function testALongEmergencyContactNoteIsReportedAsThatAnswer(): void
    {
        $result = self::service()->render(
            HealthSheetFillingTest::member(),
            HealthSheet::fromArray([
                'contact1_note' => 'Joignable uniquement en journée, de préférence après quatorze heures, '
                    . 'sinon appeler le grand-père qui habite à côté de chez nous',
            ])
        );

        $this->assertSame(['contact1_note'], $result['overflowing']);
    }

    /**
     * And what the second contact wrote is still theirs: both notes are
     * fitted to their own column rather than one running over the other.
     */
    public function testTheTwoContactNotesStayInTheirOwnColumns(): void
    {
        $result = self::service()->render(
            HealthSheetFillingTest::member(),
            HealthSheet::fromArray([
                'contact1_note' => 'Joignable uniquement en journée, de préférence après quatorze heures, '
                    . 'sinon appeler les voisins du dessous',
                'contact2_note' => 'Après dix-sept heures',
            ])
        );

        $page = (new Parser())->parseContent($result['pdf'])->getPages()[0]->getText();

        $this->assertStringContainsString('Joignable', $page);
        $this->assertStringContainsString('Après dix-sept heures', $page);
        $this->assertStringNotContainsString('voisins du dessous', $page);
        $this->assertSame(['contact1_note'], $result['overflowing']);
    }

    /**
     * Each answer is named once, however many of its lines were involved.
     */
    public function testAnOverflowingAnswerIsNamedOnlyOnce(): void
    {
        $result = self::service()->render(
            HealthSheetFillingTest::member(),
            HealthSheet::fromArray([
                'treatment' => str_repeat('Ventoline 100 µg, deux bouffées, quatre fois par jour. ', 15),
                'diet' => str_repeat('Sans lactose, sans gluten, sans fruits à coque. ', 15),
            ])
        );

        $this->assertSame(['treatment', 'diet'], array_values(array_intersect(
            ['treatment', 'diet'],
            $result['overflowing']
        )));
        $this->assertSame($result['overflowing'], array_unique($result['overflowing']));
    }

    /**
     * A value the SITE supplies can overflow too — a very long street on a
     * 92 mm line — and it is reported under its own name so the screen can
     * tell it apart from the family's own answers and stay quiet about it.
     *
     * Without this, the « only report what the parent can fix » rule on the
     * screen would be untested in the one direction that matters: it would
     * pass just as well if nothing site-supplied ever overflowed.
     */
    public function testAValueTheSiteSuppliesOverflowsUnderItsOwnName(): void
    {
        $member = HealthSheetFillingTest::member(addresses: [
            new \Core\Member\MemberAddress(
                'home',
                str_repeat('Avenue des Anciens Combattants de la Grande Guerre ', 6),
                '148',
                null,
                null,
                '4000',
                'Liège',
                'Belgique'
            ),
        ]);

        $result = self::service()->render($member, HealthSheet::empty());

        $this->assertContains('member_street', $result['overflowing']);
    }

    /**
     * An empty sheet is a valid sheet: it produces the federation's blank
     * form with the member's identity on it, and nothing is reported.
     */
    public function testAnEmptySheetProducesTheBlankFormWithoutComplaint(): void
    {
        $result = self::service()->render(HealthSheetFillingTest::member(), HealthSheet::empty());

        $this->assertStringStartsWith('%PDF-', $result['pdf']);
        $this->assertSame([], $result['overflowing']);
    }

    // ---------------------------------------------------------------
    // The filesystem, and the missing template
    // ---------------------------------------------------------------

    /**
     * Nothing is written to disk — the chantier's rule, and the one that
     * matters on shared hosting where a temporary file is a file somebody
     * else's process can read.
     */
    public function testNothingIsWrittenToDisk(): void
    {
        $directory = sys_get_temp_dir();
        $before = scandir($directory);

        self::service()->render(HealthSheetFillingTest::member(), self::filled());

        $this->assertSame($before, scandir($directory));
    }

    /**
     * A missing template is a French sentence, never a stack trace from
     * FPDI about a file it could not open — and never the library's own
     * message, which names a path on the server.
     */
    public function testAMissingTemplateIsAFrenchSentenceAndNotALibraryMessage(): void
    {
        $service = new HealthSheetPdfService(new TemplateLibrary('/nowhere/at/all'));

        $this->expectException(OfficialDocumentsException::class);
        $this->expectExceptionMessage('Le formulaire officiel est introuvable sur ce site.');

        $service->render(HealthSheetFillingTest::member(), HealthSheet::empty());
    }

    /**
     * And a file that is there but is not a PDF this build of FPDI can
     * read — the case a template replaced without being converted produces.
     */
    public function testATemplateThatCannotBeParsedIsAFrenchSentenceToo(): void
    {
        $directory = sys_get_temp_dir() . '/official-documents-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/' . TemplateLibrary::HEALTH_SHEET, 'pas un PDF');

        try {
            $service = new HealthSheetPdfService(new TemplateLibrary($directory));

            $this->expectException(OfficialDocumentsException::class);
            $this->expectExceptionMessage('n\'a pas pu être ouvert');

            $service->render(HealthSheetFillingTest::member(), HealthSheet::empty());
        } finally {
            @unlink($directory . '/' . TemplateLibrary::HEALTH_SHEET);
            @rmdir($directory);
        }
    }
}
