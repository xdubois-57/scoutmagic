<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Journal\JournalService;
use Core\Member\Duplicate\MemberMergeListener;
use Modules\Presences\Repository\PresenceRepository;

/**
 * Brings an animé's recorded evenings along when two of their identities
 * are merged (issue #1462). Without it the evenings pointed under the
 * absorbed identity came back « non renseigné » and the animé's rate fell
 * to zero.
 *
 * **Collision rule:** an evening recorded under both identities keeps the
 * row written last — the most recent decision about that evening — and the
 * other is deleted, since the sheet holds one decision per animé and
 * evening. Nothing goes unsaid: the number of such evenings is journalled
 * (`presences_merge_collisions`, ids and a count, never a name or a
 * comment).
 */
final class PresenceMergeListener implements MemberMergeListener
{
    public function __construct(
        private PresenceRepository $presences,
        private JournalService $journal
    ) {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        return ['soirée(s) de présence' => $this->presences->countForMember($duplicateMemberId)];
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        $result = $this->presences->mergeMember($keptMemberId, $duplicateMemberId);

        if ($result['collisions'] > 0) {
            $this->journal->log(
                'presences',
                'presences_merge_collisions',
                'info',
                'Fusion de fiches : soirées pointées sous les deux fiches, la saisie la plus récente est gardée : '
                    . $result['collisions'],
                [
                    'kept_member_id' => $keptMemberId,
                    'merged_member_id' => $duplicateMemberId,
                    'collisions' => $result['collisions'],
                ]
            );
        }

        return $result['handled'];
    }

    public function moduleId(): string
    {
        return 'presences';
    }
}
