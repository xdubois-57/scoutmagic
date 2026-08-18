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
    public function add(int $groupId, int $memberId, bool $isModerator = false, ?int $invitedByMemberId = null): void
    {
        if ($this->find($groupId, $memberId) !== null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator, invited_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$groupId, $memberId, $isModerator ? 1 : 0, $invitedByMemberId, Timestamps::now()]);
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
     * Grants or revokes the moderator flag. A member who has no explicit
     * row yet (a derived member of a section group) gets one created here
     * carrying only that flag — which is exactly what the row is for.
     */
    public function setModerator(int $groupId, int $memberId, bool $isModerator, ?int $grantedByMemberId = null): void
    {
        if ($this->find($groupId, $memberId) === null) {
            $this->add($groupId, $memberId, $isModerator, $grantedByMemberId);

            return;
        }

        $stmt = $this->pdo->prepare('UPDATE discussion_group_members SET is_moderator = ? WHERE group_id = ? AND member_id = ?');
        $stmt->execute([$isModerator ? 1 : 0, $groupId, $memberId]);
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
            $row['invited_by_member_id'] !== null ? (int) $row['invited_by_member_id'] : null,
            (string) $row['created_at']
        );
    }
}
