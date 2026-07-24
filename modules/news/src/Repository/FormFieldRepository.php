<?php

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
            'INSERT INTO news_form_fields (form_id, sort_order, field_type, label, is_required, options_source, options_manual, capacity_max, price_per_unit, confirmation_text)
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
            'UPDATE news_form_fields SET sort_order = ?, field_type = ?, label = ?, is_required = ?, options_source = ?, options_manual = ?, capacity_max = ?, price_per_unit = ?, confirmation_text = ? WHERE id = ?'
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
            confirmationText: $row['confirmation_text'] !== null ? (string) $row['confirmation_text'] : null
        );
    }
}
