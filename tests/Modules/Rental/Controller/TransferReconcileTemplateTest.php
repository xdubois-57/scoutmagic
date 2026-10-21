<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Finance\Api\PaymentCandidate;
use Modules\Finance\Api\ReceivableMovement;
use Modules\Rental\Booking\BookingPage;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * « Rapprocher un virement » as the « Finances » templates render it
 * (#980, chantier §2.6), through the real Twig environment: the list of
 * candidates, how far the account is known, and the movements' « Rapproché
 * à la main par … » and « Détacher ».
 */
final class TransferReconcileTemplateTest extends TestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);

        return (string) preg_replace('/\s+/', ' ', $twig->render('@rental/management/' . $template, $context + [
            'asset' => ['id' => 3],
            'booking' => ['id' => 9, 'reference' => 'LOC-A2B3C4'],
            'booking_page' => BookingPage::FINANCES,
            'transfer_since' => new \DateTimeImmutable('2027-01-20'),
            'transfer_statement_at' => new \DateTimeImmutable('2027-03-21 18:30:00'),
        ]));
    }

    /** @param list<PaymentCandidate> $candidates */
    private function candidates(array $candidates, ?\DateTimeImmutable $statementAt = null): string
    {
        return $this->render('_finance_transfer_candidates.html.twig', [
            'amount_cents' => 100000,
            'candidates' => $candidates,
        ] + ($statementAt !== null ? ['transfer_statement_at' => $statementAt] : []));
    }

    /**
     * @param list<ReceivableMovement> $movements
     * @param array<int, string> $authors
     * @param list<int> $detachable
     */
    private function movements(array $movements, array $authors, array $detachable): string
    {
        return $this->render('_finance_movements.html.twig', [
            'movements' => $movements,
            'payment' => ['received_cents' => 125000],
            'transfer_available' => true,
            'movement_authors' => $authors,
            'detachable_transactions' => $detachable,
        ]);
    }

    public function testEachCandidateSaysWhenHowMuchFromWhomAndWhatThePayerWrote(): void
    {
        $html = $this->candidates([
            new PaymentCandidate(91, '2027-03-14', 100000, 'ETHIAS SA', 'Sinistre 2027/118'),
            new PaymentCandidate(92, '2027-03-15', 100000, null, 'Virement'),
        ]);

        $this->assertSame(2, substr_count($html, 'data-transfer-candidate>'));
        $this->assertStringContainsString('14/03/2027', $html);
        $this->assertStringContainsString('+1 000,00 €', $html);
        $this->assertStringContainsString('ETHIAS SA', $html);
        $this->assertStringContainsString('Sinistre 2027/118', $html);
        $this->assertStringContainsString(
            'data-confirm="Rapprocher ce virement de 1 000,00 € (ETHIAS SA, 14/03/2027) de LOC-A2B3C4 ? Il compte en '
            . 'entier comme un paiement de la réservation, même au-delà de ce qui reste dû. Aucun e-mail '
            . 'n&#039;est envoyé."',
            $html
        );
        // Without a counterparty, the date alone.
        $this->assertStringContainsString('Rapprocher ce virement de 1 000,00 € (15/03/2027) de LOC-A2B3C4 ?', $html);
        $this->assertSame(2, substr_count($html, 'action="/mes-locations/virement-rapprocher" data-async="off"'));
        $this->assertSame(2, substr_count($html, 'name="amount_cents" value="100000"'));
    }

    public function testABanksTextIsEscapedInTheListAndInTheQuestion(): void
    {
        $html = $this->candidates([new PaymentCandidate(91, '2027-03-14', 100000, '<b>"X"</b>', '<script>x</script>')]);

        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringNotContainsString('<b>"X"', $html);
        $this->assertStringContainsString('&lt;b&gt;&quot;X&quot;&lt;/b&gt;', $html);
    }

    public function testAnEmptyAnswerSaysSoAndHowFarTheAccountIsKnown(): void
    {
        $known = $this->candidates([]);
        $this->assertStringContainsString('data-transfer-candidates-empty', $known);
        $this->assertStringContainsString('Aucun virement entrant de 1 000,00 €', $known);
        $this->assertStringContainsString('Dernier extrait bancaire importé le 21/03/2027', $known);
        $this->assertStringNotContainsString('Aucun extrait importé', $known);

        $stale = $this->candidates([], new \DateTimeImmutable('2027-01-05 10:00:00'));
        $this->assertStringContainsString('Aucun extrait importé depuis le 20/01/2027', $stale);
    }

    public function testNoImportAtAllSaysNothingIsImportedSinceTheSearchStarts(): void
    {
        $html = $this->render('_finance_transfer_statement.html.twig', ['transfer_statement_at' => null]);

        $this->assertStringContainsString('Aucun extrait importé depuis le 20/01/2027', $html);
    }

    public function testAHandReconciledMovementNamesWhoAndWhenAndOffersDetachOnlyWhenListed(): void
    {
        $html = $this->movements([
            new ReceivableMovement('2027-02-01', 'Acompte', 'Jeanne Martin', 25000, true, 90),
            new ReceivableMovement(
                '2027-03-14',
                'Sinistre 2027/118',
                'ETHIAS SA',
                100000,
                false,
                91,
                '2027-03-21 09:15:00',
                7,
                true
            ),
        ], [91 => 'Marie Dubois'], [91]);

        $this->assertStringContainsString('Rapproché à la main par Marie Dubois, le 21/03/2027', $html);
        $this->assertSame(1, substr_count($html, 'Rapproché à la main'));
        $this->assertSame(1, substr_count($html, 'action="/mes-locations/virement-detacher"'));
        $this->assertStringContainsString('name="transaction_id" value="91"', $html);
        $this->assertStringContainsString(
            'data-confirm="Détacher ce virement de 1 000,00 € (ETHIAS SA, 14/03/2027) de LOC-A2B3C4 ? Il redevient '
            . 'un virement non rapproché du compte, et ce qu&#039;il payait est à nouveau attendu. Aucun e-mail '
            . 'n&#039;est envoyé."',
            $html
        );
        $this->assertStringContainsString('data-bs-target="#rapprocher-virement"', $html);
    }

    public function testAMovementASettlementCountedKeepsItsMentionButLosesDetach(): void
    {
        $html = $this->movements([
            new ReceivableMovement('2027-03-14', 'Sinistre', 'ETHIAS SA', 100000, false, 91,
                '2027-03-21 09:15:00', 7, true),
        ], [91 => 'un gestionnaire'], []);

        $this->assertStringContainsString('Rapproché à la main par un gestionnaire, le 21/03/2027', $html);
        $this->assertStringNotContainsString('virement-detacher', $html);
    }
}
