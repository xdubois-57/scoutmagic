<?php

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
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? ORDER BY submitted_at ASC, id ASC');
        $stmt->execute([$formId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return FormResponse[] responses submitted strictly after $sinceDatetime — Task\SendResponseDigestHandler.
     */
    public function findByFormIdSince(int $formId, string $sinceDatetime): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND submitted_at > ? ORDER BY submitted_at ASC');
        $stmt->execute([$formId, $sinceDatetime]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findByAccountAndForm(int $formId, int $userAccountId): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND user_account_id = ? LIMIT 1');
        $stmt->execute([$formId, $userAccountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByMemberYearAndForm(int $formId, int $memberYearId): ?FormResponse
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_responses WHERE form_id = ? AND member_year_id = ? LIMIT 1');
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
        $stmt = $this->pdo->prepare("SELECT member_year_id FROM news_form_responses WHERE form_id = ? AND member_year_id IN ({$placeholders})");
        $stmt->execute([$formId, ...$memberYearIds]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Caller (Service\ResponseService) is responsible for wrapping this in
     * a transaction alongside its capacity check — see beginTransaction()/
     * commit()/rollBack() below — so this never opens its own.
     *
     * @param array<int, string> $values field_id => plain-text answer (encrypted uniformly, module spec — no per-field judgment)
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
            $this->encryption->encrypt(strtolower(trim($contactEmail))),
            $this->encryption->blindIndex(strtolower(trim($contactEmail))),
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
            'UPDATE news_form_responses SET contact_email = ?, contact_email_blind_index = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $this->encryption->encrypt(strtolower(trim($contactEmail))),
            $this->encryption->blindIndex(strtolower(trim($contactEmail))),
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
            $values[(int) $row['field_id']] = $row['value'] !== null ? $this->encryption->decrypt($row['value']) : '';
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
     * @param int|null $excludeResponseId when editing an existing response, its own previous value is excluded from the sum (module spec: "their own previous value is returned to the pool for the edit")
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
            $sum += (float) $this->encryption->decrypt($row['value']);
        }

        return $sum;
    }

    public function setReceivable(int $responseId, string $structuredCommunication, int $receivableId): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_form_responses SET structured_communication = ?, receivable_id = ? WHERE id = ?');
        $stmt->execute([$structuredCommunication, $receivableId, $responseId]);
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
        $stmt = $this->pdo->prepare('INSERT INTO news_form_response_values (response_id, field_id, value) VALUES (?, ?, ?)');
        foreach ($values as $fieldId => $value) {
            $stmt->execute([$responseId, $fieldId, $value !== '' ? $this->encryption->encrypt($value) : null]);
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
            contactEmail: $this->encryption->decrypt($row['contact_email']),
            structuredCommunication: $row['structured_communication'] !== null ? (string) $row['structured_communication'] : null,
            receivableId: $row['receivable_id'] !== null ? (int) $row['receivable_id'] : null,
            submittedAt: (string) $row['submitted_at'],
            updatedAt: $row['updated_at'] !== null ? (string) $row['updated_at'] : null
        );
    }
}
