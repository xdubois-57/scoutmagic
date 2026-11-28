<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\Duplicate\MemberMergeListener;
use Core\Member\Duplicate\MergeRowRepository;
use Modules\Groups\Repository\PollRepository;

/**
 * Brings a member's discussion-group rows along when two of their
 * identities are merged (issue #1462): an explicit membership granted to
 * the absorbed identity no longer drops out of the group, and what they
 * wrote stays theirs.
 *
 * **Collision rules:** a membership held by both identities in one group is
 * one membership, and the moderator one stays (the kept identity's on a
 * tie); a reaction or a report on the same message is one, the kept
 * identity's stays; what was last read in a group is the later of the two.
 * A poll ballot is keyed by `m:<member id>`, so PollRepository::mergeVoter()
 * rewrites that key too; a poll both identities answered keeps the kept
 * identity's ballots. Authorship, the invitation's « invité par » and a
 * group's creator simply follow. Rate-limit counters and the link
 * preview log are short-lived and stay where they are.
 */
final class GroupMergeListener implements MemberMergeListener
{
    /**
     * Unique per member and something: table => [member column, the rest
     * of the unique index, which row stays].
     *
     * @var array<string, array{string, list<string>, string}>
     */
    private const UNIQUE = [
        'discussion_group_members' => ['member_id', ['group_id'], 'is_moderator'],
        'discussion_group_post_reactions' => ['member_id', ['post_id'], 'kept'],
        'discussion_group_reply_reactions' => ['member_id', ['reply_id'], 'kept'],
        'discussion_group_post_reports' => ['reporter_member_id', ['post_id'], 'kept'],
        'discussion_group_reply_reports' => ['reporter_member_id', ['reply_id'], 'kept'],
        'discussion_group_reads' => ['member_id', ['group_id'], 'last_read_at'],
    ];

    /** @var array<string, string> table => member column, no unique index */
    private const PLAIN = [
        'discussion_group_members' => 'invited_by_member_id',
        'discussion_groups' => 'created_by_member_id',
        'discussion_group_posts' => 'author_member_id',
        'discussion_group_replies' => 'author_member_id',
    ];

    public function __construct(private MergeRowRepository $rows, private PollRepository $polls)
    {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        return [
            'appartenance(s) à un groupe de discussion' => $this->rows->count(
                'discussion_group_members',
                'member_id',
                $duplicateMemberId
            ),
            'message(s) et réponse(s) dans les groupes' => $this->rows->count(
                'discussion_group_posts',
                'author_member_id',
                $duplicateMemberId
            ) + $this->rows->count('discussion_group_replies', 'author_member_id', $duplicateMemberId),
        ];
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        $handled = 0;
        foreach (self::UNIQUE as $table => [$column, $scope, $winner]) {
            $handled += $this->rows->repointUnique(
                $table,
                $column,
                $scope,
                $winner,
                $keptMemberId,
                $duplicateMemberId
            )['handled'];
        }
        foreach (self::PLAIN as $table => $column) {
            $handled += $this->rows->repoint($table, $column, $keptMemberId, $duplicateMemberId);
        }
        $handled += $this->polls->mergeVoter($keptMemberId, $duplicateMemberId)['handled'];

        return $handled;
    }

    public function moduleId(): string
    {
        return 'groups';
    }
}
