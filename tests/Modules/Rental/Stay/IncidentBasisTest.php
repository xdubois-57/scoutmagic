<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentBasis;
use Modules\Rental\Stay\IncidentDecision;
use PHPUnit\Framework\TestCase;

/**
 * The fingerprint a settlement version records of its incidents (#980,
 * chantier §2.4): it moves with what moves money, and with nothing else.
 */
final class IncidentBasisTest extends TestCase
{
    private static function incident(
        int $id,
        IncidentDecision $decision,
        ?int $decided = null,
        ?int $proposed = 5000,
        ?string $awaiting = null,
        ?string $reference = null,
        ?string $waiveReason = null
    ): Incident {
        return new Incident(
            $id,
            1,
            'Vitre cassée',
            $proposed,
            $decision,
            $decided,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-04 10:00:00'),
            $awaiting,
            $reference,
            $waiveReason
        );
    }

    public function testItIsASha256(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', IncidentBasis::hashOf([]));
    }

    public function testTheOrderTheIncidentsAreReadInDoesNotCount(): void
    {
        $a = self::incident(1, IncidentDecision::CHARGE);
        $b = self::incident(2, IncidentDecision::WAIVE);

        $this->assertSame(IncidentBasis::hashOf([$a, $b]), IncidentBasis::hashOf([$b, $a]));
    }

    public function testAStateAnAmountOrAnIncidentMoreChangesIt(): void
    {
        $charged = IncidentBasis::hashOf([self::incident(1, IncidentDecision::CHARGE)]);

        $this->assertNotSame($charged, IncidentBasis::hashOf([self::incident(1, IncidentDecision::WAIVE)]));
        $this->assertNotSame($charged, IncidentBasis::hashOf([self::incident(1, IncidentDecision::PENDING)]));
        $this->assertNotSame($charged, IncidentBasis::hashOf([self::incident(1, IncidentDecision::CHARGE, 6000)]));
        $this->assertNotSame($charged, IncidentBasis::hashOf([]));
        $this->assertNotSame($charged, IncidentBasis::hashOf([
            self::incident(1, IncidentDecision::CHARGE),
            self::incident(2, IncidentDecision::PENDING),
        ]));
    }

    public function testTheChargedAmountIsTheDecidedOneFallingBackToTheProposedOne(): void
    {
        // Charged without retyping the figure means the proposed amount:
        // the same money, so the same fingerprint.
        $this->assertSame(
            IncidentBasis::hashOf([self::incident(1, IncidentDecision::CHARGE, null, 5000)]),
            IncidentBasis::hashOf([self::incident(1, IncidentDecision::CHARGE, 5000, 9000)])
        );
    }

    public function testWhatMovesNoMoneyLeavesItAlone(): void
    {
        $waived = IncidentBasis::hashOf([self::incident(1, IncidentDecision::WAIVE)]);

        $this->assertSame($waived, IncidentBasis::hashOf([
            self::incident(1, IncidentDecision::WAIVE, 7000, 5000, 'Devis', 'DOS-1', 'Usure normale'),
        ]));
    }
}
