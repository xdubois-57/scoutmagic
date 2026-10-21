<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\IncidentDecision;
use PHPUnit\Framework\TestCase;

/**
 * The three states of an incident (#980, chantier §2.1).
 */
final class IncidentDecisionTest extends TestCase
{
    public function testThereAreThreeStatesAndAManagerMayPickEachOfThem(): void
    {
        // « En cours d'évaluation » is selectable: it is where what the
        // assessment waits for is written, and where a decided incident
        // goes back to (Q5).
        $this->assertSame(IncidentDecision::cases(), IncidentDecision::decidable());
        $this->assertSame(
            ["En cours d'évaluation", 'Facturer', 'Ne pas facturer'],
            array_map(static fn(IncidentDecision $d) => $d->label(), IncidentDecision::decidable())
        );
    }

    public function testEachChoiceSaysWhatItDoes(): void
    {
        $this->assertSame(
            "Le montant s'ajoute au décompte. S'il dépasse la caution, le surplus est demandé au locataire.",
            IncidentDecision::CHARGE->consequence()
        );
        $this->assertStringContainsString("Rien n'est facturé", IncidentDecision::PENDING->consequence());
        $this->assertStringContainsString('le locataire ne le voit pas', IncidentDecision::WAIVE->consequence());
    }

    public function testOnlyAChargeEntersTheSettlement(): void
    {
        $this->assertTrue(IncidentDecision::CHARGE->entersSettlement());
        $this->assertFalse(IncidentDecision::PENDING->entersSettlement());
        $this->assertFalse(IncidentDecision::WAIVE->entersSettlement());
    }

    public function testOnlyAnAssessmentInProgressIsHiddenFromTheRenter(): void
    {
        $this->assertFalse(IncidentDecision::PENDING->isVisibleToRenter());
        $this->assertTrue(IncidentDecision::CHARGE->isVisibleToRenter());
        $this->assertTrue(IncidentDecision::WAIVE->isVisibleToRenter());
    }

    public function testTheRetiredWithholdReadsAsAChargeAndAnythingUnknownAsPending(): void
    {
        $this->assertNull(IncidentDecision::tryFrom(IncidentDecision::LEGACY_WITHHOLD), 'withhold is not a case');
        $this->assertSame(IncidentDecision::CHARGE, IncidentDecision::fromStored('withhold'));
        $this->assertSame(IncidentDecision::WAIVE, IncidentDecision::fromStored('waive'));
        $this->assertSame(IncidentDecision::PENDING, IncidentDecision::fromStored('pending'));
        $this->assertSame(IncidentDecision::PENDING, IncidentDecision::fromStored('something-else'));
    }
}
