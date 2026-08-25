<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * Surfaces undecided duplicate candidates on the attention-points page.
 *
 * The right shape for it, and the reason the result is not an import
 * report entry: a split identity **stays true until somebody decides**.
 * It is not a fact about the day an import ran — the returning member's
 * page is just as empty a month later — and it disappears from the page
 * the moment a chef d'unité either merges the two records or says they
 * are two different people.
 *
 * Bounded, as the interface requires: one `COUNT(*)` on an indexed
 * status column. The expensive half — decrypting names and birth dates
 * to find the pairs — ran once, after an import committed
 * ({@see DuplicateMemberDetector}).
 */
class DuplicateAttentionProvider implements AttentionPointProvider
{
    public function __construct(private DuplicateMemberRepository $repository)
    {
    }

    public function sourceLabel(): string
    {
        return 'Cœur';
    }

    public function collect(int $scoutYearId): array
    {
        $pending = $this->repository->countPending();
        if ($pending === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: $pending . ' fiche' . ($pending > 1 ? 's' : '') . ' membre'
                . ($pending > 1 ? ' semblent avoir été recréées' : ' semble avoir été recréée')
                . ' au lieu d\'être rouverte' . ($pending > 1 ? 's' : ''),
            why: "Quand quelqu'un revient après une absence et qu'on le recrée dans Desk plutôt que de "
                . "rouvrir sa fiche, son historique se scinde : photos, badges, documents privés et "
                . "périodes de section restent accrochés à l'ancienne identité, et sa page est vide sans "
                . "que rien ne l'explique.",
            actionLabel: 'Examiner les fiches',
            actionUrl: '/admin/doublons',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
