<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;

/**
 * « Des comptes de section n'ont pas de trésorier cette année » (issue #1107).
 *
 * A section's account is reachable below `admin` only by that section's
 * treasurer, and nobody carrying the `Trésorier` badge no longer switches
 * that rule off (TreasurerScopeService::getTreasurerSectionIds()). So a
 * section account without a treasurer for the year is one only the chefs
 * d'unité can open — which happens every September, the badges being per
 * scout year, and which nobody else would otherwise be told about: the
 * section's animateurs simply stop seeing it.
 *
 * Bounded: the unit's accounts (a handful) and one aggregate query.
 */
class TreasurerCoverageAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private AccountRepository $accounts,
        private TreasurerScopeService $treasurers
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Finances';
    }

    public function collect(int $scoutYearId): array
    {
        $covered = $this->treasurers->sectionIdsWithTreasurer($scoutYearId);

        $uncovered = array_values(array_filter(
            $this->accounts->findAllOrdered(),
            static fn(Account $account): bool => $account->sectionId !== null
                && $account->status !== Account::STATUS_INACTIVE
                && !in_array($account->sectionId, $covered, true)
        ));
        if ($uncovered === []) {
            return [];
        }

        $count = count($uncovered);
        $names = implode(', ', array_map(static fn(Account $account): string => $account->name, $uncovered));

        return [new AttentionPoint(
            title: $count === 1
                ? '1 compte de section n\'a pas de trésorier cette année'
                : $count . ' comptes de section n\'ont pas de trésorier cette année',
            why: 'Seuls les chefs d\'unité y ont accès tant que personne de la section ne porte le badge '
                . '« Trésorier » pour l\'année : ' . $names . '. Attribuez le badge à un animateur de la '
                . 'section depuis sa page Staffs.',
            actionLabel: 'Ouvrir les staffs',
            actionUrl: '/chefs/staffs',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
