<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

use Core\Security\EncryptionService;

/**
 * camp_field_proposals. The values are encrypted for the same reason
 * Core\Audit encrypts its own: a proposal about "booked_by" holds a
 * person's name, and classifying field by field is a rule nobody
 * maintains.
 */
class FieldProposalRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findById(int $id): ?FieldProposal
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_field_proposals WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return FieldProposal[]
     */
    public function findByCamp(int $campId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_field_proposals WHERE camp_id = ? ORDER BY id ASC');
        $stmt->execute([$campId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $campIds
     * @return array<int, int> camp id => pending proposal count
     */
    public function countByCamps(array $campIds): array
    {
        $campIds = array_values(array_unique(array_map('intval', $campIds)));
        if ($campIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($campIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT camp_id, COUNT(*) AS n FROM camp_field_proposals WHERE camp_id IN ({$placeholders}) GROUP BY camp_id"
        );
        $stmt->execute($campIds);

        $counts = array_fill_keys($campIds, 0);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['camp_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Records a proposal, REPLACING any live one for the same field.
     * Three cards disagreeing about one price is not more information,
     * it is noise.
     */
    public function save(
        int $campId,
        string $fieldKey,
        ?string $currentValue,
        string $proposedValue,
        string $proposedMachineValue,
        ?string $sourceReference
    ): void {
        $this->deleteForField($campId, $fieldKey);

        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_field_proposals (camp_id, field_key, current_value, proposed_value,
                                               proposed_machine_value, source_reference, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $campId,
            $fieldKey,
            $currentValue !== null ? $this->encryption->encrypt($currentValue, 'camp_field_proposals.value') : null,
            $this->encryption->encrypt($proposedValue, 'camp_field_proposals.value'),
            $this->encryption->encrypt($proposedMachineValue, 'camp_field_proposals.value'),
            $sourceReference,
            date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM camp_field_proposals WHERE id = ?')->execute([$id]);
    }

    public function deleteForField(int $campId, string $fieldKey): void
    {
        $this->pdo->prepare('DELETE FROM camp_field_proposals WHERE camp_id = ? AND field_key = ?')
            ->execute([$campId, $fieldKey]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FieldProposal
    {
        return new FieldProposal(
            id: (int) $row['id'],
            campId: (int) $row['camp_id'],
            fieldKey: (string) $row['field_key'],
            currentValue: $row['current_value'] !== null && $row['current_value'] !== ''
                ? $this->encryption->decrypt((string) $row['current_value'], 'camp_field_proposals.value')
                : null,
            proposedValue: $this->encryption->decrypt((string) $row['proposed_value'], 'camp_field_proposals.value'),
            proposedMachineValue: $this->encryption->decrypt((string) $row['proposed_machine_value'], 'camp_field_proposals.value'),
            sourceReference: $row['source_reference'] !== null ? (string) $row['source_reference'] : null,
            createdAt: (string) $row['created_at'],
        );
    }
}
