<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

/**
 * The only layer here that touches PDO (ARCHITECTURE.md §2).
 *
 * Every read a request makes on the hot path goes through
 * {@see findActive()}, which is **one query for the whole site** — not
 * one per menu and not one per page. It runs on every single request
 * that builds a menu or registers a route, so its cost is the cost of
 * the feature existing at all.
 */
class TextPageRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every active page, menu by menu, in display order.
     *
     * The one query the boot path runs. Ordered here rather than in PHP
     * so the index does the work and the caller — route registration and
     * menu contribution alike — gets the same order without sorting
     * twice.
     *
     * @return TextPage[]
     */
    public function findActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM text_pages WHERE is_active = 1 ORDER BY menu_id ASC, sort_order ASC, id ASC'
        );

        return array_map(
            [TextPage::class, 'fromRow'],
            $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []
        );
    }

    /**
     * Every page, active or not — the configuration screen's list.
     *
     * @return TextPage[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM text_pages ORDER BY menu_id ASC, sort_order ASC, id ASC'
        );

        return array_map(
            [TextPage::class, 'fromRow'],
            $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []
        );
    }

    public function findById(int $id): ?TextPage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM text_pages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : TextPage::fromRow($row);
    }

    /**
     * One active page by its slug — what the rendered page resolves.
     *
     * `is_active = 1` is part of the query rather than a check the
     * caller makes afterwards: a page that has been switched off has no
     * route either, and both halves of "it does not exist" must say the
     * same thing.
     */
    public function findActiveBySlug(string $slug): ?TextPage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM text_pages WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : TextPage::fromRow($row);
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM text_pages WHERE slug = ?');
        $stmt->execute([$slug]);

        return $stmt->fetchColumn() !== false;
    }

    /** The id of the row just written, so the caller can build its content key. */
    public function insert(
        string $slug,
        string $menuLabel,
        string $title,
        string $menuId,
        ?string $menuGroup,
        int $sortOrder,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO text_pages (slug, menu_label, title, menu_id, menu_group, sort_order, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$slug, $menuLabel, $title, $menuId, $menuGroup, $sortOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Everything a page may change — the slug is deliberately absent.
     *
     * Not an oversight: the address is frozen at creation so links that
     * have been shared keep working (see {@see TextPage::path()}). A
     * repository method that could write it would be the first step back
     * towards a renameable one.
     */
    public function update(
        int $id,
        string $menuLabel,
        string $title,
        string $menuId,
        ?string $menuGroup,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE text_pages SET menu_label = ?, title = ?, menu_id = ?, menu_group = ?, '
            . 'updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$menuLabel, $title, $menuId, $menuGroup, self::now(), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE text_pages SET is_active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, self::now(), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM text_pages WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Writes the given ids' order, in the order they arrive.
     *
     * One statement per id inside a transaction rather than a single
     * CASE expression: the list is a handful of rows written by one
     * person on one screen, and a readable loop that either lands whole
     * or not at all is worth more than a clever statement here. A
     * partially applied ordering is its own defect (SECURITY.md §3 says
     * as much about the section-documents reorder).
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE text_pages SET sort_order = ?, updated_at = ? WHERE id = ?');
        $now = self::now();

        $this->pdo->beginTransaction();
        try {
            foreach (array_values($orderedIds) as $position => $id) {
                $stmt->execute([$position, $now, $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** The highest order currently used in a menu, so a new page lands last. */
    public function maxSortOrder(string $menuId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(sort_order) FROM text_pages WHERE menu_id = ?');
        $stmt->execute([$menuId]);
        $max = $stmt->fetchColumn();

        return $max === false || $max === null ? -1 : (int) $max;
    }

    /**
     * The application clock, naive local datetime — the same spelling
     * `Core\View\EditableContentRepository::now()` uses, so a page and
     * its text never disagree about when they were touched.
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
