<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Journal\JournalService;
use Core\Member\Duplicate\MemberMergeListener;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;

/**
 * Brings a child's health sheet along when two of their identities are
 * merged (issue #1462). Without it the parents saw an empty sheet, while
 * the one they had filled in stayed on the absorbed identity, out of their
 * reach, until the retention purge erased it.
 *
 * **Collision rule:** one sheet per member, so when both identities hold
 * one, the most recently used is kept — saved or written onto a document,
 * the very freshness the purge reads — and the other is deleted. That is
 * journalled (`health_sheet_merge_collision`, `warning`, the two ids and
 * which one was kept, never a word of the sheet): a deletion of health data
 * nobody can ask about is the one thing this module refuses.
 */
final class HealthSheetMergeListener implements MemberMergeListener
{
    public function __construct(
        private HealthSheetRepository $sheets,
        private JournalService $journal
    ) {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        return ['fiche santé' => $this->sheets->exists($duplicateMemberId) ? 1 : 0];
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        $outcome = $this->sheets->mergeMember($keptMemberId, $duplicateMemberId);
        if ($outcome === 'none') {
            return 0;
        }

        if ($outcome !== 'moved') {
            $this->journal->log(
                'official_documents',
                'health_sheet_merge_collision',
                'warning',
                'Fusion de fiches : deux fiches santé, la plus récemment utilisée est gardée',
                [
                    'kept_member_id' => $keptMemberId,
                    'merged_member_id' => $duplicateMemberId,
                    'kept_sheet_of' => $outcome === 'kept_duplicate' ? 'merged_member' : 'kept_member',
                ]
            );
        }

        return 1;
    }

    public function moduleId(): string
    {
        return 'official_documents';
    }
}
