<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

class FormFieldRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?FormField
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_fields WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return FormField[]
     */
    public function findByFormId(int $formId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$formId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(
        int $formId,
        int $sortOrder,
        string $fieldType,
        ?string $label,
        bool $isRequired,
        ?string $optionsSource,
        ?string $optionsManual,
        ?int $capacityMax,
        ?float $pricePerUnit,
        ?string $confirmationText
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_form_fields (form_id, sort_order, field_type, label, is_required, options_source, '
                . 'options_manual, capacity_max, price_per_unit, confirmation_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $formId, $sortOrder, $fieldType, $label, $isRequired ? 1 : 0,
            $optionsSource, $optionsManual, $capacityMax, $pricePerUnit, $confirmationText,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * In-place update (Service\FormService's save reconciles the incoming
     * field list against existing ids rather than deleting and
     * re-inserting everything, so a field that already has responses
     * keeps its id — and their news_form_response_values rows, which
     * cascade-delete if the field itself is ever actually removed).
     */
    public function update(
        int $id,
        int $sortOrder,
        string $fieldType,
        ?string $label,
        bool $isRequired,
        ?string $optionsSource,
        ?string $optionsManual,
        ?int $capacityMax,
        ?float $pricePerUnit,
        ?string $confirmationText
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE news_form_fields SET sort_order = ?, field_type = ?, label = ?, is_required = ?, options_source = '
                . '?, options_manual = ?, capacity_max = ?, price_per_unit = ?, confirmation_text = ? WHERE id = ?'
        );
        $stmt->execute([
            $sortOrder, $fieldType, $label, $isRequired ? 1 : 0,
            $optionsSource, $optionsManual, $capacityMax, $pricePerUnit, $confirmationText, $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM news_form_fields WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByFormId(int $formId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM news_form_fields WHERE form_id = ?');
        $stmt->execute([$formId]);
    }

    /**
     * Persists a full reorder — PATCH /news/{id}/form/fields/reorder
     * (same "ids in new order" contract as public/assets/js/list-editor.js's
     * persistOrder()).
     *
     * @param int[] $orderedFieldIds
     */
    public function reorder(array $orderedFieldIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_form_fields SET sort_order = ? WHERE id = ?');
        foreach ($orderedFieldIds as $index => $fieldId) {
            $stmt->execute([$index, $fieldId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FormField
    {
        return new FormField(
            id: (int) $row['id'],
            formId: (int) $row['form_id'],
            sortOrder: (int) $row['sort_order'],
            fieldType: (string) $row['field_type'],
            label: $row['label'] !== null ? (string) $row['label'] : null,
            isRequired: (bool) $row['is_required'],
            optionsSource: $row['options_source'] !== null ? (string) $row['options_source'] : null,
            optionsManual: $row['options_manual'] !== null ? (string) $row['options_manual'] : null,
            capacityMax: $row['capacity_max'] !== null ? (int) $row['capacity_max'] : null,
            pricePerUnit: $row['price_per_unit'] !== null ? (float) $row['price_per_unit'] : null,
            confirmationText: $row['confirmation_text'] !== null ? (string) $row['confirmation_text'] : null,
            capacityUsed: (float) ($row['capacity_used'] ?? 0)
        );
    }

    /**
     * The running total of what this field's capacity caps, read at the
     * moment of a write.
     *
     * `FOR UPDATE` on MySQL/MariaDB, which is what makes two concurrent
     * submissions queue on the same row rather than both read the same
     * stale total and both fit. SQLite (tests, single-writer by design)
     * has no such syntax and needs none — the same reasoning
     * Repository\FormResponseRepository::sumFieldValues() already carried.
     */
    public function usedCapacity(int $fieldId, bool $lockForUpdate = false): float
    {
        $sql = 'SELECT capacity_used FROM news_form_fields WHERE id = ?';
        if ($lockForUpdate && $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$fieldId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0.0 : (float) $value;
    }

    /**
     * Moves the running total by $delta — positive for a new answer,
     * negative for the part an edit gives back. Written as a relative
     * UPDATE rather than a read-then-write so it is correct under the
     * row lock the caller already holds.
     */
    public function addUsedCapacity(int $fieldId, float $delta): void
    {
        if ($delta === 0.0) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE news_form_fields SET capacity_used = capacity_used + ? WHERE id = ?');
        $stmt->execute([$delta, $fieldId]);
    }

    /**
     * Recomputes the running total of every capped field from the
     * answers themselves — the one-off reprise for installations whose
     * responses were written before the column existed, and the repair
     * if the two ever drift.
     *
     * @return int the number of fields recomputed
     */
    public function recomputeUsedCapacities(FormResponseRepository $responses): int
    {
        $stmt = $this->pdo->query('SELECT id FROM news_form_fields WHERE capacity_max IS NOT NULL');
        \assert($stmt !== false);

        $update = $this->pdo->prepare('UPDATE news_form_fields SET capacity_used = ? WHERE id = ?');
        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $fieldId) {
            $update->execute([$responses->sumFieldValues((int) $fieldId), (int) $fieldId]);
            $count++;
        }

        return $count;
    }
}
