<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Member\Duplicate\MemberMergeListener;
use Core\Member\Duplicate\MergeRowRepository;
use Modules\Registration\Repository\PassageNoteRepository;

/**
 * Brings the registration module's rows along when two identities of a
 * member are merged (issue #1462). Without it a family that had answered
 * the reenrollment under the absorbed identity read as « sans réponse » and
 * was reminded again, and the staff's passage note vanished.
 *
 * **Collision rules**, table by table:
 * - a reenrollment answer, a passage note or a planned section transfer is
 *   one per member and year: when both identities hold one for the same
 *   year, the one written last stays (`answered_at`, `updated_at`);
 * - a sibling declared twice on one request is one sibling: the duplicate
 *   row goes;
 * - a registration request linked to each identity keeps both requests —
 *   a request is never deleted — and the absorbed identity's link stays as
 *   it is, read through `merged_into_member_id` like any reference to it;
 * - a friend wish matched to the absorbed identity now names the kept one,
 *   and so does a friend an AI reading found in another child's passage
 *   comment (`ai_friend_member_ids`, once in the list).
 *
 * Collisions are journalled, table by table, as counts
 * (`registration_merge_collisions`), never a name or a comment.
 */
final class RegistrationMergeListener implements MemberMergeListener
{
    /**
     * One per member and year, the latest kept: table => [member column,
     * the rest of the unique index, the column that says which is latest,
     * the merge screen's label].
     *
     * @var array<string, array{string, list<string>, string, string}>
     */
    private const PER_YEAR = [
        'registration_reenrollments' => [
            'member_id', ['scout_year_id'], 'answered_at', 'réponse(s) de réinscription',
        ],
        'registration_passage_notes' => [
            'member_id', ['scout_year_id'], 'updated_at', 'note(s) de passage',
        ],
        'registration_section_transfers' => [
            'member_id', ['target_scout_year_id'], 'updated_at', 'changement(s) de section prévu(s)',
        ],
    ];

    /** @var array<string, array{string, string}> table => [column, label] */
    private const PLAIN = [
        'registration_friend_wishes' => ['matched_member_id', 'souhait(s) d\'être avec un ami'],
        'registration_request_friend_wishes' => ['matched_member_id', 'souhait(s) d\'être avec un ami'],
    ];

    public function __construct(
        private MergeRowRepository $rows,
        private JournalService $journal,
        private PassageNoteRepository $passageNotes
    ) {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        $items = [];
        foreach (self::PER_YEAR as $table => [$column, , , $label]) {
            $items[$label] = $this->rows->count($table, $column, $duplicateMemberId);
        }
        $items['mention(s) comme frère ou sœur'] = $this->rows->count(
            'registration_request_siblings',
            'member_id',
            $duplicateMemberId
        );
        $items['demande(s) d\'inscription'] = $this->rows->count(
            'registration_requests',
            'linked_member_id',
            $duplicateMemberId
        );
        foreach (self::PLAIN as $table => [$column, $label]) {
            $items[$label] = ($items[$label] ?? 0) + $this->rows->count($table, $column, $duplicateMemberId);
        }

        return $items;
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        $handled = 0;
        $collisions = [];

        foreach (self::PER_YEAR as $table => [$column, $scope, $latest]) {
            $result = $this->rows->repointUnique($table, $column, $scope, $latest, $keptMemberId, $duplicateMemberId);
            $handled += $result['handled'];
            if ($result['collisions'] > 0) {
                $collisions[$table] = $result['collisions'];
            }
        }

        $siblings = $this->rows->repointUnique(
            'registration_request_siblings',
            'member_id',
            ['registration_request_id'],
            'kept',
            $keptMemberId,
            $duplicateMemberId
        );
        $handled += $siblings['handled'];
        if ($siblings['collisions'] > 0) {
            $collisions['registration_request_siblings'] = $siblings['collisions'];
        }

        // linked_member_id is unique on its own: one request per member.
        // Only moved when the kept identity has none — a request is never
        // deleted, and a second one stays linked to the absorbed id.
        if ($this->rows->count('registration_requests', 'linked_member_id', $keptMemberId) === 0) {
            $handled += $this->rows->repoint(
                'registration_requests',
                'linked_member_id',
                $keptMemberId,
                $duplicateMemberId
            );
        } elseif ($this->rows->count('registration_requests', 'linked_member_id', $duplicateMemberId) > 0) {
            $collisions['registration_requests'] = 1;
        }

        foreach (self::PLAIN as $table => [$column]) {
            $handled += $this->rows->repoint($table, $column, $keptMemberId, $duplicateMemberId);
        }
        // The friends an AI reading found, on other children's notes.
        $handled += $this->passageNotes->repointAiFriend($keptMemberId, $duplicateMemberId);

        if ($collisions !== []) {
            $this->journal->log(
                'registration',
                'registration_merge_collisions',
                'info',
                'Fusion de fiches : données d\'inscription présentes sous les deux fiches, '
                    . 'une seule est gardée selon la règle de chaque donnée',
                [
                    'kept_member_id' => $keptMemberId,
                    'merged_member_id' => $duplicateMemberId,
                    'collisions' => $collisions,
                ]
            );
        }

        return $handled;
    }

    public function moduleId(): string
    {
        return 'registration';
    }
}
