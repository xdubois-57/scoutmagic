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
        ?string $counterpartyName = null
    ): Transaction {
        return new Transaction(
            id: 1,
            accountId: 1,
            fiscalYearId: 1,
            bankReference: null,
            transactionDate: '2026-10-01',
            label: $label,
            amount: -20.0,
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

    public function testDescriptionTruncatesLabelAt30Characters(): void
    {
        $label = 'Achat de matériel de camping pour le camp été';
        $movement = $this->transaction($label);

        $this->assertSame(mb_substr($label, 0, 30), MovementPresenter::description($movement, null));
        $this->assertSame(30, mb_strlen(MovementPresenter::description($movement, null)));
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
