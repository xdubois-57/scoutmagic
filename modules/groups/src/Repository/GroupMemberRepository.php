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
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator, moderator_user_account_id, invited_by_member_id, created_at)
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
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_members WHERE group_id = ? ORDER BY is_moderator DESC, id');
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
            $row = $this->find($groupId, $memberId);
            if ($row === null) {
                $this->pdo->commit();

                return true;
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
