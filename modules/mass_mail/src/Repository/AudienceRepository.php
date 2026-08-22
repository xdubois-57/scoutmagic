<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * mass_mail_audiences / mass_mail_audience_rows — a row's address and
 * its full data map are imported personal data → BLOB + encrypted, only
 * ever decrypted here (SECURITY.md). No blind index anywhere: nothing is
 * ever searched by value in these tables, they are only read back whole
 * (freeze, preview) or by primary key (per-recipient rendering).
 */
class AudienceRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param string[] $columns
     */
    public function createAudience(string $sourceFilename, string $sheetName, array $columns, int $rowCount, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_audiences (source_filename, sheet_name, columns_json, row_count, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sourceFilename, $sheetName, json_encode(array_values($columns)), $rowCount, $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, string> $data
     */
    public function createRow(int $audienceId, int $rowIndex, ?int $memberId, ?string $email, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_audience_rows (audience_id, row_index, member_id, email_encrypted, data_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $audienceId,
            $rowIndex,
            $memberId,
            $email !== null ? $this->encryption->encrypt($email, 'mass_mail_audience_rows.email') : null,
            $this->encryption->encrypt((string) json_encode($data), 'mass_mail_audience_rows.data'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Audience
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_audiences WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrateAudience($row) : null;
    }

    /**
     * Every row of one audience, in file order — bounded by the import
     * cap (Service\AudienceImportService::MAX_ROWS), so never paginated.
     *
     * @return AudienceRow[]
     */
    public function findRowsByAudience(int $audienceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_audience_rows WHERE audience_id = ? ORDER BY row_index ASC, id ASC');
        $stmt->execute([$audienceId]);
        return array_map([$this, 'hydrateRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The Nth data row (0-based, in file order) of one audience — the
     * compose dialog's per-recipient preview navigation.
     */
    public function findRowByOffset(int $audienceId, int $offset): ?AudienceRow
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mass_mail_audience_rows WHERE audience_id = ? ORDER BY row_index ASC, id ASC LIMIT 1 OFFSET ' . max(0, $offset)
        );
        $stmt->execute([$audienceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrateRow($row) : null;
    }

    public function findRowById(int $id): ?AudienceRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_audience_rows WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrateRow($row) : null;
    }

    /**
     * Audiences whose merge data is past retention (Task\
     * PurgeMergeAudiencesHandler): every referencing email is 'sent' and
     * the most recent send is older than $sentCutoff. An audience still
     * referenced by any draft/test/sending email is never returned,
     * however old.
     *
     * @return int[]
     */
    public function findPurgeableSentAudienceIds(\DateTimeImmutable $sentCutoff): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id
             FROM mass_mail_audiences a
             WHERE EXISTS (SELECT 1 FROM mass_mail_emails e WHERE e.audience_id = a.id)
               AND NOT EXISTS (
                   SELECT 1 FROM mass_mail_emails e
                   WHERE e.audience_id = a.id
                     AND (e.status != 'sent' OR e.sent_at IS NULL OR e.sent_at > ?)
               )"
        );
        $stmt->execute([$sentCutoff->format('Y-m-d H:i:s')]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Orphan audiences (imported but never attached to any email — e.g.
     * an abandoned dialog, or replaced by a re-import) older than
     * $createdCutoff.
     *
     * @return int[]
     */
    public function findOrphanAudienceIds(\DateTimeImmutable $createdCutoff): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id
             FROM mass_mail_audiences a
             WHERE NOT EXISTS (SELECT 1 FROM mass_mail_emails e WHERE e.audience_id = a.id)
               AND a.created_at < ?'
        );
        $stmt->execute([$createdCutoff->format('Y-m-d H:i:s')]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Rows cascade via FK; mass_mail_emails.audience_id and
     * mass_mail_recipients.audience_row_id go to NULL (schema.sql) — but
     * the SQLite test database doesn't enforce referential actions the
     * same way, so the two SET NULLs are also issued explicitly here.
     */
    public function deleteById(int $id): void
    {
        $this->pdo->prepare('UPDATE mass_mail_emails SET audience_id = NULL WHERE audience_id = ?')->execute([$id]);
        $this->pdo->prepare(
            'UPDATE mass_mail_recipients SET audience_row_id = NULL
             WHERE audience_row_id IN (SELECT id FROM mass_mail_audience_rows WHERE audience_id = ?)'
        )->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_audience_rows WHERE audience_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_audiences WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAudience(array $row): Audience
    {
        $columns = json_decode((string) $row['columns_json'], true);
        return new Audience(
            id: (int) $row['id'],
            sourceFilename: (string) $row['source_filename'],
            sheetName: (string) $row['sheet_name'],
            columns: is_array($columns) ? array_values(array_map('strval', $columns)) : [],
            rowCount: (int) $row['row_count'],
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRow(array $row): AudienceRow
    {
        $data = json_decode($this->encryption->decrypt($row['data_encrypted'], 'mass_mail_audience_rows.data'), true);
        return new AudienceRow(
            id: (int) $row['id'],
            audienceId: (int) $row['audience_id'],
            rowIndex: (int) $row['row_index'],
            memberId: $row['member_id'] !== null ? (int) $row['member_id'] : null,
            email: $row['email_encrypted'] !== null ? $this->encryption->decrypt($row['email_encrypted'], 'mass_mail_audience_rows.email') : null,
            data: is_array($data) ? array_map('strval', $data) : []
        );
    }
}
