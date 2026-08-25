<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * Computes {@see ImportDiff} from two consecutive roster snapshots of the
 * same scout year.
 *
 * **The two snapshots are the diff.** There is no before/after to capture
 * during the import: the previous one has been in the database since the
 * previous import, and the new one is written a few statements earlier in
 * the same transaction. Nothing here reads the CSV — the file is kept for
 * a human to investigate with, never as a source the site computes from.
 *
 * The one thing the snapshots cannot say is what the import *created*: a
 * function, a section, a branch or a fee category this installation had
 * never seen before. `MappingResolver` is what created them, so it is
 * what reports them.
 */
class ImportDiffCalculator
{
    public function __construct(private RosterSnapshotRepository $snapshots)
    {
    }

    /**
     * @param NewMappings $newMappings what this import created on the way
     */
    public function calculate(
        RosterSnapshot $current,
        ?RosterSnapshot $previous,
        ?int $previousImportId,
        NewMappings $newMappings,
        ?ImportQuality $quality = null
    ): ImportDiff {
        $quality ??= new ImportQuality();

        if ($previous === null) {
            // Two absences that must not read the same. A predecessor id
            // with no snapshot behind it means the retention purge took
            // it: the comparison existed and is gone, which is
            // "unavailable", never "nothing moved".
            return ImportDiff::unavailable(
                $previousImportId === null
                    ? ImportDiff::UNAVAILABLE_FIRST_OF_SEASON
                    : ImportDiff::UNAVAILABLE_PREDECESSOR_PURGED,
                $quality
            );
        }

        $before = $this->byMember($this->snapshots->findMembers($previous->id));
        $after = $this->byMember($this->snapshots->findMembers($current->id));

        $arrived = array_values(array_diff(array_keys($after), array_keys($before)));
        $departed = array_values(array_diff(array_keys($before), array_keys($after)));
        sort($arrived);
        sort($departed);

        $sectionChanges = [];
        $functionChanges = [];
        $roleChanges = [];
        $feeCategoryChanges = [];
        $adminGained = [];
        $adminLost = [];

        foreach ($after as $memberId => $now) {
            if (!isset($before[$memberId])) {
                // An arrival is an arrival, not four changes: reporting
                // "section changed from nothing to Baladins 1" for
                // somebody who was not there is noise that buries the
                // changes that did happen to people who were.
                if ($now->functionRole === 'admin') {
                    $adminGained[] = $memberId;
                }
                continue;
            }

            $was = $before[$memberId];

            if ($was->sectionId !== $now->sectionId) {
                $sectionChanges[$memberId] = ['from' => $was->sectionId, 'to' => $now->sectionId];
            }
            if ($was->functionId !== $now->functionId) {
                $functionChanges[$memberId] = ['from' => $was->functionId, 'to' => $now->functionId];
            }
            if ($was->functionRole !== $now->functionRole) {
                $roleChanges[$memberId] = ['from' => $was->functionRole, 'to' => $now->functionRole];

                if ($now->functionRole === 'admin') {
                    $adminGained[] = $memberId;
                } elseif ($was->functionRole === 'admin') {
                    $adminLost[] = $memberId;
                }
            }
            if ($was->feeCategoryId !== $now->feeCategoryId) {
                $feeCategoryChanges[$memberId] = ['from' => $was->feeCategoryId, 'to' => $now->feeCategoryId];
            }
        }

        // A departing chef d'unité loses the Configuration as surely as
        // one whose role changed — they simply do so by disappearing,
        // which the loop above never sees.
        foreach ($departed as $memberId) {
            if ($before[$memberId]->functionRole === 'admin') {
                $adminLost[] = $memberId;
            }
        }

        ksort($sectionChanges);
        ksort($functionChanges);
        ksort($roleChanges);
        ksort($feeCategoryChanges);
        sort($adminGained);
        sort($adminLost);

        [$goneInactive, $goneActive] = $this->sectionMovements($before, $after);

        return new ImportDiff(
            available: true,
            unavailableReason: null,
            previousImportId: $previousImportId,
            arrivedMemberIds: $arrived,
            departedMemberIds: $departed,
            sectionChanges: $sectionChanges,
            functionChanges: $functionChanges,
            roleChanges: $roleChanges,
            feeCategoryChanges: $feeCategoryChanges,
            adminGainedMemberIds: $adminGained,
            adminLostMemberIds: $adminLost,
            newFunctionIds: $newMappings->functionIds,
            newSectionIds: $newMappings->sectionIds,
            newBranchIds: $newMappings->branchIds,
            newFeeCategoryIds: $newMappings->feeCategoryIds,
            sectionsGoneInactiveIds: $goneInactive,
            sectionsGoneActiveIds: $goneActive,
            quality: $quality
        );
    }

    /**
     * Sections that lost their last member, and sections that gained
     * their first.
     *
     * The first of those is the spectacular and, until now, unexplained
     * consequence of an import: an emptied section drops out of every
     * picker on the site at once (`ImportSectionRepository::
     * deactivateAll()` plus the reactivation pass). Saying so is most of
     * what the report is for.
     *
     * @param array<int, RosterSnapshotMember> $before
     * @param array<int, RosterSnapshotMember> $after
     * @return array{int[], int[]}
     */
    private function sectionMovements(array $before, array $after): array
    {
        $sectionsBefore = $this->populatedSections($before);
        $sectionsAfter = $this->populatedSections($after);

        $goneInactive = array_values(array_diff($sectionsBefore, $sectionsAfter));
        $goneActive = array_values(array_diff($sectionsAfter, $sectionsBefore));
        sort($goneInactive);
        sort($goneActive);

        return [$goneInactive, $goneActive];
    }

    /**
     * @param array<int, RosterSnapshotMember> $members
     * @return int[]
     */
    private function populatedSections(array $members): array
    {
        $sections = [];
        foreach ($members as $member) {
            if ($member->sectionId !== null) {
                $sections[$member->sectionId] = true;
            }
        }

        return array_keys($sections);
    }

    /**
     * @param RosterSnapshotMember[] $members
     * @return array<int, RosterSnapshotMember>
     */
    private function byMember(array $members): array
    {
        $byMember = [];
        foreach ($members as $member) {
            $byMember[$member->memberId] = $member;
        }

        return $byMember;
    }
}
