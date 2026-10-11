<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Service\MovementPresenter;
use PHPUnit\Framework\TestCase;

class MovementPresenterTest extends TestCase
{
    private function transaction(
        string $label,
        ?string $comment = null,
        ?string $counterpartyName = null,
        float $amount = -20.0
    ): Transaction {
        return new Transaction(
            id: 1,
            accountId: 1,
            fiscalYearId: 1,
            bankReference: null,
            transactionDate: '2026-10-01',
            label: $label,
            amount: $amount,
            categoryId: null,
            comment: $comment,
            source: Transaction::SOURCE_MANUAL,
            importedAt: null,
            counterpartyName: $counterpartyName
        );
    }

    private function attachment(?string $suggestedLabel = null, ?string $suggestedDescription = null): Attachment
    {
        return new Attachment(
            id: 1,
            accountId: 1,
            fileId: 1,
            mimeType: 'application/pdf',
            originalFilename: 'facture.pdf',
            suggestedAmount: null,
            suggestedDate: null,
            suggestedLabel: $suggestedLabel,
            suggestedSource: null,
            status: Attachment::STATUS_ACTIVE,
            parentAttachmentId: null,
            uploadedBy: null,
            uploadedAt: '2026-10-01 00:00:00',
            suggestedDescription: $suggestedDescription
        );
    }

    // --- counterparty() ---

    public function testCounterpartyUsesCounterpartyNameWhenPresent(): void
    {
        $movement = $this->transaction('VIR', counterpartyName: 'Jean Dupont');

        $this->assertSame('Jean Dupont', MovementPresenter::counterparty($movement, null, 'Compte'));
    }

    public function testCounterpartyFallsBackToReceiptStoreName(): void
    {
        $movement = $this->transaction('VIR');
        $receipt = $this->attachment(suggestedLabel: 'Delhaize');

        $this->assertSame('Delhaize', MovementPresenter::counterparty($movement, $receipt, 'Compte'));
    }

    public function testCounterpartyFallsBackToAccountNameWhenNothingElseKnown(): void
    {
        $movement = $this->transaction('VIR');

        $this->assertSame('Compte', MovementPresenter::counterparty($movement, null, 'Compte'));
    }

    public function testCounterpartyIgnoresBlankCounterpartyName(): void
    {
        $movement = $this->transaction('VIR', counterpartyName: '   ');
        $receipt = $this->attachment(suggestedLabel: 'Delhaize');

        $this->assertSame('Delhaize', MovementPresenter::counterparty($movement, $receipt, 'Compte'));
    }

    public function testCounterpartyIgnoresReceiptWithNoStoreName(): void
    {
        $movement = $this->transaction('VIR');
        $receipt = $this->attachment(suggestedLabel: null);

        $this->assertSame('Compte', MovementPresenter::counterparty($movement, $receipt, 'Compte'));
    }

    // --- description() ---

    public function testDescriptionUsesLabelWhenItContainsText(): void
    {
        $movement = $this->transaction('Achat fournitures de bureau');

        $this->assertSame('Achat fournitures de bureau', MovementPresenter::description($movement, null));
    }

    /**
     * One bound for every description (issue #838), with an ellipsis —
     * not the old thirty characters, which cut « Location LOC-D4E5F6 » in
     * half.
     */
    public function testDescriptionIsBoundedWithAnEllipsis(): void
    {
        $movement = $this->transaction(str_repeat('Achat de matériel de camping ', 6));

        $description = MovementPresenter::description($movement, null);

        $this->assertSame(MovementPresenter::DESCRIPTION_MAX_LENGTH, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
    }

    // --- the human description (issue #838) ---

    public function testAMovementThatPaysAReceivableIsDescribedByIt(): void
    {
        $movement = $this->transaction('+++123/4567/89002+++', amount: 45.0, counterpartyName: 'J. DUPONT');

        $this->assertSame(
            'Location LOC-D4E5F6 — Chalet des Pins — J. DUPONT — Camps',
            MovementPresenter::description($movement, null, [
                'receivable_label' => 'Location LOC-D4E5F6 — Chalet des Pins',
                'category' => 'Camps',
            ])
        );
    }

    public function testSeveralReceivablesNameTheLargestAndCountTheOthers(): void
    {
        $movement = $this->transaction('Famille Dupont', amount: 120.0);

        $this->assertStringStartsWith(
            'Campagne Cotisations — Jean Dupont (+ 2 autres créances)',
            MovementPresenter::description($movement, null, [
                'receivable_label' => 'Campagne Cotisations — Jean Dupont',
                'other_receivables' => 2,
            ])
        );
        $this->assertStringStartsWith(
            'Campagne Cotisations (+ 1 autre créance)',
            MovementPresenter::description($movement, null, ['receivable_label' => 'Campagne Cotisations', 'other_receivables' => 1])
        );
    }

    /**
     * A structured communication is an identifier: never shown. A credit
     * carrying one that pays nothing is what « Paiements à traiter » calls
     * it.
     */
    public function testAStructuredCommunicationIsNeverTheDescription(): void
    {
        $credit = $this->transaction('+++123/4567/89002+++', amount: 45.0);
        $debit = $this->transaction('+++123/4567/89002+++', amount: -45.0);

        $this->assertSame('Paiement non imputé', MovementPresenter::description($credit, null));
        $this->assertSame('Paiement', MovementPresenter::description($debit, null));
        $this->assertSame('Fournitures', MovementPresenter::description($debit, null, ['category' => 'Fournitures']));
    }

    public function testACreditDeclaredToPayNothingIsNotCalledUnimputed(): void
    {
        $credit = new Transaction(
            id: 1, accountId: 1, fiscalYearId: 1, bankReference: null, transactionDate: '2026-10-01',
            label: '+++123/4567/89002+++', amount: 45.0, categoryId: null, comment: null,
            source: Transaction::SOURCE_IMPORT, importedAt: null, notAReceivableAt: '2026-10-02 10:00:00'
        );

        $this->assertSame('Virement reçu', MovementPresenter::description($credit, null));
    }

    /**
     * The communication is taken out of the bank text; the words around
     * it stay.
     */
    public function testTheBankTextKeepsItsWordsWithoutItsCommunication(): void
    {
        $movement = $this->transaction('Camp été +++123/4567/89002+++', amount: -45.0);

        $this->assertSame('Camp été', MovementPresenter::description($movement, null));

        // Glued to another digit, it still goes — and an ordinary long
        // number that holds none stays.
        $glued = $this->transaction('VIREMENT SEPA 1234567890025 DUPONT JEAN', amount: -45.0);
        $this->assertSame('VIREMENT SEPA DUPONT JEAN', MovementPresenter::description($glued, null));
        $plain = $this->transaction('FACTURE 1111111111111 DUPONT', amount: -45.0);
        $this->assertSame('FACTURE 1111111111111 DUPONT', MovementPresenter::description($plain, null));
    }

    /**
     * A fragment already said is not said twice.
     */
    public function testRepeatedFragmentsAreSaidOnce(): void
    {
        $this->assertSame(
            'Location LOC-1 — Jean Dupont — Camps',
            MovementPresenter::compose(['Location LOC-1 — Jean Dupont', 'jean dupont', '', null, 'Camps', 'Camps'])
        );
    }

    /**
     * The other way round: an earlier, shorter fragment inside a later,
     * longer one — a BNP label that spells the counterparty out — is not
     * said twice either.
     */
    public function testAnEarlierFragmentIsTakenOutOfALaterOneThatRepeatsIt(): void
    {
        $this->assertSame(
            'Jean Dupont — VIREMENT AU COMPTE BE00 REFERENCE BANQUE : 123',
            MovementPresenter::compose(['Jean Dupont', 'VIREMENT AU COMPTE BE00 JEAN DUPONT REFERENCE BANQUE : 123'])
        );
        // Whole words only: a short fragment never eats into a longer word.
        $this->assertSame('Camps — Campsite des Ardennes', MovementPresenter::compose(['Camps', 'Campsite des Ardennes']));
        $this->assertSame('ACHAT CAMPING CARREFOUR — Camp', MovementPresenter::compose(['ACHAT CAMPING CARREFOUR', 'Camp']));
        // Nothing left but the repeated name: the later fragment goes.
        $this->assertSame('Jean Dupont', MovementPresenter::compose(['Jean Dupont', 'JEAN DUPONT 12345']));
    }

    public function testAnInferredNameServesAsCounterpartyWhenTheBankGaveNone(): void
    {
        $movement = $this->transaction('PAYCONIQ');

        $this->assertSame('Boulangerie Martin', MovementPresenter::counterparty($movement, null, 'Compte', 'Boulangerie Martin'));
        $this->assertSame(
            'Jean Dupont',
            MovementPresenter::counterparty($this->transaction('X', counterpartyName: 'Jean Dupont'), null, 'Compte', 'Autre')
        );
    }

    public function testDescriptionFallsBackToCommentWhenLabelIsNumericOnly(): void
    {
        $movement = $this->transaction('0012345 / 987', comment: 'Remboursement matériel');

        $this->assertSame('Remboursement matériel', MovementPresenter::description($movement, null));
    }

    public function testDescriptionIgnoresShortComment(): void
    {
        $movement = $this->transaction('0012345', comment: 'Ok');
        $receipt = $this->attachment(suggestedDescription: 'Achat de fournitures de bureau');

        $this->assertSame('Achat de fournitures de bureau', MovementPresenter::description($movement, $receipt));
    }

    public function testDescriptionFallsBackToReceiptDescriptionWhenLabelAndCommentAreUnusable(): void
    {
        $movement = $this->transaction('0012345 / 987', comment: null);
        $receipt = $this->attachment(suggestedDescription: 'Achat de fournitures de bureau');

        $this->assertSame('Achat de fournitures de bureau', MovementPresenter::description($movement, $receipt));
    }

    public function testDescriptionFallsBackToRawLabelWhenNothingElseIsUsable(): void
    {
        $movement = $this->transaction('0012345 / 987', comment: null);

        $this->assertSame('0012345 / 987', MovementPresenter::description($movement, null));
    }

    public function testDescriptionLabelWithLetterAndDigitsStillCountsAsText(): void
    {
        $movement = $this->transaction('REF123ABC');

        $this->assertSame('REF123ABC', MovementPresenter::description($movement, null));
    }
}
