<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

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
    public function setPreferredSection(int $memberId, int $scoutYearId, ?int $sectionId, ?int $actingUserAccountId): void
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
     */
    public function setAiSuggestion(int $memberId, int $scoutYearId, string $sourceHash, ?string $suggestion): void
    {
        $this->ensureRow($memberId, $scoutYearId);

        $stmt = $this->pdo->prepare(
            'UPDATE registration_passage_notes
                SET ai_source_hash = ?, ai_suggestion_encrypted = ?, ai_confirmed = 0
              WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([
            $sourceHash,
            $suggestion !== null && trim($suggestion) !== ''
                ? $this->encryption->encrypt(trim($suggestion), 'registration_passage_notes.ai_suggestion')
                : null,
            $memberId,
            $scoutYearId,
        ]);
    }

    /**
     * The chief saying « oui, c'est bien ce qu'ils demandent ».
     *
     * Only this makes a suggestion usable downstream (IT-18). Nothing
     * confirms itself, and nothing is confirmed by being displayed.
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
     * @return array<int, array{preferred_section_id: ?int, staff_note: ?string, ai_source_hash: ?string, ai_suggestion: ?string, ai_confirmed: bool}>
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
     * @return array{preferred_section_id: ?int, staff_note: ?string, ai_source_hash: ?string, ai_suggestion: ?string, ai_confirmed: bool}|null
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
     * @return array{preferred_section_id: ?int, staff_note: ?string, ai_source_hash: ?string, ai_suggestion: ?string, ai_confirmed: bool}
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
                ? $this->encryption->decrypt($row['ai_suggestion_encrypted'], 'registration_passage_notes.ai_suggestion')
                : null,
            'ai_confirmed' => (bool) ($row['ai_confirmed'] ?? false),
        ];
    }

    /**
     * INSERT … then UPDATE, rather than one upsert: MySQL's
     * `ON DUPLICATE KEY` and SQLite's `ON CONFLICT` are different
     * statements, and this module's schema is exercised on both engines
     * (`scripts/test-engines.sh`). A failed insert on the unique index is
     * the ordinary path here, not an error.
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

        $insert = $this->pdo->prepare(
            'INSERT INTO registration_passage_notes (member_id, scout_year_id, updated_at) VALUES (?, ?, ?)'
        );
        $insert->execute([$memberId, $scoutYearId, $this->now()]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
