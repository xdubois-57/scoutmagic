<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Database\ConstraintViolation;
use Core\Security\EncryptionService;

/**
 * `registration_passage_notes` — what a chief writes on the Passage page
 * about a continuing member: the section they read the family as wanting,
 * and an internal note (roadmap IT-17).
 *
 * The only place `staff_note_encrypted` is written or read in clear
 * (SECURITY.md §5). No blind index: nothing looks a note up by its text.
 *
 * **Never the family's own answer.** That lives in
 * `registration_reenrollments`, and every reader of it treats a row as
 * « this family has answered ». A chief typing here must not put words in
 * a parent's mouth, nor take a silent family out of the reminder list by
 * writing about them.
 */
class PassageNoteRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Set the section the staff reads as wanted, leaving the note alone.
     *
     * Two writes rather than one, exactly as « Départs » splits its
     * checkbox from its comment and for the same reason: the page saves
     * each field on its own as it is edited, and one save must never
     * silently clobber the other's field.
     */
    public function setPreferredSection(
        int $memberId,
        int $scoutYearId,
        ?int $sectionId,
        ?int $actingUserAccountId
    ): void
    {
        $this->ensureRow($memberId, $scoutYearId);

        $stmt = $this->pdo->prepare(
            'UPDATE registration_passage_notes
                SET preferred_section_id = ?, updated_at = ?, updated_by_user_account_id = ?
              WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$sectionId, $this->now(), $actingUserAccountId, $memberId, $scoutYearId]);
    }

    public function setStaffNote(int $memberId, int $scoutYearId, ?string $note, ?int $actingUserAccountId): void
    {
        $this->ensureRow($memberId, $scoutYearId);

        $trimmed = $note !== null ? trim($note) : '';
        $stmt = $this->pdo->prepare(
            'UPDATE registration_passage_notes
                SET staff_note_encrypted = ?, updated_at = ?, updated_by_user_account_id = ?
              WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([
            $trimmed !== '' ? $this->encryption->encrypt($trimmed, 'registration_passage_notes.staff_note') : null,
            $this->now(),
            $actingUserAccountId,
            $memberId,
            $scoutYearId,
        ]);
    }

    /**
     * Record the AI's reading of one family comment, against the hash of
     * the comment it was drawn from (roadmap IT-17).
     *
     * The hash is what makes « one call per comment » decidable without a
     * second table: a comment whose hash still matches has already been
     * read. A new suggestion always arrives unconfirmed — a machine
     * reading is a hint to a chief, and re-reading an edited comment
     * cannot inherit the validation of the sentence it replaced.
     *
     * `$sectionId` and `$friendMemberIds` are the structured half of the
     * same reading (issue #733), already resolved by the caller to a
     * section of the arrival branch and to member ids — never a name.
     *
     * @param array<int, int> $friendMemberIds
     */
    public function setAiSuggestion(
        int $memberId,
        int $scoutYearId,
        string $sourceHash,
        ?string $suggestion,
        ?int $sectionId = null,
        array $friendMemberIds = []
    ): void {
        $this->ensureRow($memberId, $scoutYearId);

        $friendMemberIds = array_values(array_unique(array_map('intval', $friendMemberIds)));

        $stmt = $this->pdo->prepare(
            'UPDATE registration_passage_notes
                SET ai_source_hash = ?, ai_suggestion_encrypted = ?, ai_confirmed = 0,
                    ai_section_id = ?, ai_friend_member_ids = ?
              WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([
            $sourceHash,
            $suggestion !== null && trim($suggestion) !== ''
                ? $this->encryption->encrypt(trim($suggestion), 'registration_passage_notes.ai_suggestion')
                : null,
            $sectionId,
            $friendMemberIds === [] ? null : json_encode($friendMemberIds, JSON_THROW_ON_ERROR),
            $memberId,
            $scoutYearId,
        ]);
    }

    /**
     * The chief saying « oui, c'est bien ce qu'ils demandent ».
     *
     * A mark of agreement on the sentence shown to the chief. Nothing
     * confirms itself, and nothing is confirmed by being displayed. The
     * optimiser does not wait for it (issue #733): it ranks the resolved
     * reading below the staff's choice and the family's own fields.
     */
    public function confirmAiSuggestion(int $memberId, int $scoutYearId, bool $confirmed): void
    {
        $this->ensureRow($memberId, $scoutYearId);

        $stmt = $this->pdo->prepare(
            'UPDATE registration_passage_notes SET ai_confirmed = ? WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$confirmed ? 1 : 0, $memberId, $scoutYearId]);
    }

    /**
     * Every staff entry for one target year, keyed by member id — what the
     * Passage page reads, so it never fires one query per line.
     *
     * @return array<
     *     int,
     *     array{
     *         preferred_section_id: ?int,
     *         staff_note: ?string,
     *         ai_source_hash: ?string,
     *         ai_suggestion: ?string,
     *         ai_confirmed: bool,
     *         ai_section_id: ?int,
     *         ai_friend_member_ids: array<int, int>
     *     }
     * >
     */
    public function findForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_passage_notes WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);

        $notes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $notes[(int) $row['member_id']] = $this->hydrate($row);
        }

        return $notes;
    }

    /**
     * @return array{
     *     preferred_section_id: ?int,
     *     staff_note: ?string,
     *     ai_source_hash: ?string,
     *     ai_suggestion: ?string,
     *     ai_confirmed: bool,
     *     ai_section_id: ?int,
     *     ai_friend_member_ids: array<int, int>
     * }|null
     */
    public function find(int $memberId, int $scoutYearId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_passage_notes WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     preferred_section_id: ?int,
     *     staff_note: ?string,
     *     ai_source_hash: ?string,
     *     ai_suggestion: ?string,
     *     ai_confirmed: bool,
     *     ai_section_id: ?int,
     *     ai_friend_member_ids: array<int, int>
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'preferred_section_id' => $row['preferred_section_id'] !== null ? (int) $row['preferred_section_id'] : null,
            'staff_note' => $row['staff_note_encrypted'] !== null
                ? $this->encryption->decrypt($row['staff_note_encrypted'], 'registration_passage_notes.staff_note')
                : null,
            'ai_source_hash' => $row['ai_source_hash'] !== null ? (string) $row['ai_source_hash'] : null,
            'ai_suggestion' => $row['ai_suggestion_encrypted'] !== null
                ? $this->encryption->decrypt(
                    $row['ai_suggestion_encrypted'],
                    'registration_passage_notes.ai_suggestion'
                )
                : null,
            'ai_confirmed' => (bool) ($row['ai_confirmed'] ?? false),
            'ai_section_id' => ($row['ai_section_id'] ?? null) !== null ? (int) $row['ai_section_id'] : null,
            'ai_friend_member_ids' => self::decodeIds($row['ai_friend_member_ids'] ?? null),
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function decodeIds(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
    }

    /**
     * SELECT, then INSERT when there is no row, then the caller's UPDATE —
     * rather than one upsert: MySQL's `ON DUPLICATE KEY` and SQLite's
     * `ON CONFLICT` are different statements, and this module's schema is
     * exercised on both engines (`scripts/test-engines.sh`).
     *
     * Two saves on the same child can both find no row and both insert.
     * The unique index on (member, year) refuses the second, and that
     * refusal is caught: the row this method exists to guarantee is there,
     * so the caller's UPDATE goes ahead (issue #592). Any other failure of
     * the INSERT is still thrown.
     */
    private function ensureRow(int $memberId, int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM registration_passage_notes WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO registration_passage_notes (member_id, scout_year_id, updated_at) VALUES (?, ?, ?)'
            );
            $insert->execute([$memberId, $scoutYearId, $this->now()]);
        } catch (\PDOException $e) {
            if (!ConstraintViolation::isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
