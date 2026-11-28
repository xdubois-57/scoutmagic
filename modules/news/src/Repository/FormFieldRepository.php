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
                . 'options_manual, capacity_max, capacity_peak, price_per_unit, confirmation_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $formId, $sortOrder, $fieldType, $label, $isRequired ? 1 : 0,
            $optionsSource, $optionsManual, $capacityMax, $capacityMax, $pricePerUnit, $confirmationText,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * In-place update (Service\FormService's save reconciles the incoming
     * field list against existing ids rather than deleting and
     * re-inserting everything, so a field that already has responses
     * keeps its id — and their news_form_response_values rows, which
     * cascade-delete if the field itself is ever actually removed).
     *
     * `capacity_peak` only ever rises: the highest cap the field has had,
     * the one FormField::storedQuantity() bounds every answer by (#1546
     * review). A row written before the column starts from its current
     * cap — what it had before cannot be known.
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
                . '?, options_manual = ?, '
                . 'capacity_peak = CASE'
                // `? + 0`: a bound parameter arrives as text, which SQLite
                // would compare as greater than any number.
                . ' WHEN COALESCE(capacity_peak, capacity_max, 0) >= COALESCE(? + 0, 0) THEN COALESCE(capacity_peak, capacity_max)'
                . ' ELSE ? END, '
                . 'capacity_max = ?, price_per_unit = ?, confirmation_text = ? WHERE id = ?'
        );
        $stmt->execute([
            $sortOrder, $fieldType, $label, $isRequired ? 1 : 0,
            $optionsSource, $optionsManual, $capacityMax, $capacityMax,
            $capacityMax, $pricePerUnit, $confirmationText, $id,
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
            capacityUsed: (float) ($row['capacity_used'] ?? 0),
            capacityPeak: isset($row['capacity_peak']) ? (int) $row['capacity_peak'] : null
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
     * Locks the rows of $fieldIds, in ascending id order, and returns each
     * one's running total — what a submission or an edit does BEFORE it
     * writes its answers (#1546 review). Inserting an answer takes a shared
     * lock on its field's row through the foreign key; two submissions
     * holding that shared lock and then both asking for the exclusive one
     * addUsedCapacity() needs would deadlock, and one of them would be
     * refused. Taking the exclusive lock first makes them queue instead,
     * and the same order everywhere keeps two multi-field writes from
     * crossing. `FOR UPDATE` on MySQL/MariaDB only, as usedCapacity().
     *
     * @param list<int> $fieldIds
     * @return array<int, float> field id => capacity_used
     */
    public function lockUsedCapacities(array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }
        $fieldIds = array_values(array_unique($fieldIds));
        sort($fieldIds);

        $sql = 'SELECT id, capacity_used FROM news_form_fields WHERE id IN ('
            . implode(',', array_fill(0, count($fieldIds), '?')) . ') ORDER BY id';
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($fieldIds);

        $used = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $used[(int) $row['id']] = (float) $row['capacity_used'];
        }

        return $used;
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
     * $work inside one transaction — the caller's if it already has one,
     * else one of its own, committed when $work returns and rolled back if
     * it throws. What lets the editor write a field's new cap and its
     * recomputed total as one change (#1546 review).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Recomputes the running total of ONE field from its answers, and
     * returns it — what the editor does each time a field's cap is set or
     * changed (issue #1465): the counter is only kept while a cap exists,
     * so a cap added after the first answers would otherwise start from
     * zero, and a cap removed then restored would miss every answer given
     * in between.
     */
    public function recomputeUsedCapacity(int $fieldId, FormResponseRepository $responses): float
    {
        return $this->recount($fieldId, $responses);
    }

    /** The recount itself, under the row lock. */
    private function recount(int $fieldId, FormResponseRepository $responses): float
    {
        // Under the row lock a submission's increment takes (#1546
        // review): one in flight either commits first — its answer is then
        // in the sum — or waits for this write and adds its share on top.
        // Without it, an answer read as absent here and added on the
        // uncapped path would be lost for good.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->usedCapacity($fieldId, lockForUpdate: true);
            // The field itself, so each answer counts what
            // FormField::storedQuantity() says it does (issue #1525).
            $field = $this->findById($fieldId);
            $used = $field === null ? 0.0 : $responses->sumFieldValues($field);
            $stmt = $this->pdo->prepare('UPDATE news_form_fields SET capacity_used = ? WHERE id = ?');
            $stmt->execute([$used, $fieldId]);
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $used;
    }

    /**
     * Recomputes the running total of every capped field from the
     * answers themselves — the one-off reprise for installations whose
     * responses were written before the column existed, and the repair
     * if the two ever drift.
     *
     * Each field under its own row lock, as the editor's recount (#1546
     * review): a submission committing between an unlocked read and the
     * write would otherwise have its share erased, and the marker set
     * after this one run would keep the undercount for good.
     *
     * @return int the number of fields recomputed
     */
    public function recomputeUsedCapacities(FormResponseRepository $responses): int
    {
        $stmt = $this->pdo->query('SELECT id FROM news_form_fields WHERE capacity_max IS NOT NULL');
        \assert($stmt !== false);

        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $fieldId) {
            $this->recount((int) $fieldId, $responses);
            $count++;
        }

        return $count;
    }
}
