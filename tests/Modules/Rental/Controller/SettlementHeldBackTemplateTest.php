<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Stay\Settlement;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * A damage under assessment holds the settlement and the security deposit
 * (#980, chantier §2.4), as `_settlement.html.twig` (« Facture ») and
 * `_finance_summary.html.twig` (« Finances ») render it, through the real
 * Twig environment.
 */
final class SettlementHeldBackTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function renderSettlement(array $context): string
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);

        return $twig->render('@rental/management/_settlement.html.twig', $context + [
            'asset' => ['id' => 3],
            'booking' => ['id' => 9],
            'settlements' => [$this->settlement(11, 1), $this->settlement(12, 2)],
            'final_persons' => 20,
            'preview' => [
                'lines' => [],
                'total_cents' => 45000,
                'balance_cents' => 45000,
                'security_deposit_withheld_cents' => 0,
                'security_deposit_return_cents' => 0,
            ],
            'decided_incidents' => [],
            'inventory_url' => '/mes-locations/local/reservations/9/etat-des-lieux',
        ]);
    }

    private function settlement(int $id, int $version): Settlement
    {
        return new Settlement(
            id: $id,
            bookingId: 9,
            version: $version,
            finalPersons: 20,
            lines: [],
            totalCents: 45000,
            alreadyPaidCents: 0,
            balanceCents: 45000,
            securityDepositWithheldCents: null,
            securityDepositReturnCents: null,
            isValidated: false,
            validatedAt: null,
            validatedByMemberId: null,
            createdByMemberId: 1,
            createdAt: new \DateTimeImmutable('2027-07-21 10:00:00'),
            recordedKeptCents: 45000,
            incidentBasisHash: str_repeat('a', 64)
        );
    }

    public function testEachVersionValidatesWhenNothingHoldsItBack(): void
    {
        $html = $this->renderSettlement([]);

        $this->assertSame(2, substr_count($html, 'action="/mes-locations/decompte-valider"'));
        $this->assertStringNotContainsString('data-settlement-waits', $html);
    }

    public function testADamageUnderAssessmentReplacesValiderWithASentenceAndALinkToIt(): void
    {
        $url = '/mes-locations/local/reservations/9/etat-des-lieux#incident-4';

        $html = $this->renderSettlement(['pending_incident_url' => $url]);

        $this->assertStringNotContainsString('/mes-locations/decompte-valider', $html);
        $this->assertSame(2, substr_count($html, 'data-settlement-waits="assessment"'));
        $this->assertStringContainsString("Un dégât est en cours d'évaluation", $html);
        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->assertStringContainsString("Voir l'incident", $html);
        // Recording a version stays possible: only its validation waits.
        $this->assertStringContainsString('action="/mes-locations/decompte"', $html);
    }

    public function testAnOutdatedVersionSaysToRecordANewOneAndTheOtherStillValidates(): void
    {
        $html = $this->renderSettlement(['outdated_settlement_ids' => [11]]);

        $this->assertSame(1, substr_count($html, 'data-settlement-waits="outdated"'));
        $this->assertStringContainsString('enregistrez-en', $html);
        $this->assertSame(1, substr_count($html, 'action="/mes-locations/decompte-valider"'));
        $this->assertStringContainsString('name="settlement_id" value="12"', $html);
        $this->assertStringNotContainsString('name="settlement_id" value="11"', $html);
    }

    private function renderSummary(bool $damageUnderAssessment, SecurityDepositStatus $status): string
    {
        $twig = TestTwig::create(['rental']);

        return $twig->render('@rental/management/_finance_summary.html.twig', [
            'booking' => ['status' => BookingStatus::CONFIRMED],
            'settlement' => null,
            'damage_under_assessment' => $damageUnderAssessment,
            'payment' => [
                'rental_cents' => 40000,
                'total_cents' => 45000,
                'received_cents' => 45000,
                'remaining_cents' => 0,
                'overpaid_cents' => 0,
                'refunded_cents' => 0,
                'fully_paid' => true,
                'deposit_cents' => null,
                'balance_due_date' => null,
                'security_deposit' => ['amount_cents' => 5000, 'status' => $status],
            ],
        ]);
    }

    public function testFinancesSaysTheDepositIsHeldWhileADamageIsAssessed(): void
    {
        $this->assertStringContainsString(
            "Caution retenue : dégât en cours d'évaluation",
            $this->renderSummary(true, SecurityDepositStatus::RECEIVED)
        );
        $this->assertStringNotContainsString(
            'data-security-deposit-held',
            $this->renderSummary(false, SecurityDepositStatus::RECEIVED)
        );
        // Already given back: nothing is held any more.
        $this->assertStringNotContainsString(
            'data-security-deposit-held',
            $this->renderSummary(true, SecurityDepositStatus::RETURNED)
        );
    }
}
