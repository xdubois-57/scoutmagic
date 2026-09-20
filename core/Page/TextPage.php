<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\View\MenuBuilder;

/**
 * One free-text page a superadmin has added to a menu (issue #368).
 *
 * A page is deliberately thin: a name in a menu, a title, a place, and
 * whether it is on. **Its text is not in here** — that lives in
 * `editable_contents` under {@see contentKey()} and is written through
 * the site's ordinary configuration-mode editing, the same mechanism the
 * home page's introduction uses. Carrying the text on this object would
 * have meant a second way of storing rich text and a second sanitizer
 * call site, for a feature whose whole point is that the existing one
 * already does the job.
 *
 * Immutable, like every other row object here: the service rebuilds one
 * rather than mutating it, so nothing can hold a page whose slug has
 * silently changed underneath it.
 */
final class TextPage
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $menuLabel,
        public readonly string $title,
        public readonly string $menuId,
        public readonly ?string $menuGroup,
        public readonly int $sortOrder,
        public readonly bool $isActive,
    ) {
    }

    /**
     * The path this page answers on.
     *
     * The `/pages/` prefix is not decoration: it is what guarantees that
     * adding a page can never shadow a route the site already has, nor a
     * route a future version introduces. A page at `/asbl` would win
     * today and lose the day `/asbl` means something to the application.
     */
    public function path(): string
    {
        return '/pages/' . $this->slug;
    }

    /**
     * The `editable_contents` key holding this page's text.
     *
     * **Keyed on the id, never on the slug.** The slug is frozen at
     * creation precisely so shared links keep working, but nothing stops
     * a future version from offering to change it — and on the day it
     * does, a slug-keyed text would be orphaned in the table with nobody
     * able to find it or delete it. The id is the one identifier that
     * cannot change.
     */
    public function contentKey(): string
    {
        return 'page_content_' . $this->id;
    }

    /**
     * The role floor this page's route is registered with.
     *
     * Derived, never stored: the menu already carries the floor, and the
     * section a page is filed in IS the answer to who may read it. A
     * column of its own would be a second truth and the one that drifts
     * away from the menu it is supposed to agree with.
     */
    public function roleMin(): string
    {
        return MenuBuilder::roleMinFor($this->menuId);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $group = $row['menu_group'] ?? null;

        return new self(
            id: (int) $row['id'],
            slug: (string) $row['slug'],
            menuLabel: (string) $row['menu_label'],
            title: (string) $row['title'],
            menuId: (string) $row['menu_id'],
            menuGroup: ($group === null || $group === '') ? null : (string) $group,
            sortOrder: (int) $row['sort_order'],
            isActive: (bool) $row['is_active'],
        );
    }
}
