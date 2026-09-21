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
     * The prefix every free-text page's address begins with.
     *
     * A constant rather than a literal repeated twice, because one of the
     * two readers is a test: these routes are born from database rows, so
     * they never appear in `public/index.php`, and
     * `Tests\Core\Help\HelpInvariantsTest` — which checks that a help
     * topic's declared `paths` are served by a real GET route — has to be
     * told this family exists. Told from here, it cannot be told a shape
     * the application stopped using.
     */
    public const PATH_PREFIX = '/pages/';

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
        return self::PATH_PREFIX . $this->slug;
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

    /**
     * Columns that were renamed by the menu reorganisation, old id to new.
     *
     * **Why this is not a migration.** `schema/` describes a desired
     * STRUCTURE — core.sql is compared against the live schema, drops.sql
     * only drops — so an `UPDATE text_pages SET menu_group = …` has
     * nowhere to live in this repository, and inventing a data-migration
     * mechanism for four strings would be the larger change.
     *
     * Reading it here instead is not a workaround, it is the safer half
     * of the trade. `fromRow()` is the single point every page is
     * hydrated through, so nothing downstream ever sees an old id: in
     * particular TextPageMenuProvider::placementIsStillValid() never sees
     * an orphan, and an orphan is silent — a page whose column no longer
     * exists simply stops being placed where its author put it, with
     * nothing said to anybody. It is also idempotent and order-free,
     * where a one-shot UPDATE is neither.
     *
     * A row is only ever WRITTEN with a current id: the configuration
     * form validates against MenuBuilder::MENU_GROUPS. This map is
     * therefore read-only compatibility for rows written before the
     * reorganisation, and it stays until nothing can still hold one.
     *
     * @var array<string, string>
     */
    private const RENAMED_GROUPS = [
        'pages' => 'unite',
        'gestion' => 'argent',
        'contenu' => 'communication',
        'exploitation' => 'etat_du_site',
    ];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $group = $row['menu_group'] ?? null;

        if (is_string($group) && isset(self::RENAMED_GROUPS[$group])) {
            $group = self::RENAMED_GROUPS[$group];
        }

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
