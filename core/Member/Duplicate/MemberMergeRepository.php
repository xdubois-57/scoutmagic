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
        // The chef d'unité's notes (issue #1462): a vigilance note written
        // on the absorbed identity used to vanish from the kept one.
        'member_notes' => 'member_id',
    ];

    /**
     * Core tables carrying a `members.id` that a merge deliberately does
     * NOT repoint the generic way, and why — what
     * Tests\Architecture\MergeRepointsEveryCoreMemberReferenceTest checks
     * the schema against, so the next core table holding a member is
     * either here or in MEMBER_REFERENCES, never forgotten.
     *
     * @var array<string, string> "table.column" => reason
     */
    public const NOT_REPOINTED = [
        'members.merged_into_member_id' => 'the merge itself, written by mergeInto()',
        'member_desk_id_aliases.member_id' => 'moved by mergeInto() with the alias it adds',
        'member_duplicate_candidates.kept_member_id' => 'the decision record of a pair',
        'member_duplicate_candidates.duplicate_member_id' => 'the decision record of a pair',
    ];

    public function __construct(
        private \PDO $pdo,
        private DuplicateMemberRepository $candidates,
        private ?MemberMergeListenerRegistry $listeners = null
    ) {
    }

    /**
     * MEMBER_REFERENCES, for the architecture test that holds it against
     * the schema.
     *
     * @return array<string, string>
     */
    public static function memberReferences(): array
    {
        return self::MEMBER_REFERENCES;
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
            rosterSnapshotRows: $this->count('fees_roster_snapshot_members', 'member_id', $duplicateMemberId),
            notes: $this->count('member_notes', 'member_id', $duplicateMemberId),
            moduleItems: $this->moduleItems($duplicateMemberId)
        );
    }

    /**
     * What the enabled modules' listeners would bring along, label =>
     * count, the empty ones left out.
     *
     * @return array<string, int>
     */
    private function moduleItems(int $duplicateMemberId): array
    {
        $items = [];
        foreach ($this->listeners?->all() ?? [] as $listener) {
            foreach ($listener->mergePreview($duplicateMemberId) as $label => $count) {
                if ($count > 0) {
                    $items[$label] = ($items[$label] ?? 0) + $count;
                }
            }
        }

        return $items;
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
     * Whether the member's row was already merged into another identity —
     * a row nobody sees any more, which can be neither kept nor merged
     * again (issue #914).
     */
    public function isMerged(int $memberId): bool
    {
        $stmt = $this->pdo->prepare('SELECT merged_into_member_id FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null;
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
     *
     * The enabled modules' listeners run inside the same transaction, after
     * core's own tables (issue #1462). Returns what each moved, module id =>
     * rows, for the journal.
     *
     * @return array<string, int>
     */
    public function mergeInto(
        int $keptMemberId,
        int $duplicateMemberId,
        string $duplicateDeskId,
        ?int $userAccountId,
        ?int $candidateId
    ): array {
        $this->pdo->beginTransaction();
        try {
            foreach (self::MEMBER_REFERENCES as $table => $column) {
                $this->repoint($table, $column, $duplicateMemberId, $keptMemberId);
            }

            $moduleRowsMoved = [];
            foreach ($this->listeners?->all() ?? [] as $listener) {
                $moved = $listener->onMembersMerged($keptMemberId, $duplicateMemberId);
                $moduleRowsMoved[$listener->moduleId()] = ($moduleRowsMoved[$listener->moduleId()] ?? 0) + $moved;
            }

            // The photos just moved to the kept member; their files' owner
            // follows, or the parents linked to the kept member could no
            // longer open their own child's original (issue #1108). A
            // generic files.owner_id is not a member reference in general —
            // only this owner type makes it one.
            $stmt = $this->pdo->prepare('UPDATE files SET owner_id = ? WHERE owner_type = ? AND owner_id = ?');
            $stmt->execute([
                $keptMemberId,
                \Core\Photo\PersonPhotoOwnershipChecker::OWNER_MEMBER,
                $duplicateMemberId,
            ]);

            $stmt = $this->pdo->prepare(
                'UPDATE members SET merged_into_member_id = ?, merged_at = ? WHERE id = ?'
            );
            $stmt->execute([$keptMemberId, date('Y-m-d H:i:s'), $duplicateMemberId]);

            // A merge chain stays one hop long (issue #914). When A was
            // merged into B and B is now merged into C, A points at C too:
            // every reader that resolves a merged identity — finance's
            // MemberLookupRepository, the registration siblings — follows
            // one hop, and a reference to A must not land on B, itself a
            // merged row. The Desk codes B had collected as aliases follow
            // for the same reason: the next import carrying A's old code
            // must reach C, not a row nobody sees any more.
            $stmt = $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE merged_into_member_id = ?');
            $stmt->execute([$keptMemberId, $duplicateMemberId]);
            $stmt = $this->pdo->prepare('UPDATE member_desk_id_aliases SET member_id = ? WHERE member_id = ?');
            $stmt->execute([$keptMemberId, $duplicateMemberId]);

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

            $this->listeners?->merged();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $moduleRowsMoved;
    }

    /**
     * Every merge already done, as [merged id, kept id] — one hop, as
     * mergeInto() keeps every chain. What a module's listener catches up on
     * the first time it is registered (issue #1462).
     *
     * @return list<array{int, int}>
     */
    public function mergedPairs(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, merged_into_member_id FROM members WHERE merged_into_member_id IS NOT NULL ORDER BY id'
        );

        return array_map(
            static fn(array $row): array => [(int) $row['id'], (int) $row['merged_into_member_id']],
            $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []
        );
    }

    /**
     * What the merges done so far look like, in one short string: how many
     * identities are merged and the latest merge's date. It changes with
     * every merge, so a module's catch-up runs again when there is one it
     * has not seen — made while the module was disabled, or concurrently
     * with its last catch-up (issue #1462).
     */
    public function mergeSignature(): string
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(*), MAX(merged_at) FROM members WHERE merged_into_member_id IS NOT NULL'
        );
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_NUM) : false;

        return $row === false ? '0|' : (int) $row[0] . '|' . (string) ($row[1] ?? '');
    }

    /**
     * Runs $listener over every merge already done, in one transaction:
     * the rows a module wrote under identities merged before it could
     * follow a merge are brought to the kept identity. Returns how many.
     */
    public function catchUp(MemberMergeListener $listener): int
    {
        $pairs = $this->mergedPairs();
        if ($pairs === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $handled = 0;
            foreach ($pairs as [$mergedId, $keptId]) {
                $handled += $listener->onMembersMerged($keptId, $mergedId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $handled;
    }

    /**
     * catchUp() by one connection at a time, per module: null when another
     * request holds it already — that one is doing the work, and this one
     * stands down instead of queueing behind it for the same row locks
     * (\Core\Database\AdvisoryLock, timeout 0).
     */
    public function catchUpAlone(MemberMergeListener $listener): ?int
    {
        $lock = 'member_merge_catch_up_' . $listener->moduleId();
        if (!\Core\Database\AdvisoryLock::acquire($this->pdo, $lock)) {
            return null;
        }
        try {
            return $this->catchUp($listener);
        } finally {
            \Core\Database\AdvisoryLock::release($this->pdo, $lock);
        }
    }

    /**
     * Repoints the notes still held by identities merged before notes were
     * part of a merge (issue #1462) onto the identity each was merged into
     * — one hop, as mergeInto() keeps every chain. Idempotent: a second run
     * finds nothing. Returns how many notes moved.
     */
    public function repointNotesOfMergedMembers(): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_notes
             SET member_id = (SELECT m.merged_into_member_id FROM members m WHERE m.id = member_notes.member_id)
             WHERE member_id IN (SELECT id FROM members WHERE merged_into_member_id IS NOT NULL)'
        );
        $stmt->execute();

        return $stmt->rowCount();
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
