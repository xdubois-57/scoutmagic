<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * What this module has to say about the unit's current state
 * (`Core\Attention\AttentionPointProvider`).
 *
 * One thing, and it is the one the module exists for: households whose
 * encoded tariff no longer matches who actually lives there. A member
 * arriving or leaving changes a household's size, and the category
 * encoded in Desk does not follow — so the federation invoices the wrong
 * amount, and nobody finds out until the settlement invoice in June.
 *
 * Reuses `FeeAccuracyService` rather than reasoning again: the page it
 * feeds is the one a chef d'unité is sent to, and two implementations of
 * "which households are wrong" would drift within a season.
 *
 * Bounded, as the interface requires: one report, already built from
 * aggregate queries for its own page, and this reads only its counters.
 */
class FeesAttentionProvider implements AttentionPointProvider
{
    public function __construct(private FeeAccuracyService $accuracy)
    {
    }

    public function sourceLabel(): string
    {
        return 'Cotisations';
    }

    public function collect(int $scoutYearId): array
    {
        $report = $this->accuracy->report($scoutYearId);

        $wrong = count($report->toCorrect);
        if ($wrong === 0) {
            return [];
        }

        $difference = $report->toCorrectDifferenceCents();
        $why = $difference !== null
            ? 'Écart estimé de ' . number_format(abs($difference) / 100, 2, ',', ' ') . ' € sur la prochaine facture de la fédération.'
            : "L'écart en euros n'est pas chiffrable tant que le barème ne porte pas de montant pour les catégories concernées.";

        return [new AttentionPoint(
            title: $wrong . ' foyer' . ($wrong > 1 ? 's portent' : ' porte') . ' une catégorie tarifaire devenue fausse',
            why: $why . " La catégorie encodée dans Desk ne suit pas automatiquement un mouvement de membre.",
            actionLabel: 'Ouvrir la justesse des tarifs',
            actionUrl: '/admin/fees/tarifs',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
