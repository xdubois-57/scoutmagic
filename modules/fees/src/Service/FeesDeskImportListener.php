<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\DeskImportListener;
use Core\Journal\JournalService;
use Modules\Fees\Repository\RosterSnapshotRepository;

/**
 * Freezes the roster's composition at the end of every Desk import.
 *
 * The site overwrites `member_years` wholesale at each import, so without
 * this the only roster it can ever describe is today's. An invoice
 * describes the day it was issued, and checking February's invoice against
 * March's roster produces differences that were never real — which is
 * exactly the kind of false alarm that gets a verification tool abandoned.
 *
 * Three properties this listener has to have, all of them because of where
 * it runs (`Core\Import\DeskImportListener`: inside the import transaction,
 * before the commit, a throw rolling the whole import back):
 *
 * - **Bounded.** One `INSERT ... SELECT` over the roster, never a loop
 *   issuing a statement per member (`RosterSnapshotRepository::capture()`).
 * - **Replayable.** Nothing here leaves the database — no mail, no HTTP
 *   call, nothing an aborted import could not simply undo.
 * - **Silent about people.** The journal entry carries a count and two ids
 *   and nothing else (SECURITY.md §11). Who was on the roster is exactly
 *   what must not become readable there.
 *
 * $activeMemberIds is deliberately unused: the interface hands it over so a
 * listener holding its own references to `members.id` can reconcile them,
 * and this one holds none — it reads the roster back out of the same
 * transaction instead, which is what lets it record a fee category, a
 * section and a formation level the id list does not carry.
 */
class FeesDeskImportListener implements DeskImportListener
{
    public function __construct(
        private RosterSnapshotRepository $repository,
        private JournalService $journal
    ) {
    }

    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void
    {
        $snapshot = $this->repository->capture($scoutYearId, new \DateTimeImmutable());

        $this->journal->log(
            'fees',
            'fees_roster_snapshot_taken',
            'info',
            'Composition du roster figée après un import Desk : ' . $snapshot->memberCount . ' membre(s)',
            ['snapshot_id' => $snapshot->id, 'scout_year_id' => $scoutYearId, 'count' => $snapshot->memberCount]
        );
    }
}
