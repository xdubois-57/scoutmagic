<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

use Core\Security\EncryptionService;

class FormResponseRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findById(int $id): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return FormResponse[]
     */
    public function findByFormId(int $formId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? ORDER BY submitted_at ASC, '
            . 'id ASC');
        $stmt->execute([$formId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return FormResponse[] responses submitted strictly after $sinceDatetime — Task\SendResponseDigestHandler.
     */
    public function findByFormIdSince(int $formId, string $sinceDatetime): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND submitted_at > ? ORDER '
            . 'BY submitted_at ASC');
        $stmt->execute([$formId, $sinceDatetime]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByFormId(int $formId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM news_form_responses WHERE form_id = ?');
        $stmt->execute([$formId]);

        return (int) $stmt->fetchColumn();
    }

    public function findByAccountAndForm(int $formId, int $userAccountId): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND user_account_id = ? '
            . 'LIMIT 1');
        $stmt->execute([$formId, $userAccountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByMemberYearAndForm(int $formId, int $memberYearId): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND member_year_id = ? LIMIT '
            . '1');
        $stmt->execute([$formId, $memberYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param int[] $memberYearIds
     * @return int[] member_year_ids (of the given set) that already have a response for this form
     */
    public function findAnsweredMemberYearIds(int $formId, array $memberYearIds): array
    {
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare("SELECT member_year_id FROM news_form_responses WHERE form_id = ? AND "
            . "member_year_id IN ({$placeholders})");
        $stmt->execute([$formId, ...$memberYearIds]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Caller (Service\ResponseService) is responsible for wrapping this in
     * a transaction alongside its capacity check — see beginTransaction()/
     * commit()/rollBack() below — so this never opens its own.
     *
     * @param array<
     *     int,
     *     string
     * > $values field_id => plain-text answer (encrypted uniformly, module spec — no per-field judgment)
     */
    public function create(
        int $formId,
        ?int $userAccountId,
        ?int $memberYearId,
        string $contactEmail,
        array $values,
        ?string $structuredCommunication,
        ?int $receivableId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_form_responses (form_id, user_account_id, member_year_id, contact_email, contact_email_blind_index, structured_communication, receivable_id, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $formId, $userAccountId, $memberYearId,
            $this->encryption->encrypt(
                EncryptionService::normalizeEmailForIndex($contactEmail),
                'news_form_responses.contact_email'
            ),
            $this->encryption->blindIndex(
                EncryptionService::normalizeEmailForIndex($contactEmail),
                'news_contact_email'
            ),
            $structuredCommunication, $receivableId, date('Y-m-d H:i:s'),
        ]);
        $responseId = (int) $this->pdo->lastInsertId();

        $this->insertValues($responseId, $values);

        return $responseId;
    }

    /**
     * Same transaction-ownership note as create() above.
     *
     * @param array<int, string> $values field_id => plain-text answer
     */
    public function update(int $responseId, string $contactEmail, array $values): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE news_form_responses SET contact_email = ?, contact_email_blind_index = ?, updated_at = ? WHERE id '
                . '= ?'
        );
        $stmt->execute([
            $this->encryption->encrypt(
                EncryptionService::normalizeEmailForIndex($contactEmail),
                'news_form_responses.contact_email'
            ),
            $this->encryption->blindIndex(
                EncryptionService::normalizeEmailForIndex($contactEmail),
                'news_contact_email'
            ),
            date('Y-m-d H:i:s'),
            $responseId,
        ]);

        $del = $this->pdo->prepare('DELETE FROM news_form_response_values WHERE response_id = ?');
        $del->execute([$responseId]);

        $this->insertValues($responseId, $values);
    }

    /**
     * @return array<int, string> field_id => decrypted answer
     */
    public function getValues(int $responseId): array
    {
        $stmt = $this->pdo->prepare('SELECT field_id, value FROM news_form_response_values WHERE response_id = ?');
        $stmt->execute([$responseId]);

        $values = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $values[(int) $row['field_id']] = $row['value'] !== null
                ? $this->encryption->decrypt($row['value'], 'news_form_response_values.value')
                : '';
        }
        return $values;
    }

    /**
     * Sum of all numeric answers for $fieldId across every response
     * (module spec: capacity is the cumulative sum of all responses).
     * Values are encrypted, so this decrypts and sums in PHP — never in
     * SQL. $lockForUpdate takes a real row lock on MySQL (InnoDB) to
     * prevent a race between two concurrent submissions both reading a
     * stale sum; SQLite (used in tests, and by design single-writer at
     * the file level) has no FOR UPDATE syntax, so the lock is skipped
     * there — Service\ResponseService still wraps the check+insert in
     * one transaction either way.
     *
     * @param int|null $excludeResponseId when editing an existing response, its own previous value is excluded from the
     *     sum (module spec: "their own previous value is returned to the pool for the edit")
     */
    public function sumFieldValues(int $fieldId, ?int $excludeResponseId = null, bool $lockForUpdate = false): float
    {
        $sql = 'SELECT value, response_id FROM news_form_response_values WHERE field_id = ?';
        if ($lockForUpdate && $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$fieldId]);

        $sum = 0.0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($excludeResponseId !== null && (int) $row['response_id'] === $excludeResponseId) {
                continue;
            }
            if ($row['value'] === null) {
                continue;
            }
            $sum += (float) $this->encryption->decrypt($row['value'], 'news_form_response_values.value');
        }

        return $sum;
    }

    public function setReceivable(int $responseId, string $structuredCommunication, int $receivableId): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_form_responses SET structured_communication = ?, receivable_id = ? '
            . 'WHERE id = ?');
        $stmt->execute([$structuredCommunication, $receivableId, $responseId]);
    }

    /**
     * Binds a canonical reference to a response — the ticket being
     * issued. Never overwrites one: a reference already handed out in an
     * e-mail is the only thing the holder has, so lowering and raising
     * the form's flag again must not invalidate it. The WHERE clause is
     * what makes that true even against a concurrent second attempt.
     *
     * @return bool false when the row already carried a reference, or
     *              when the reference collided with another response's —
     *              the caller retries with a new one.
     */
    public function claimTicketReference(int $responseId, string $reference): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE news_form_responses SET ticket_reference = ? WHERE id = ? AND ticket_reference IS NULL'
        );

        try {
            $stmt->execute([$reference, $responseId]);
        } catch (\PDOException) {
            // The unique index refused it: two responses drew the same
            // ten characters, which is what the caller's retry is for.
            return false;
        }

        return $stmt->rowCount() === 1;
    }

    public function findByTicketReference(string $canonicalReference): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE ticket_reference = ?');
        $stmt->execute([$canonicalReference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByStructuredCommunication(string $communication): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE structured_communication = ?');
        $stmt->execute([$communication]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return FormResponse[] the form's responses that have no reference yet — what raising the flag backfills.
     */
    public function findByFormIdWithoutTicket(int $formId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM news_form_responses WHERE form_id = ? AND ticket_reference IS NULL ORDER BY id ASC'
        );
        $stmt->execute([$formId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Marks the holder in, or takes the mark back.
     *
     * `$usedAt` null is the unmarking — a scan by mistake, a validation
     * made too early. The previous site's own operation wrote true or
     * false indifferently, and so does this one.
     */
    public function setTicketUsedAt(int $responseId, ?string $usedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_form_responses SET ticket_used_at = ? WHERE id = ?');
        $stmt->execute([$usedAt, $responseId]);
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * @param array<int, string> $values
     */
    private function insertValues(int $responseId, array $values): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO news_form_response_values (response_id, field_id, value) VALUES (?, '
            . '?, ?)');
        foreach ($values as $fieldId => $value) {
            $stmt->execute([$responseId, $fieldId,
                $value !== '' ? $this->encryption->encrypt($value, 'news_form_response_values.value') : null]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FormResponse
    {
        return new FormResponse(
            id: (int) $row['id'],
            formId: (int) $row['form_id'],
            userAccountId: $row['user_account_id'] !== null ? (int) $row['user_account_id'] : null,
            memberYearId: $row['member_year_id'] !== null ? (int) $row['member_year_id'] : null,
            contactEmail: $this->encryption->decrypt($row['contact_email'], 'news_form_responses.contact_email'),
            structuredCommunication: $row['structured_communication'] !== null
                ? (string) $row['structured_communication']
                : null,
            receivableId: $row['receivable_id'] !== null ? (int) $row['receivable_id'] : null,
            submittedAt: (string) $row['submitted_at'],
            updatedAt: $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            ticketReference: ($row['ticket_reference'] ?? null) !== null ? (string) $row['ticket_reference'] : null,
            ticketUsedAt: ($row['ticket_used_at'] ?? null) !== null ? (string) $row['ticket_used_at'] : null
        );
    }
}
