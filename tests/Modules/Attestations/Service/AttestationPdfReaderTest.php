<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Modules\Attestations\Service\AttestationPdfReader;
use Modules\Attestations\Service\AttestationsException;
use Modules\Attestations\Service\MemberNameDirectory;
use Modules\Attestations\Service\PageCountMismatchException;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * The golden test: the committed fixture
 * (`tests/fixtures/pdf/attestations_batch_sample.pdf`) read end to end,
 * replayed on every change to the reader.
 *
 * The fixture reproduces the SHAPE of a federation batch — a constant title
 * opening each first page, a different constant opening each second page,
 * the member's name in the identity block — with invented names. Its
 * generator's docblock says what it is and is not.
 */
class AttestationPdfReaderTest extends TestCase
{
    private AttestationPdfReader $reader;

    protected function setUp(): void
    {
        $this->reader = new AttestationPdfReader();
    }

    /**
     * The unit as the fixture assumes it: four of the five names are known,
     * one of them to two different people.
     */
    private function directory(): MemberNameDirectory
    {
        $directory = new MemberNameDirectory();
        $directory->add(1, 'Margaux', 'Vandenbrande');
        $directory->add(2, 'Sacha', 'Meunier');
        $directory->add(3, 'Timéo', 'Roskam');
        // Two members of one name. Ids deliberately not adjacent to the
        // others, so an assertion cannot pass by counting.
        $directory->add(11, 'Zoé', 'Herremans');
        $directory->add(12, 'Zoé', 'Herremans');
        // « Camille Delacroix » is deliberately absent.

        return $directory;
    }

    public function testTheCertificateSizeIsDetectedFromTheFileItself(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());

        $this->assertSame(10, $analysis->pageCount);
        // Nothing was configured: the reader met page 1's first text field
        // again on page 3, so a certificate is two pages.
        $this->assertSame(2, $analysis->pagesPerDocument);
        $this->assertCount(5, $analysis->attestations);
    }

    public function testEachCertificateCoversItsOwnPages(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());

        $ranges = array_map(
            static fn($a): string => $a->pageRangeLabel(),
            $analysis->attestations
        );

        $this->assertSame(['1–2', '3–4', '5–6', '7–8', '9–10'], $ranges);
    }

    public function testANamePrintedSurnameFirstIsMatched(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());
        $first = $analysis->attestations[0];

        $this->assertSame(MatchState::Matched, $first->state());
        $this->assertSame(1, $first->matchedMemberId());
        $this->assertSame('VANDENBRANDE Margaux', $first->readName);
    }

    /**
     * The same file prints the second person the other way round. A
     * certificate carries no clue which half is the surname, so the
     * directory indexes both orders and this has to match just as well.
     */
    public function testANamePrintedGivenNameFirstIsMatchedToo(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());
        $second = $analysis->attestations[1];

        $this->assertSame(MatchState::Matched, $second->state());
        $this->assertSame(2, $second->matchedMemberId());
    }

    /**
     * The fixture's PDF text layer is WinAnsi, so « Timéo » survives the
     * round trip; the directory folds the accent on both sides.
     */
    public function testAnAccentedNameIsMatched(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());
        $third = $analysis->attestations[2];

        $this->assertSame(MatchState::Matched, $third->state());
        $this->assertSame(3, $third->matchedMemberId());
    }

    /**
     * The behaviour this whole module is built around. Two members carry
     * the name on pages 7–8, so the line comes out ambiguous with BOTH
     * candidates — never the first one found, which is what the previous
     * site did and what sends a family the wrong document.
     */
    public function testAHomonymIsAmbiguousAndKeepsEveryCandidate(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());
        $fourth = $analysis->attestations[3];

        $this->assertSame(MatchState::Ambiguous, $fourth->state());
        $this->assertSame([11, 12], $fourth->memberIds);
        $this->assertNull($fourth->matchedMemberId());
    }

    public function testANameNobodyCarriesIsUnmatchedAndStillShowsWhatWasRead(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());
        $fifth = $analysis->attestations[4];

        $this->assertSame(MatchState::Unmatched, $fifth->state());
        $this->assertSame([], $fifth->memberIds);
        // The screen has to show WHAT was read, or the reader has an empty
        // cell and nothing to act on.
        $this->assertSame('Camille DELACROIX', $fifth->readName);
    }

    public function testTheAnalysisCountsWhatStillNeedsAHuman(): void
    {
        $analysis = $this->reader->analyze(AttestationsTestHelper::goldenFixturePath(), $this->directory());

        // The homonym and the unknown name.
        $this->assertSame(2, $analysis->pendingCount());
        $this->assertSame([1, 2, 3], $analysis->matchedMemberIds());
    }

    /**
     * The guard rail. 9 pages against a 2-page certificate is not a
     * rounding problem: a split one page out of step gives every family the
     * next family's certificate, so nothing at all is produced.
     */
    public function testAPageCountThatIsNotAMultipleRefusesEverything(): void
    {
        $pages = [];
        for ($i = 0; $i < 4; $i++) {
            $pages[] = ['ATTESTATION FISCALE', 'Personne ' . $i];
            $pages[] = ['ANNEXE', 'Personne ' . $i];
        }
        $pages[] = ['ATTESTATION FISCALE', 'Personne orpheline'];

        $path = AttestationsTestHelper::writeTemporaryPdf($pages);

        try {
            $this->reader->analyze($path, $this->directory());
            $this->fail('A page count that is not a multiple must be refused.');
        } catch (PageCountMismatchException $e) {
            $this->assertSame(9, $e->pageCount);
            $this->assertSame(2, $e->pagesPerDocument);
            $this->assertSame(1, $e->remainder());
            // The screen shows the subtraction; the sentence carries it.
            $this->assertStringContainsString('9 pages', $e->getMessage());
            $this->assertStringContainsString('Rien n\'a été produit', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /**
     * One certificate per page is the same mechanism, not a special case:
     * page 1's first field recurs on page 2, so the size is 1.
     */
    public function testAOnePageCertificateIsDetectedJustTheSame(): void
    {
        $path = AttestationsTestHelper::writeTemporaryPdf([
            ['ATTESTATION', 'VANDENBRANDE Margaux'],
            ['ATTESTATION', 'Sacha MEUNIER'],
            ['ATTESTATION', 'Timéo ROSKAM'],
        ]);

        try {
            $analysis = $this->reader->analyze($path, $this->directory());

            $this->assertSame(1, $analysis->pagesPerDocument);
            $this->assertCount(3, $analysis->attestations);
            $this->assertSame('1', $analysis->attestations[0]->pageRangeLabel());
            $this->assertSame(0, $analysis->pendingCount());
        } finally {
            @unlink($path);
        }
    }

    /**
     * A file whose first field never recurs is ONE certificate — right for
     * a single-member document, and the shape a human is meant to catch on
     * the verification screen when it is not.
     */
    public function testAFirstFieldThatNeverRecursMakesTheWholeFileOneCertificate(): void
    {
        $path = AttestationsTestHelper::writeTemporaryPdf([
            ['Page un', 'VANDENBRANDE Margaux'],
            ['Page deux', 'suite'],
            ['Page trois', 'fin'],
        ]);

        try {
            $analysis = $this->reader->analyze($path, $this->directory());

            $this->assertSame(3, $analysis->pagesPerDocument);
            $this->assertCount(1, $analysis->attestations);
            $this->assertSame('1–3', $analysis->attestations[0]->pageRangeLabel());
            $this->assertSame(1, $analysis->attestations[0]->matchedMemberId());
        } finally {
            @unlink($path);
        }
    }

    /**
     * The name is matched wherever the template put it, not only on the
     * first line — the identity block moves around between templates and
     * the name is all the document carries.
     */
    public function testTheNameIsFoundOnTheSecondPageWhenTheFirstDoesNotCarryIt(): void
    {
        $path = AttestationsTestHelper::writeTemporaryPdf([
            ['ATTESTATION FISCALE', 'Les Scouts ASBL'],
            ['ANNEXE', 'Beneficiaire : Sacha MEUNIER'],
            ['ATTESTATION FISCALE', 'Les Scouts ASBL'],
            ['ANNEXE', 'VANDENBRANDE Margaux'],
        ]);

        try {
            $analysis = $this->reader->analyze($path, $this->directory());

            $this->assertSame(2, $analysis->pagesPerDocument);
            $this->assertSame(2, $analysis->attestations[0]->matchedMemberId());
            $this->assertSame(1, $analysis->attestations[1]->matchedMemberId());
        } finally {
            @unlink($path);
        }
    }

    /**
     * A labelled field resolves on what follows the label, because that is
     * how templates print an identity block. The line the screen shows is
     * the name, not the label — the reader stored what it matched.
     */
    public function testALabelledFieldResolvesOnWhatFollowsTheLabel(): void
    {
        $path = AttestationsTestHelper::writeTemporaryPdf([
            ['ATTESTATION', 'Beneficiaire : VANDENBRANDE Margaux'],
            ['ATTESTATION', 'Beneficiaire : Sacha MEUNIER'],
        ]);

        try {
            $analysis = $this->reader->analyze($path, $this->directory());

            $this->assertSame(1, $analysis->attestations[0]->matchedMemberId());
            $this->assertSame('VANDENBRANDE Margaux', $analysis->attestations[0]->readName);
            $this->assertSame(2, $analysis->attestations[1]->matchedMemberId());
        } finally {
            @unlink($path);
        }
    }

    /**
     * The other half of the rule above, and the one that matters: reading
     * the tail of a labelled field is not substring matching. A street
     * carrying a member's name still resolves to nobody, because every
     * candidate is looked up WHOLE.
     */
    public function testALineMerelyContainingANameMatchesNobody(): void
    {
        $path = AttestationsTestHelper::writeTemporaryPdf([
            ['ATTESTATION', 'Rue Camille Delacroix 12', 'Adresse : Rue Margaux Vandenbrande 3'],
            ['ATTESTATION', 'Rue Camille Delacroix 12', 'Adresse : Rue Margaux Vandenbrande 3'],
        ]);

        try {
            $analysis = $this->reader->analyze($path, $this->directory());

            $this->assertSame(MatchState::Unmatched, $analysis->attestations[0]->state());
            $this->assertSame(MatchState::Unmatched, $analysis->attestations[1]->state());
        } finally {
            @unlink($path);
        }
    }

    /**
     * A refusal a chef d'unité can act on: French, a whole sentence, and
     * naming nothing internal (AGENTS.md § Exception messages that reach a
     * visitor). The library's own English message rides on $previous, for
     * the journal.
     */
    public function testAFileThatIsNotAPdfIsRefusedWithASentenceAChiefCanAct(): void
    {
        $path = sys_get_temp_dir() . '/attestations_not_a_pdf_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "Ceci n'est pas un PDF.");

        try {
            $this->reader->analyze($path, $this->directory());
            $this->fail('A file that is not a PDF must be refused.');
        } catch (AttestationsException $e) {
            $this->assertStringContainsString('PDF', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
            $this->assertStringNotContainsString('Smalot', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(AttestationsException::class);
        $this->reader->analyze(sys_get_temp_dir() . '/attestations_no_such_file.pdf', $this->directory());
    }
}
