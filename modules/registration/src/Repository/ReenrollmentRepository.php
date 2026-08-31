<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * `registration_reenrollments` and `registration_friend_wishes` — the only
 * place their two encrypted columns are ever written or read in clear
 * (SECURITY.md §5, ARCHITECTURE.md §7.2).
 *
 * **No blind index on either.** A blind index exists so a value can be
 * looked up by its exact text; nothing here ever is. A family comment is
 * read back to the family that wrote it, and a friend's name is resolved
 * once, server-side, by a name search that is deliberately fuzzy — not by
 * an equality lookup. Adding one would be a searchable copy of a third
 * party's name for no reader.
 *
 * **The absence of a row is "no answer yet."** There is no third decision
 * value, so `findAnswer()` returning null and a campaign's "who is still
 * silent" query read the same fact from the same place.
 */
class ReenrollmentRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Store a family's answer, replacing any earlier one for the same
     * member and year (the table's own UNIQUE says there is at most one).
     *
     * The wishes are rewritten wholesale rather than merged: they are one
     * answer to one question, and a partial update would leave a family's
     * second thoughts sitting next to their first.
     *
     * @param array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}> $friendWishes
     *        already resolved and already capped by the caller
     */
    public function saveAnswer(
        int $memberId,
        int $scoutYearId,
        string $decision,
        ?int $preferredSectionId,
        ?string $familyComment,
        ?int $answeredByUserAccountId,
        array $friendWishes
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $comment = $familyComment !== null && trim($familyComment) !== ''
            ? $this->encryption->encrypt(trim($familyComment), 'registration_reenrollments.family_comment')
            : null;

        $existingId = $this->findAnswerId($memberId, $scoutYearId);

        if ($existingId === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO registration_reenrollments
                    (member_id, scout_year_id, decision, preferred_section_id, family_comment_encrypted, answered_at, answered_by_user_account_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$memberId, $scoutYearId, $decision, $preferredSectionId, $comment, $now, $answeredByUserAccountId]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE registration_reenrollments
                    SET decision = ?, preferred_section_id = ?, family_comment_encrypted = ?,
                        answered_at = ?, answered_by_user_account_id = ?
                  WHERE id = ?'
            );
            $stmt->execute([$decision, $preferredSectionId, $comment, $now, $answeredByUserAccountId, $existingId]);
            $id = $existingId;

            $delete = $this->pdo->prepare('DELETE FROM registration_friend_wishes WHERE reenrollment_id = ?');
            $delete->execute([$id]);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO registration_friend_wishes (reenrollment_id, position, raw_name_encrypted, matched_member_id, match_state)
             VALUES (?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach ($friendWishes as $wish) {
            $insert->execute([
                $id,
                $position++,
                $this->encryption->encrypt($wish['raw_name'], 'registration_friend_wishes.raw_name'),
                $wish['matched_member_id'],
                $wish['match_state'],
            ]);
        }

        return $id;
    }

    /**
     * Record what the link with the « Départs » page just wrote into
     * member_years.leaving for this answer's child.
     *
     * A write of its own, never folded into saveAnswer(): the family
     * answering again does not by itself change what the automation last
     * put in the box, and an UPDATE that touched both would lose the
     * staff-has-the-last-word rule the moment a parent edited their
     * answer.
     */
    public function markAppliedLeaving(int $reenrollmentId, bool $applied): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_reenrollments SET applied_leaving = ? WHERE id = ?');
        $stmt->execute([$applied ? 1 : 0, $reenrollmentId]);
    }

    public function findAnswer(int $memberId, int $scoutYearId): ?ReenrollmentAnswer
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_reenrollments WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every answer for one target year, keyed by member id — what a
     * campaign's own screens read, so they never fire one query per child.
     *
     * @return array<int, ReenrollmentAnswer>
     */
    public function findAnswersForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_reenrollments WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        $answers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $answers[(int) $row['member_id']] = $this->hydrate($row);
        }

        return $answers;
    }

    /**
     * The member ids that have answered for this year — the cheap half of
     * "who is still silent", without decrypting a single comment.
     *
     * @return array<int, int>
     */
    public function answeredMemberIds(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM registration_reenrollments WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The public form's own « avec qui » entries, for a request that has
     * no member id yet.
     *
     * Rewritten wholesale like the reenrollment ones, and for the same
     * reason: they are one answer to one question.
     *
     * @param array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}> $friendWishes
     *        already resolved and already capped by the caller
     */
    public function saveRequestWishes(int $registrationRequestId, array $friendWishes): void
    {
        $delete = $this->pdo->prepare('DELETE FROM registration_request_friend_wishes WHERE registration_request_id = ?');
        $delete->execute([$registrationRequestId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO registration_request_friend_wishes (registration_request_id, position, raw_name_encrypted, matched_member_id, match_state)
             VALUES (?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach ($friendWishes as $wish) {
            $insert->execute([
                $registrationRequestId,
                $position++,
                $this->encryption->encrypt($wish['raw_name'], 'registration_friend_wishes.raw_name'),
                $wish['matched_member_id'],
                $wish['match_state'],
            ]);
        }
    }

    /**
     * @return array<int, FriendWish>
     */
    public function findRequestWishes(int $registrationRequestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_request_friend_wishes WHERE registration_request_id = ? ORDER BY position ASC'
        );
        $stmt->execute([$registrationRequestId]);

        return $this->hydrateWishes($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function findAnswerId(int $memberId, int $scoutYearId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM registration_reenrollments WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ReenrollmentAnswer
    {
        $id = (int) $row['id'];

        return new ReenrollmentAnswer(
            id: $id,
            memberId: (int) $row['member_id'],
            scoutYearId: (int) $row['scout_year_id'],
            decision: (string) $row['decision'],
            preferredSectionId: $row['preferred_section_id'] !== null ? (int) $row['preferred_section_id'] : null,
            familyComment: $row['family_comment_encrypted'] !== null
                ? $this->encryption->decrypt($row['family_comment_encrypted'], 'registration_reenrollments.family_comment')
                : null,
            answeredAt: DateInput::requireFromStorage((string) $row['answered_at'], 'answered_at'),
            answeredByUserAccountId: $row['answered_by_user_account_id'] !== null ? (int) $row['answered_by_user_account_id'] : null,
            friendWishes: $this->findWishes($id),
            appliedLeaving: $row['applied_leaving'] === null ? null : (bool) $row['applied_leaving'],
        );
    }

    /**
     * @return array<int, FriendWish>
     */
    private function findWishes(int $reenrollmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_friend_wishes WHERE reenrollment_id = ? ORDER BY position ASC'
        );
        $stmt->execute([$reenrollmentId]);

        return $this->hydrateWishes($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, FriendWish>
     */
    private function hydrateWishes(array $rows): array
    {
        $wishes = [];
        foreach ($rows as $row) {
            $wishes[] = new FriendWish(
                id: (int) $row['id'],
                position: (int) $row['position'],
                rawName: $this->encryption->decrypt($row['raw_name_encrypted'], 'registration_friend_wishes.raw_name'),
                matchedMemberId: $row['matched_member_id'] !== null ? (int) $row['matched_member_id'] : null,
                matchState: (string) $row['match_state'],
            );
        }

        return $wishes;
    }
}
