<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\OfficialDocuments\Value\HealthSheet;

/**
 * The only place a health sheet is encrypted or decrypted (SECURITY.md §5),
 * and the only place its JSON shape is ever a string.
 *
 * Everything above this class works with `HealthSheet`, a value object of
 * plain answers. Nothing above it sees ciphertext, nothing above it knows
 * the sheet is stored as JSON, and nothing in here writes to the journal or
 * builds a message out of what it read — which matters here more than
 * almost anywhere on the site: this is the one table holding health data
 * about children.
 *
 * Keyed on `members.id`, the persistent identity, with one row per member
 * replaced in place. A child's allergies do not restart every September,
 * which is why there is no scout year here (AGENTS.md § Database allows the
 * exception; `member_notes` is the same shape).
 */
class HealthSheetRepository
{
    private const ENCRYPTION_CONTEXT = 'official_documents_health_sheets.content';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * This member's sheet, or null when they have none.
     *
     * A row whose content cannot be decrypted answers null rather than
     * throwing: the key was rotated, or the row predates a restore. A
     * family then sees an empty form they can fill in again, which is a
     * far better screen than an error page — and the next save replaces
     * the unreadable row.
     */
    public function findForMember(int $memberId): ?HealthSheet
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_encrypted, last_used_at FROM official_documents_health_sheets WHERE member_id = ?'
        );
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        try {
            $json = $this->encryption->decrypt((string) $row['content_encrypted'], self::ENCRYPTION_CONTEXT);
        } catch (\Throwable) {
            // Deliberately swallowed, and deliberately not journaled: the
            // only thing worth saying would name the member, and there is
            // nothing an administrator could do with it anyway.
            return null;
        }

        $decoded = json_decode($json, true);

        return HealthSheet::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * Write this member's sheet, replacing whatever was there.
     *
     * `last_used_at` moves on every save, because saving IS using it. It
     * also moves when a document is generated from it (IT-04), which is
     * what `touch()` is for.
     */
    public function save(int $memberId, HealthSheet $sheet, \DateTimeImmutable $now): void
    {
        $content = $this->encryption->encrypt(
            (string) json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE),
            self::ENCRYPTION_CONTEXT
        );
        $stamp = $now->format('Y-m-d H:i:s');

        // One statement rather than a read-then-branch: two households
        // saving the same child's sheet at once would otherwise race into
        // a duplicate-key error on the unique index.
        $stmt = $this->pdo->prepare(
            'INSERT INTO official_documents_health_sheets
                 (member_id, content_encrypted, last_used_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 content_encrypted = VALUES(content_encrypted),
                 last_used_at = VALUES(last_used_at),
                 updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$memberId, $content, $stamp, $stamp, $stamp]);
    }

    /**
     * Say that this sheet is still in use, without touching its content.
     *
     * Generating a document from a sheet is a family relying on it, so it
     * postpones the retention purge exactly as retyping it would (IT-05).
     * A member with no sheet is a no-op rather than an error.
     */
    public function touch(int $memberId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE official_documents_health_sheets SET last_used_at = ? WHERE member_id = ?'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $memberId]);
    }

    /**
     * Remove this member's sheet entirely — « Tout effacer ».
     *
     * A delete rather than a blanked row: what the family asked for is that
     * the site stop holding their child's health data, and a row full of
     * empty strings is still a row. Answers whether there was anything to
     * remove, so the caller can journal the fact — with the member id and
     * nothing else.
     */
    public function delete(int $memberId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM official_documents_health_sheets WHERE member_id = ?');
        $stmt->execute([$memberId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * When this member's sheet was last used, or null when they have none.
     *
     * Reads the date WITHOUT decrypting anything: the screen wants to say
     * « mise à jour le 12 mars » beside a link, which is not a reason to
     * put a child's health data through a cipher.
     */
    /**
     * Delete every sheet that has not been used since the cutoff, and
     * answer whose they were.
     *
     * **Nothing is decrypted.** `last_used_at` is a plain DATETIME column
     * with an index of its own (see `schema.sql`), so the retention purge
     * decides what to delete without a single sheet going through the
     * cipher. A purge that had to open sixty fields of health data to read
     * one date would be the one job on this installation that touches
     * every family's medical answers in a single pass, at four in the
     * morning, with nobody watching.
     *
     * **Each row is deleted under its own re-checked condition**, and that
     * is not a detail of style. Selecting the ids and then deleting by id
     * alone leaves a window: a family that saves the sheet or prints the
     * document between the two statements has their data erased anyway,
     * because the delete no longer knows what it was deleting for — and
     * the journal then records a purge for a sheet that was in active use
     * at the moment it went. The erasure is permanent and the data is
     * nowhere else.
     *
     * The ids are still read first, and that half also matters both ways:
     * deleting on the date alone would take rows that went stale after the
     * read, without naming them, and an unjournalled deletion is one no
     * family can ever get an answer about.
     *
     * @return list<int> the member ids whose sheets were actually removed
     */
    public function deleteUnusedSince(\DateTimeImmutable $cutoff): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id FROM official_documents_health_sheets WHERE last_used_at < ?'
        );
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        /** @var list<int> $candidates */
        $candidates = array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $removed = [];
        foreach ($candidates as $memberId) {
            if ($this->deleteIfUnusedSince($memberId, $cutoff)) {
                $removed[] = $memberId;
            }
        }

        return $removed;
    }

    /**
     * Delete one sheet **only if it is still unused** at this instant, and
     * say whether it went.
     *
     * The whole retention guarantee lives in this one statement: the
     * condition that chose the row is re-evaluated by the engine as it
     * deletes, under the row's own lock. A `touch()` that committed first
     * makes the row stop matching and it survives; a delete that goes
     * first simply wins, which is the legitimate outcome of that race.
     *
     * A statement per member rather than one `IN (…)` over the lot: these
     * are rows nobody has opened in eighteen months, so a daily pass has a
     * handful, and `rowCount()` is then the truth about what actually went
     * — which is what the journal carries. A single bulk delete would
     * answer a total, and « une fiche a été effacée » answers no family's
     * question about their own child.
     *
     * Deliberately NOT a transaction with `SELECT … FOR UPDATE`: that
     * holds a lock on every stale sheet for the length of the pass, and it
     * takes a transaction scope this repository does not own — its caller
     * may already be in one, and PDO does not nest. The re-checked
     * condition gives the same guarantee while holding nothing.
     */
    public function deleteIfUnusedSince(int $memberId, \DateTimeImmutable $cutoff): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM official_documents_health_sheets
             WHERE member_id = ? AND last_used_at < ?'
        );
        $stmt->execute([$memberId, $cutoff->format('Y-m-d H:i:s')]);

        return $stmt->rowCount() > 0;
    }

    public function lastUsedAt(int $memberId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_used_at FROM official_documents_health_sheets WHERE member_id = ?'
        );
        $stmt->execute([$memberId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : DateInput::fromStorage((string) $value);
    }
}
