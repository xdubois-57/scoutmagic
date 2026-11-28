<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\Timestamps;

/**
 * discussion_group_members — explicit membership and the moderator flag.
 * Everything here is keyed by members.id; no name, email or phone is
 * stored or returned.
 */
class GroupMemberRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Before a merge settles the memberships held under both identities
     * (issue #1462, Service\GroupMergeListener): where both are moderators
     * of a group but only the absorbed identity's grant is bound to an
     * account, the kept row takes that binding. Otherwise the merge would
     * keep an unbound grant — one that moderates nothing,
     * GroupAccessService::canModerate() matching the account — and delete
     * the one that worked. Returns how many rows took a binding.
     */
    public function carryModeratorBinding(int $keptMemberId, int $duplicateMemberId): int
    {
        // Locked, inside the merge's transaction: a revocation committing
        // between this read and the copy below would otherwise hand the
        // revoked account to the kept row. A revocation that waits for the
        // merge follows it to the kept identity (demoteUnlessLastModerator()).
        $stmt = $this->pdo->prepare(
            'SELECT k.id AS kept_row, d.moderator_user_account_id AS account
             FROM discussion_group_members d
             JOIN discussion_group_members k ON k.group_id = d.group_id AND k.member_id = ?
             WHERE d.member_id = ? AND d.is_moderator = 1 AND d.moderator_user_account_id IS NOT NULL
               AND k.is_moderator = 1 AND k.moderator_user_account_id IS NULL' . $this->forUpdate()
        );
        $stmt->execute([$keptMemberId, $duplicateMemberId]);
        $bind = $this->pdo->prepare('UPDATE discussion_group_members SET moderator_user_account_id = ? WHERE id = ?');
        $carried = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bind->execute([(int) $row['account'], (int) $row['kept_row']]);
            $carried++;
        }

        return $carried;
    }

    /**
     * Idempotent on (group, member): inviting someone already invited
     * leaves the existing row (and its moderator flag) untouched rather
     * than failing on the unique index.
     */
    public function add(
        int $groupId,
        int $memberId,
        bool $isModerator = false,
        ?int $invitedByMemberId = null,
        ?int $moderatorUserAccountId = null
    ): void {
        if ($this->find($groupId, $memberId) !== null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator, moderator_user_account_id, '
                . 'invited_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $groupId,
            $memberId,
            $isModerator ? 1 : 0,
            $isModerator ? $moderatorUserAccountId : null,
            $invitedByMemberId,
            Timestamps::now(),
        ]);
    }

    public function find(int $groupId, int $memberId): ?GroupMember
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_members WHERE group_id = ? AND member_id = ?');
        $stmt->execute([$groupId, $memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return GroupMember[]
     */
    public function findByGroup(int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_members WHERE group_id = ? ORDER BY is_moderator '
            . 'DESC, id');
        $stmt->execute([$groupId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every explicit row held by any of $memberIds, in one query — the
     * group list resolves the caller's whole membership this way instead
     * of asking per group.
     *
     * @param int[] $memberIds
     * @return GroupMember[]
     */
    public function findByMemberIds(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM discussion_group_members WHERE member_id IN ({$placeholders})"
        );
        $stmt->execute(array_map('intval', $memberIds));

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The explicit member ids of several groups at once.
     *
     * The counterpart of findByMemberIds() for the other axis: the list
     * page needs "who is in this group" for every group it shows, and a
     * findByGroup() per row would be one query per card.
     *
     * @param int[] $groupIds
     * @return array<int, list<int>> group id => members.id values
     */
    public function findMemberIdsByGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT group_id, member_id FROM discussion_group_members WHERE group_id IN ({$placeholders})"
        );
        $stmt->execute(array_map('intval', array_values($groupIds)));

        $byGroup = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byGroup[(int) $row['group_id']][] = (int) $row['member_id'];
        }

        return $byGroup;
    }

    /**
     * Grants the moderator flag to ONE login, or revokes it. A member who
     * has no explicit row yet (a derived member of a section group) gets
     * one created here carrying only that flag — which is exactly what
     * the row is for.
     *
     * A grant always names the account it is for: "modérateur" is a power
     * one identified address holds, not a property of the membership, so
     * granting without an account id is refused rather than stored as a
     * flag nobody holds (schema.sql, and the NULL rows this replaces).
     * Revoking clears both, so re-granting later is always a fresh,
     * deliberate choice of who.
     */
    public function setModerator(
        int $groupId,
        int $memberId,
        bool $isModerator,
        ?int $grantedByMemberId = null,
        ?int $moderatorUserAccountId = null
    ): void {
        if ($isModerator && $moderatorUserAccountId === null) {
            return;
        }

        if ($this->find($groupId, $memberId) === null) {
            $this->add($groupId, $memberId, $isModerator, $grantedByMemberId, $moderatorUserAccountId);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE discussion_group_members SET is_moderator = ?, moderator_user_account_id = ?
             WHERE group_id = ? AND member_id = ?'
        );
        $stmt->execute([
            $isModerator ? 1 : 0,
            $isModerator ? $moderatorUserAccountId : null,
            $groupId,
            $memberId,
        ]);
    }

    /**
     * Binds a moderator row that names no account to one — the self-heal
     * Service\ModeratorBindingService performs for rows granted before
     * the flag became per-login, and only ever for a member with exactly
     * one account, since anything else is precisely the choice a human
     * has to make.
     *
     * Guarded on the column still being NULL so it can never overwrite a
     * deliberate grant, whatever raced with it.
     */
    public function bindModeratorAccount(int $groupId, int $memberId, int $userAccountId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discussion_group_members SET moderator_user_account_id = ?
             WHERE group_id = ? AND member_id = ? AND is_moderator = 1 AND moderator_user_account_id IS NULL'
        );
        $stmt->execute([$userAccountId, $groupId, $memberId]);
    }

    /**
     * Every moderator row that still names no account, across all groups
     * — one query, for the self-heal pass.
     *
     * @return GroupMember[]
     */
    public function findUnboundModerators(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_members
             WHERE is_moderator = 1 AND moderator_user_account_id IS NULL ORDER BY id LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many explicit moderators the group has.
     *
     * Explicit rows ONLY: a site admin moderates every group without
     * holding a row in any of them (Service\GroupSessionContext::
     * isSiteAdmin()), and counting them would let the last real moderator
     * of a group walk away leaving nobody who actually belongs to it in
     * charge. A group must keep a moderator of its own.
     */
    public function countModerators(int $groupId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_members
             WHERE group_id = ? AND is_moderator = 1 AND moderator_user_account_id IS NOT NULL'
        );
        $stmt->execute([$groupId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Removes $memberId from $groupId, refusing when they are the group's
     * last explicit moderator.
     *
     * Count and delete run in ONE transaction, and the count re-reads the
     * row inside it: checking first and deleting afterwards would let two
     * moderators leaving at the same instant both see "there are two of
     * us" and both succeed, leaving the group with none.
     *
     * @return bool false when the removal was refused because it would
     *         have left the group without a moderator
     */
    public function removeUnlessLastModerator(int $groupId, int $memberId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $row = $this->findLocked($groupId, $memberId);
            if ($row === null) {
                $keptMemberId = $this->mergedInto($memberId);
                $row = $keptMemberId !== null ? $this->findLocked($groupId, $keptMemberId) : null;
                if ($row === null) {
                    $this->pdo->commit();

                    return true;
                }
                $memberId = $keptMemberId;
            }

            // An unbound row (granted before the flag became per-login)
            // moderates nobody, so leaving with one takes nothing away.
            if ($row->isModerator && $row->moderatorUserAccountId !== null && $this->countModerators($groupId) <= 1) {
                $this->pdo->rollBack();

                return false;
            }

            $stmt = $this->pdo->prepare('DELETE FROM discussion_group_members WHERE group_id = ? AND member_id = ?');
            $stmt->execute([$groupId, $memberId]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Revokes the moderator flag from $memberId, refusing when they are
     * the group's last explicit moderator — the same rule as
     * removeUnlessLastModerator() above ("Retirer la modération" must not
     * be able to do in one click what leaving the group may not), with
     * the same transactional shape: count and update run in ONE
     * transaction, so two simultaneous revocations cannot both see
     * "there are two of us" and both succeed.
     *
     * An unbound row (granted before the flag became per-login) moderates
     * nobody, so clearing it takes nothing away and is always allowed. A
     * missing row is nothing to demote and reports success — unless the
     * member was merged meanwhile (issue #1462): the revocation then
     * follows them to the kept identity, whose row now carries what this
     * one held. The row is read FOR UPDATE, so a merge in flight is waited
     * for and its outcome seen, never raced.
     *
     * @return bool false when the revocation was refused because it would
     *         have left the group without a moderator
     */
    public function demoteUnlessLastModerator(int $groupId, int $memberId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $row = $this->findLocked($groupId, $memberId);
            if ($row === null) {
                $keptMemberId = $this->mergedInto($memberId);
                $row = $keptMemberId !== null ? $this->findLocked($groupId, $keptMemberId) : null;
                if ($row === null) {
                    $this->pdo->commit();

                    return true;
                }
                $memberId = $keptMemberId;
            }

            if ($row->isModerator && $row->moderatorUserAccountId !== null && $this->countModerators($groupId) <= 1) {
                $this->pdo->rollBack();

                return false;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE discussion_group_members SET is_moderator = 0, moderator_user_account_id = NULL
                 WHERE group_id = ? AND member_id = ?'
            );
            $stmt->execute([$groupId, $memberId]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function findLocked(int $groupId, int $memberId): ?GroupMember
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_members WHERE group_id = ? AND member_id = ?' . $this->forUpdate()
        );
        $stmt->execute([$groupId, $memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** The identity $memberId was merged into, or null when it was not (one hop, issue #914). */
    private function mergedInto(int $memberId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT merged_into_member_id FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $kept = $stmt->fetchColumn();

        return $kept !== false && $kept !== null ? (int) $kept : null;
    }

    /** ` FOR UPDATE`, or nothing on SQLite, the test engine, which has no such clause. */
    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function remove(int $groupId, int $memberId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_members WHERE group_id = ? AND member_id = ?');
        $stmt->execute([$groupId, $memberId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): GroupMember
    {
        return new GroupMember(
            (int) $row['id'],
            (int) $row['group_id'],
            (int) $row['member_id'],
            (bool) $row['is_moderator'],
            $row['moderator_user_account_id'] !== null ? (int) $row['moderator_user_account_id'] : null,
            $row['invited_by_member_id'] !== null ? (int) $row['invited_by_member_id'] : null,
            (string) $row['created_at']
        );
    }
}
