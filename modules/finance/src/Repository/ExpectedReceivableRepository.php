<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

class ExpectedReceivableRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function communicationExists(string $communication): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_expected_receivables WHERE communication = ?');
        $stmt->execute([$communication]);
        return $stmt->fetch() !== false;
    }

    /**
     * The receivable this communication is for, or null.
     *
     * **The input is re-canonicalised before the WHERE**, and that is the
     * whole difficulty. The column stores the issued form,
     * `+++NNN/NNNN/NNNNN+++`, while a human checking a payment types or
     * pastes whatever their bank showed them: twelve bare digits, the
     * `***…***` variant, a copy with stray spaces. Comparing the raw
     * input would find nothing for every one of those, silently — the
     * lookup would simply answer "unknown" and the person would conclude
     * the payment is not expected.
     *
     * Twelve digits in, canonical form out, one exact match: no LIKE, no
     * collation surprise, and the unique communication stays the key.
     * A value that is not twelve digits cannot match anything issued, so
     * it short-circuits rather than running a query that cannot succeed.
     */
    public function findByCommunication(string $communication): ?ExpectedReceivable
    {
        $digits = preg_replace('/\D/', '', $communication) ?? '';
        if (strlen($digits) !== 12) {
            return null;
        }

        $canonical = '+++' . substr($digits, 0, 3) . '/' . substr($digits, 3, 4) . '/' . substr($digits, 7, 5) . '+++';

        $stmt = $this->pdo->prepare('SELECT * FROM finance_expected_receivables WHERE communication = ?');
        $stmt->execute([$canonical]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountDueCents,
        string $communication,
        ?string $label,
        ?int $memberId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_expected_receivables (source_module, source_reference_id, account_id, '
                . 'amount_due_cents, communication, label_encrypted, member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sourceModule,
            $sourceReferenceId,
            $accountId,
            $amountDueCents,
            $communication,
            $label !== null ? $this->encryption->encrypt($label, 'finance_expected_receivables.label') : null,
            $memberId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Every receivable naming one of these members — what the member's
     * own page and the home banner read, in one query rather than one
     * per child.
     *
     * @param int[] $memberIds
     * @return ExpectedReceivable[]
     */
    public function findByMemberIds(array $memberIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM finance_expected_receivables WHERE member_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The receivables a set of source instances owns, keyed by
     * source_reference_id — a campaign reads a few hundred of its rows'
     * receivables at once and must not query per line.
     *
     * @param int[] $sourceReferenceIds
     * @return array<int, ExpectedReceivable>
     */
    public function findBySourceReferenceIds(string $sourceModule, array $sourceReferenceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $sourceReferenceIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM finance_expected_receivables WHERE source_module = ? AND source_reference_id IN "
                . "($placeholders) ORDER BY id ASC"
        );
        $stmt->execute(array_merge([$sourceModule], $ids));

        $byReference = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $receivable = $this->hydrate($row);
            $byReference[$receivable->sourceReferenceId] = $receivable;
        }

        return $byReference;
    }

    /**
     * Changes what a receivable expects, keeping its communication.
     *
     * Deleting and recreating would mint a new communication and orphan
     * every transfer the payer already made against the old one — the
     * amount has to move in place.
     */
    public function updateAmount(int $id, int $amountDueCents): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_expected_receivables SET amount_due_cents = ? WHERE id = ?'
        );
        $stmt->execute([$amountDueCents, $id]);
    }

    public function findById(int $id): ?ExpectedReceivable
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_expected_receivables WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return ExpectedReceivable[]
     */
    public function findBySource(string $sourceModule, int $sourceReferenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_expected_receivables WHERE source_module = ? AND source_reference_id = ? ORDER BY '
                . 'id ASC'
        );
        $stmt->execute([$sourceModule, $sourceReferenceId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * All receivables for a source module, grouped by source_reference_id
     * — used by the "Paiements attendus" reconciliation page (level 2:
     * one group per source instance).
     *
     * @return ExpectedReceivable[]
     */
    public function findAllByModule(string $sourceModule): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_expected_receivables WHERE source_module = ? ORDER BY source_reference_id ASC, id '
                . 'ASC'
        );
        $stmt->execute([$sourceModule]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Distinct source modules with at least one receivable — level 1 of
     * the reconciliation page's accordion.
     *
     * @return string[]
     */
    public function findDistinctSourceModules(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT source_module FROM finance_expected_receivables ORDER BY '
            . 'source_module ASC');
        return $stmt !== false ? array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    public function deleteBySource(string $sourceModule, int $sourceReferenceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_expected_receivables WHERE source_module = ? AND '
            . 'source_reference_id = ?');
        $stmt->execute([$sourceModule, $sourceReferenceId]);
    }

    /**
     * @param int[] $ids
     * @return ExpectedReceivable[]
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM finance_expected_receivables WHERE id IN ($placeholders) ORDER "
            . "BY id ASC");
        $stmt->execute($ids);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every receivable booked against an account — what the automatic
     * matching pass iterates over once per account, rather than once per
     * source module.
     *
     * @return ExpectedReceivable[]
     */
    public function findByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_expected_receivables WHERE account_id = ? ORDER BY id ASC');
        $stmt->execute([$accountId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Marks a receivable abandoned, or lifts the abandon ($at === null).
     *
     * The timestamp comes from PHP rather than NOW(): the test database
     * is SQLite, whose CURRENT_TIMESTAMP is UTC while the rest of the
     * application is on Europe/Brussels
     * (docs/module-development.md § Timestamps).
     */
    public function setWaived(int $id, ?string $at, ?int $by): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_expected_receivables SET waived_at = ?, waived_by = ? WHERE id = '
            . '?');
        $stmt->execute([$at, $by, $id]);
    }

    /**
     * Records — or withdraws — the decision that the surplus on this
     * receivable is owed back. Never records that it HAS been paid back:
     * that is read from the refund allocations, because the debit
     * leaving the account is what makes a refund real.
     */
    public function setRefundRequested(int $id, ?string $at, ?int $by): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_expected_receivables SET refund_requested_at = ?, '
            . 'refund_requested_by = ? WHERE id = ?');
        $stmt->execute([$at, $by, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ExpectedReceivable
    {
        return new ExpectedReceivable(
            id: (int) $row['id'],
            sourceModule: (string) $row['source_module'],
            sourceReferenceId: (int) $row['source_reference_id'],
            accountId: (int) $row['account_id'],
            amountDueCents: (int) $row['amount_due_cents'],
            communication: (string) $row['communication'],
            label: $row['label_encrypted'] !== null
                ? $this->encryption->decrypt($row['label_encrypted'], 'finance_expected_receivables.label')
                : null,
            createdAt: (string) $row['created_at'],
            memberId: isset($row['member_id']) ? (int) $row['member_id'] : null,
            waivedAt: isset($row['waived_at']) ? (string) $row['waived_at'] : null,
            waivedBy: isset($row['waived_by']) ? (int) $row['waived_by'] : null,
            refundRequestedAt: isset($row['refund_requested_at']) ? (string) $row['refund_requested_at'] : null,
            refundRequestedBy: isset($row['refund_requested_by']) ? (int) $row['refund_requested_by'] : null
        );
    }
}
