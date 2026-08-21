<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Import\DeskImportListener;
use Core\Journal\JournalService;
use Modules\Rental\Repository\RentalAssetManagerRepository;

/**
 * Keeps manager grants in step with the Desk roster (roadmap §6.3).
 *
 * A Desk import is the moment the roster becomes authoritative again:
 * whoever left simply stops appearing. A grant held by someone no longer on
 * the roster is **deactivated, never deleted**, and their return
 * **reactivates** it — the same deactivate-then-reactivate shape core
 * already uses for sections
 * (`Core\Import\MappingResolver::deactivateAllSections()`) and member years,
 * and for the same reason: a member missing from one import (a data-entry
 * slip, a late registration) must come back without a chief re-granting
 * anything, while a member who genuinely left loses access immediately.
 *
 * Both directions are journaled as **counts only** — never a member id
 * list, never a name (SECURITY.md §5). A quiet mass deactivation is exactly
 * what an admin needs to notice after a botched import, and a count is
 * enough to notice it.
 *
 * Runs inside the import transaction (see `DeskImportListener`), so both
 * queries are bounded set operations with nothing that could fail
 * unrecoverably.
 */
class RentalDeskImportListener implements DeskImportListener
{
    public function __construct(
        private RentalAssetManagerRepository $managerRepository,
        private JournalService $journal
    ) {
    }

    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void
    {
        $onRoster = array_flip(array_map('intval', $activeMemberIds));

        // Deactivate: currently-active grants whose member is off the roster.
        $goneMemberIds = array_values(array_filter(
            $this->managerRepository->findAllActiveMemberIds(),
            static fn(int $memberId) => !isset($onRoster[$memberId])
        ));
        $deactivated = $this->managerRepository->deactivateForMembers($goneMemberIds);

        // Reactivate: previously-deactivated grants whose member is back.
        $returnedMemberIds = array_values(array_filter(
            $this->managerRepository->findAllInactiveMemberIds(),
            static fn(int $memberId) => isset($onRoster[$memberId])
        ));
        $reactivated = $this->managerRepository->reactivateForMembers($returnedMemberIds);

        if ($deactivated > 0) {
            $this->journal->log(
                'rental',
                'rental_managers_deactivated',
                'security',
                'Gestionnaires de location désactivés après un import Desk : ' . $deactivated,
                ['count' => $deactivated, 'scout_year_id' => $scoutYearId]
            );
        }

        if ($reactivated > 0) {
            $this->journal->log(
                'rental',
                'rental_managers_reactivated',
                'info',
                'Gestionnaires de location réactivés après un import Desk : ' . $reactivated,
                ['count' => $reactivated, 'scout_year_id' => $scoutYearId]
            );
        }
    }
}
