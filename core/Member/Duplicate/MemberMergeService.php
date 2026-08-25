<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Journal\JournalService;

/**
 * Folds one `members` row into another.
 *
 * **Never automatic.** Two people can carry the same surname, first name
 * and date of birth. The site proposes pairs
 * ({@see DuplicateMemberDetector}); a human decides.
 *
 * **A merge deletes nothing.** It repoints foreign keys onto the kept
 * identity and leaves the abandoned row in place, marked
 * `merged_into_member_id`. Nothing in this codebase deletes a member, and
 * a merge is not the place to start — the abandoned row is also what
 * makes the operation auditable afterwards.
 *
 * **And it registers a Desk alias**, which is the part without which the
 * whole thing is pointless: the abandoned `desk_id` stays in the
 * federation's exports, and the next CSV carrying it would create a
 * brand-new `members` row and re-open the split this just repaired.
 * `MemberRepository::findByDeskId()` consults the alias table for exactly
 * that reason.
 *
 * The tables repointed below are every core table that references
 * `members.id`. A module holding its own reference is not repointed here
 * and must not be: core does not know those tables exist, and reaching
 * into them would be the §7.4 inversion. What a module gets instead is
 * the alias — a later import resolves the old Desk id to the kept member,
 * so nothing new accumulates on the abandoned identity.
 */
class MemberMergeService
{
    /**
     * Core tables carrying a `members.id`, and the column that carries
     * it. `files.owner_member_id` is in here on purpose: a member's
     * private documents are gated on it (§8.3), so a merge that forgot it
     * would leave the returning member unable to open their own papers.
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
        private DuplicateMemberRepository $candidates,
        private JournalService $journal
    ) {
    }

    /**
     * Count what a merge would move, without moving anything.
     */
    public function preview(int $keptMemberId, int $duplicateMemberId): MergePreview
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
     * Repoint everything the duplicate carries onto the kept identity,
     * mark it merged, and register its Desk id as an alias.
     *
     * One transaction: a half-merged member is a member whose history is
     * split across two rows in a way nobody can see, which is worse than
     * the duplicate it was meant to repair.
     *
     * @throws MergeException when the pair cannot be merged, always before anything moves
     */
    public function merge(int $keptMemberId, int $duplicateMemberId, ?int $userAccountId, ?int $candidateId = null): MergePreview
    {
        if ($keptMemberId === $duplicateMemberId) {
            throw new MergeException('Une fiche ne peut pas être fusionnée avec elle-même.');
        }

        $duplicateDeskId = $this->deskIdOf($duplicateMemberId);
        if ($duplicateDeskId === null || $this->deskIdOf($keptMemberId) === null) {
            throw new MergeException("L'une des deux fiches n'existe plus. Rechargez la page.");
        }

        // Two identities present in the SAME scout year are two people as
        // far as Desk is concerned: it guarantees one desk_id per person
        // per export, so both were in the same CSV. Merging them would
        // also collide head-on with member_years' (member, year) unique
        // index. Refused rather than half-applied — the real duplicate
        // this feature is about is strictly inter-year.
        if ($this->sharedScoutYears($keptMemberId, $duplicateMemberId) > 0) {
            throw new MergeException(
                'Ces deux fiches sont présentes la même année scoute : Desk les considère comme deux '
                . "personnes distinctes. La fusion ne concerne qu'une fiche recréée d'une année à l'autre."
            );
        }

        $preview = $this->preview($keptMemberId, $duplicateMemberId);

        $this->pdo->beginTransaction();
        try {
            foreach (self::MEMBER_REFERENCES as $table => $column) {
                $this->repoint($table, $column, $duplicateMemberId, $keptMemberId);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE members SET merged_into_member_id = ?, merged_at = ? WHERE id = ?'
            );
            $stmt->execute([$keptMemberId, date('Y-m-d H:i:s'), $duplicateMemberId]);

            // Without this, the next CSV carrying the abandoned code
            // re-creates the split. INSERT OR IGNORE semantics by hand:
            // the same alias arriving twice is not an error.
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

        // Numeric identifiers and counts only — a merge is a `security`
        // event, and who was merged with whom must not become readable as
        // personal data in the journal (SECURITY.md §11).
        $this->journal->log(
            'core',
            'member_merged',
            'security',
            'Deux fiches membres ont été fusionnées',
            [
                'kept_member_id' => $keptMemberId,
                'merged_member_id' => $duplicateMemberId,
                'scout_years' => $preview->scoutYears,
                'photos' => $preview->photos,
                'badges' => $preview->badges,
                'documents' => $preview->documents,
                'files' => $preview->files,
            ],
            $userAccountId
        );

        return $preview;
    }

    /**
     * Record that a proposed pair is two different people.
     *
     * A decision, and one the site has to remember: without it every
     * import would re-propose the same pair, and a list that keeps
     * re-asking a question already answered stops being read.
     */
    public function markDistinct(int $candidateId, ?int $userAccountId): void
    {
        $this->candidates->decide($candidateId, 'distinct', $userAccountId);

        $this->journal->log(
            'core',
            'member_duplicate_dismissed',
            'security',
            'Deux fiches membres semblables ont été déclarées distinctes',
            ['candidate_id' => $candidateId],
            $userAccountId
        );
    }

    /**
     * How many scout years both identities have a row in.
     */
    private function sharedScoutYears(int $memberIdA, int $memberIdB): int
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

    private function deskIdOf(int $memberId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT desk_id FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
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
