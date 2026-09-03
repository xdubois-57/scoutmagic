<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

class EditableContentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{
     *     content_key: string,
     *     content_type: string,
     *     content_value: ?string,
     *     module_id: ?string,
     *     modified_at: string
     * }|null
     */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_key, content_type, content_value, module_id, modified_at FROM editable_contents WHERE '
                . 'content_key = ?'
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
        $existing = $this->findByKey($key);

        if ($existing !== null) {
            // modified_at must reflect the last time the content actually
            // changed, not the last time this method was called (callers
            // may re-save unchanged content, e.g. when only switching mode).
            if ($existing['content_type'] === $type && $existing['content_value'] === $value) {
                return;
            }

            $now = self::now();
            $stmt = $this->pdo->prepare(
                'UPDATE editable_contents SET content_type = ?, content_value = ?, modified_at = ?, modified_by = ? '
                    . 'WHERE content_key = ?'
            );
            $stmt->execute([$type, $value, $now, $modifiedBy, $key]);
        } else {
            $now = self::now();
            $stmt = $this->pdo->prepare(
                'INSERT INTO editable_contents (content_key, content_type, content_value, module_id, modified_at, '
                    . 'modified_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$key, $type, $value, $moduleId, $now, $modifiedBy]);
        }
    }

    /**
     * modified_at is on the application clock like every other naive
     * DATETIME in this database (Core\Config\AppClock), not UTC: the RGPD
     * page renders it straight to a Belgian reader, and a value written on
     * a different clock than the one it is read back on is the whole class
     * of bug that clock exists to close.
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
