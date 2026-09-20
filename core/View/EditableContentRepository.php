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
     *     text_page_id: ?int,
     *     modified_at: string
     * }|null
     */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_key, content_type, content_value, module_id, text_page_id, modified_at '
                . 'FROM editable_contents WHERE content_key = ?'
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
    /**
     * The `text_pages` row this key's content belongs to, or null.
     *
     * **The authorization question, asked of the database rather than of
     * the key's spelling.** `content_key` is compared here with this
     * table's own `utf8mb4_unicode_ci`, which equates far more spellings
     * than they look — case, accents, trailing spaces, fullwidth forms,
     * every primary-ignorable character. Code that re-parses the key to
     * work out who owns it has to reproduce that equivalence exactly, and
     * anything it misses is a write landing on a row its caller was not
     * allowed to touch.
     *
     * Asking with the same `WHERE content_key = ?` the write itself uses
     * removes the question: whatever the collation considers this key to
     * be, the row that answers here is the row that will be written.
     */
    public function ownerPageIdForKey(string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT text_page_id FROM editable_contents WHERE content_key = ?');
        $stmt->execute([$key]);
        $owner = $stmt->fetchColumn();

        return $owner === false || $owner === null ? null : (int) $owner;
    }

    /**
     * Creates the empty row a free-text page's text will live in, owned
     * by that page.
     *
     * Called when the page is created, so there is **never a moment when
     * the key exists unclaimed** — the window in which somebody who may
     * not read the page could be the first to write its body, and in
     * which {@see ownerPageIdForKey()} would have nothing to answer.
     *
     * **`NULL`, not `''`.** The two look alike here and are not:
     * {@see EditableContentService::get()} falls back to the caller's
     * default with `??`, which answers to `NULL` and not to an empty
     * string. Claiming the key with `''` would therefore hand every
     * freshly created page an empty body instead of the « Cette page n'a
     * pas encore de contenu » its template passes — the blank screen that
     * default exists to prevent, on the one page guaranteed to hit it.
     */
    public function createOwnedBy(string $key, int $textPageId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO editable_contents (content_key, content_type, content_value, text_page_id, modified_at) '
            . "VALUES (?, 'rich_text', NULL, ?, ?)"
        );
        $stmt->execute([$key, $textPageId, self::now()]);
    }

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
