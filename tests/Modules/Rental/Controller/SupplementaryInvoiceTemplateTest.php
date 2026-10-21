<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Document\SupplementOffer;
use Modules\Rental\Stay\Settlement;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * A decision after validation and the supplementary invoice (#980,
 * chantier decision 2.8), as « Facture » renders them through the real Twig
 * environment: `_settlement_revision.html.twig` and
 * `_supplementary_invoices.html.twig`.
 */
final class SupplementaryInvoiceTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);

        return (string) preg_replace('/\s+/', ' ', $twig->render('@rental/management/' . $template, $context + [
            'asset' => ['id' => 3],
            'booking' => ['id' => 9, 'reference' => 'LOC-K7Q2M4', 'renterEmail' => 'jeanne@example.be'],
        ]));
    }

    private function settlement(int $id, int $version, int $keptCents): Settlement
    {
        return new Settlement(
            id: $id,
            bookingId: 9,
            version: $version,
            finalPersons: 20,
            lines: [],
            totalCents: $keptCents,
            alreadyPaidCents: 0,
            balanceCents: $keptCents,
            securityDepositWithheldCents: 0,
            securityDepositReturnCents: 0,
            isValidated: true,
            validatedAt: new \DateTimeImmutable('2027-07-20 10:00:00'),
            validatedByMemberId: 1,
            createdByMemberId: 1,
            createdAt: new \DateTimeImmutable('2027-07-20 09:00:00'),
            recordedKeptCents: $keptCents
        );
    }

    private function document(int $id, DocumentType $type, ?string $sentAt, ?int $settlementId = null): RentalDocument
    {
        return new RentalDocument(
            id: $id,
            bookingId: 9,
            fileId: 100 + $id,
            type: $type,
            version: 1,
            isForRenter: true,
            originalName: null,
            sizeBytes: null,
            sentAt: $sentAt !== null ? new \DateTimeImmutable($sentAt) : null,
            createdByMemberId: 1,
            createdAt: new \DateTimeImmutable('2027-07-10 09:00:00'),
            settlementId: $settlementId
        );
    }

    public function testNothingIsSaidWithoutADecisionAfterValidation(): void
    {
        $this->assertStringNotContainsString(
            'data-settlement-revision',
            $this->render('_settlement_revision.html.twig', ['settlement_revision' => null])
        );
    }

    public function testTheBannerNamesTheDecisionDateAndAsksBeforeRecording(): void
    {
        $html = $this->render('_settlement_revision.html.twig', [
            'settlement_revision' => [
                'settlement' => $this->settlement(11, 2, 39000),
                'decided_at' => new \DateTimeImmutable('2027-07-25 15:00:00'),
            ],
        ]);

        $this->assertStringContainsString(
            'Le décompte validé (v2) ne tient pas compte de la décision du 25/07/2027.',
            $html
        );
        $this->assertStringContainsString('action="/mes-locations/decompte-actualiser"', $html);
        $this->assertStringContainsString('Enregistrer un nouveau décompte', $html);
        $this->assertStringContainsString('La v2 reste validée telle quelle. Aucun e-mail n&#039;est envoyé.', $html);
    }

    public function testTheOfferSaysTheDifferenceAndThatNoEmailLeaves(): void
    {
        $html = $this->render('_supplementary_invoices.html.twig', [
            'supplement_offer' => new SupplementOffer(
                $this->document(5, DocumentType::INVOICE, '2027-07-12 10:00:00'),
                $this->settlement(12, 2, 39000),
                36000
            ),
        ]);

        $this->assertStringContainsString('data-supplement-offer', $html);
        $this->assertStringContainsString('30,00 € de plus', $html);
        $this->assertStringContainsString('la facture LOC-K7Q2M4-v1, envoyée le 12/07/2027', $html);
        $this->assertStringContainsString('action="/mes-locations/facture-complementaire"', $html);
        $this->assertStringContainsString('Aucun e-mail n&#039;est envoyé', $html);
    }

    public function testAnOfferInTheRentersFavourSaysSo(): void
    {
        $html = $this->render('_supplementary_invoices.html.twig', [
            'supplement_offer' => new SupplementOffer(
                $this->document(5, DocumentType::INVOICE, '2027-07-12 10:00:00'),
                $this->settlement(12, 2, 36000),
                39000
            ),
        ]);

        $this->assertStringContainsString('30,00 € en faveur du locataire', $html);
        $this->assertStringNotContainsString('de plus', $html);
    }

    /**
     * A supplement of the latest settlement is sent after a question
     * naming the address; one of an older settlement, never sent, says so.
     */
    public function testASupplementIsSentAfterAQuestionUnlessItIsOutdated(): void
    {
        $html = $this->render('_supplementary_invoices.html.twig', [
            'supplementary_invoices' => [
                $this->document(8, DocumentType::SUPPLEMENTARY_INVOICE, null, 13),
                $this->document(7, DocumentType::SUPPLEMENTARY_INVOICE, null, 12),
                $this->document(6, DocumentType::SUPPLEMENTARY_INVOICE, '2027-07-21 10:00:00', 11),
            ],
            'latest_validated_settlement_id' => 13,
        ]);

        $this->assertStringContainsString(
            'data-confirm="Envoyer la facture complémentaire à jeanne@example.be ?',
            $html
        );
        $this->assertStringContainsString('name="document_id" value="8"', $html);
        $this->assertStringNotContainsString('name="document_id" value="7"', $html);
        $this->assertSame(1, substr_count($html, 'data-supplement-outdated'));
        // Already sent: resent whatever came after.
        $this->assertStringContainsString('name="document_id" value="6"', $html);
        $this->assertStringContainsString('Renvoyer la facture complémentaire', $html);
        $this->assertStringContainsString('Envoyée le 21/07/2027', $html);
    }
}
