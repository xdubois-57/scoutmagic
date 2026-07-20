<?php

declare(strict_types=1);

namespace Core\View;

class EditableContentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{content_key: string, content_type: string, content_value: ?string, module_id: ?string, modified_at: string}|null
     */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_key, content_type, content_value, module_id, modified_at FROM editable_contents WHERE content_key = ?'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Removes a content key entirely — for callers whose key is tied to a
     * deletable entity (e.g. one list item among several dynamically
     * created ones), unlike the fixed, page-anchored keys used by
     * editable()/editable_image() which are never deleted.
     */
    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM editable_contents WHERE content_key = ?');
        $stmt->execute([$key]);
    }

    public function upsert(string $key, string $type, string $value, ?string $moduleId, int $modifiedBy): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByKey($key);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE editable_contents SET content_type = ?, content_value = ?, modified_at = ?, modified_by = ? WHERE content_key = ?'
            );
            $stmt->execute([$type, $value, $now, $modifiedBy, $key]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO editable_contents (content_key, content_type, content_value, module_id, modified_at, modified_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$key, $type, $value, $moduleId, $now, $modifiedBy]);
        }
    }
}
