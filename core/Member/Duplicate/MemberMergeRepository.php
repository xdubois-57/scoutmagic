<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * The statements behind {@see MemberMergeService}: counting what a merge
 * would move, and moving it.
 *
 * They lived in the Service until issue #629, which is where the Service
 * layer is not allowed to keep SQL (ARCHITECTURE.md §13). The decisions —
 * refusing a merge with itself, refusing two identities present in the
 * same scout year, journaling — stay in the Service; this class answers
 * and writes.
 *
 * **The whole write is one method, and one transaction.** A half-merged
 * member is a member whose history is split across two rows in a way
 * nobody can see, which is worse than the duplicate the merge was meant to
 * repair. So {@see mergeInto()} owns the transaction, including the
 * candidate row's decision, rather than leaving a caller to open one
 * around several calls.
 */
class MemberMergeRepository
{
    /**
     * Core tables carrying a `members.id`, and the column that carries
     * it. `files.owner_member_id` is in here on purpose: a member's
     * private documents are gated on it (§8.3), so a merge that forgot it
     * would leave the returning member unable to open their own papers.
     *
     * A module holding its own reference is not in here and must not be:
     * core does not know those tables exist, and reaching into them would
     * be the §7.4 inversion. What a module gets instead is the Desk alias.
     *
     * @var array<string, string>
     */
    private const MEMBER_REFERENCES = [
        'member_years' => 'member_id',
        'member_section_periods' => 'member_id',
        'member_photos' => 'member_id',
        'member_documents' => 'member_id',
        'member_emails' => 'member_id',
        'notifications' => 'member_id',
        'fees_roster_snapshot_members' => 'member_id',
        'files' => 'owner_member_id',
    ];

    public function __construct(
        private \PDO $pdo,
        private DuplicateMemberRepository $candidates
    ) {
    }

    /**
     * Count what a merge of $duplicateMemberId would move, moving nothing.
     */
    public function preview(int $duplicateMemberId): MergePreview
    {
        return new MergePreview(
            scoutYears: $this->count('member_years', 'member_id', $duplicateMemberId),
            photos: $this->count('member_photos', 'member_id', $duplicateMemberId),
            badges: $this->countBadges($duplicateMemberId),
            documents: $this->count('member_documents', 'member_id', $duplicateMemberId),
            sectionPeriods: $this->count('member_section_periods', 'member_id', $duplicateMemberId),
            files: $this->count('files', 'owner_member_id', $duplicateMemberId),
            emailAddresses: $this->count('member_emails', 'member_id', $duplicateMemberId),
            notifications: $this->count('notifications', 'member_id', $duplicateMemberId),
            rosterSnapshotRows: $this->count('fees_roster_snapshot_members', 'member_id', $duplicateMemberId)
        );
    }

    /**
     * The member's Desk id, or null when the row no longer exists.
     */
    public function deskIdOf(int $memberId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT desk_id FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * How many scout years both identities have a row in.
     */
    public function sharedScoutYears(int $memberIdA, int $memberIdB): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM member_years a
             JOIN member_years b ON b.scout_year_id = a.scout_year_id
             WHERE a.member_id = ? AND b.member_id = ?'
        );
        $stmt->execute([$memberIdA, $memberIdB]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Repoint everything the duplicate carries onto the kept identity, mark
     * it merged, register its Desk id as an alias and, when the merge came
     * from a proposed pair, record that decision — all or nothing.
     *
     * The alias is the part without which the merge is pointless: the
     * abandoned `desk_id` stays in the federation's exports, and the next
     * CSV carrying it would create a brand-new `members` row.
     */
    public function mergeInto(
        int $keptMemberId,
        int $duplicateMemberId,
        string $duplicateDeskId,
        ?int $userAccountId,
        ?int $candidateId
    ): void {
        $this->pdo->beginTransaction();
        try {
            foreach (self::MEMBER_REFERENCES as $table => $column) {
                $this->repoint($table, $column, $duplicateMemberId, $keptMemberId);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE members SET merged_into_member_id = ?, merged_at = ? WHERE id = ?'
            );
            $stmt->execute([$keptMemberId, date('Y-m-d H:i:s'), $duplicateMemberId]);

            // INSERT OR IGNORE semantics by hand: the same alias arriving
            // twice is not an error.
            $stmt = $this->pdo->prepare('SELECT 1 FROM member_desk_id_aliases WHERE desk_id = ?');
            $stmt->execute([$duplicateDeskId]);
            if ($stmt->fetchColumn() === false) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO member_desk_id_aliases (member_id, desk_id, created_by) VALUES (?, ?, ?)'
                );
                $stmt->execute([$keptMemberId, $duplicateDeskId, $userAccountId]);
            }

            if ($candidateId !== null) {
                $this->candidates->decide($candidateId, 'merged', $userAccountId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Badges live on `member_badges`, keyed by member_year — they follow
     * the years rather than being repointed themselves, which is why
     * they are counted through the join and never updated here.
     */
    private function countBadges(int $memberId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_badges mb
             JOIN member_years my ON my.id = mb.member_year_id
             WHERE my.member_id = ?'
        );
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * $table and $column are literals from self::MEMBER_REFERENCES, never
     * request input; the ids are bound.
     */
    private function count(string $table, string $column, int $memberId): int
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column));
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    private function repoint(string $table, string $column, int $from, int $to): void
    {
        $stmt = $this->pdo->prepare(sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column));
        $stmt->execute([$to, $from]);
    }
}
