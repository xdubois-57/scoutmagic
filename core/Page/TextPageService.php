<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\Service\TextNormalizerService;
use Core\View\EditableContentRepository;
use Core\View\MenuBuilder;

/**
 * Free-text pages: the rules, in one place (issue #368).
 *
 * Three of them are worth naming here, because each exists to stop a
 * specific way this feature could break the site:
 *
 * 1. **The section and the column are validated together, server side.**
 *    {@see MenuBuilder::addPage()} throws when a column is not declared
 *    for the menu it is given, and that call happens while the menu of
 *    EVERY page of the site is being built. A hand-edited value reaching
 *    the table would therefore not break this page — it would break the
 *    navigation everywhere, on the next request, for everyone. The form
 *    hides the column picker on a menu that has no columns; that is a
 *    convenience, and {@see assertMenuPlacement()} is the rule.
 *
 * 2. **The slug is derived once and frozen.** The address is shared the
 *    moment a page is published, so correcting a typo in a title must
 *    not break a link somebody already sent.
 *
 * 3. **Deleting a page deletes its text.** A page whose row is gone but
 *    whose rich text stays in `editable_contents` is data nobody can
 *    find, read or erase — which is the definition of the thing an RGPD
 *    notice cannot honestly describe.
 *
 * The text itself is never handled here beyond that deletion: it is
 * written through {@see EditableContentService} by the ordinary
 * configuration-mode editor, exactly as on the home page.
 */
class TextPageService
{
    /**
     * Matches `text_pages.slug` (VARCHAR(160)) with room to spare for the
     * numeric suffix a collision adds.
     */
    private const SLUG_MAX_LENGTH = 150;

    /**
     * @param EditableContentRepository|null $editableContent used for one
     *        thing only: creating the row a page's text will live in, at
     *        the moment the page is created. Deleting it is the database's
     *        job — `editable_contents.text_page_id` cascades.
     */
    public function __construct(
        private TextPageRepository $repository,
        private ?EditableContentRepository $editableContent = null,
    ) {
    }

    /** @return TextPage[] */
    public function listAll(): array
    {
        return $this->repository->findAll();
    }

    /** @return TextPage[] */
    public function listActive(): array
    {
        return $this->repository->findActive();
    }

    public function findActiveBySlug(string $slug): ?TextPage
    {
        return $this->repository->findActiveBySlug($slug);
    }

    public function findById(int $id): ?TextPage
    {
        return $this->repository->findById($id);
    }

    /**
     * Creates a page and returns it, slug and id included.
     *
     * @throws TextPageException when a field is empty or the section and
     *         column do not go together
     */
    public function create(string $menuLabel, string $title, string $menuId, ?string $menuGroup): TextPage
    {
        $menuLabel = trim($menuLabel);
        $title = trim($title);
        $menuGroup = $this->normalizeGroup($menuGroup);

        $this->assertNames($menuLabel, $title);
        $this->assertMenuPlacement($menuId, $menuGroup);

        $id = $this->repository->insert(
            $this->uniqueSlug($title),
            $menuLabel,
            $title,
            $menuId,
            $menuGroup,
            $this->repository->maxSortOrder($menuId) + 1,
        );

        $page = $this->repository->findById($id);
        if ($page === null) {
            // The row was written a statement ago; if it cannot be read
            // back, something is wrong with the connection rather than
            // with the input, and the caller must not carry on with a
            // half-made page.
            throw new TextPageException("La page n'a pas pu être relue après sa création.");
        }

        // **The text's row is claimed with the page, empty, owned.**
        //
        // Not an optimisation: it is what closes the window in which the
        // content key exists but belongs to nobody. Authorization for a
        // write is read off `editable_contents.text_page_id`
        // (ARCHITECTURE.md §8.116), so a key with no owner would have
        // nothing to answer for it — and whoever wrote first would decide
        // what a page they may not even read says.
        //
        // **And a page whose body is unclaimed must not ship at all.** It
        // would have a route, a menu entry and a text governed by the
        // write endpoint's own floor rather than by its section's: live,
        // visible, and writable by someone who cannot read it. So a claim
        // that fails takes the page with it rather than leaving that
        // behind — the page row is already committed by the time we get
        // here, so undoing it is the only way back.
        try {
            $this->editableContent?->claimForPage($page->contentKey(), $page->id);
        } catch (\Throwable $e) {
            $this->repository->delete($page->id);

            throw new TextPageException("La page n'a pas pu être créée. Réessayez.", 0, $e);
        }

        return $page;
    }

    /**
     * @throws TextPageException when a field is empty, the section and
     *         column do not go together, or the page no longer exists
     */
    public function update(int $id, string $menuLabel, string $title, string $menuId, ?string $menuGroup): TextPage
    {
        $menuLabel = trim($menuLabel);
        $title = trim($title);
        $menuGroup = $this->normalizeGroup($menuGroup);

        $this->assertNames($menuLabel, $title);
        $this->assertMenuPlacement($menuId, $menuGroup);

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new TextPageException("Cette page n'existe plus.");
        }

        // The slug is NOT recomputed from the new title — see this
        // class's docblock, rule 2.
        $this->repository->update($id, $menuLabel, $title, $menuId, $menuGroup);

        return $this->repository->findById($id) ?? $existing;
    }

    /**
     * @throws TextPageException when the page no longer exists
     *
     * The existence check is not ceremony. `UPDATE … WHERE id = ?` on a
     * row that is not there succeeds silently, so without it the screen
     * would answer « done » and the journal would record an activation
     * for a page that never existed — a fabricated line in the very
     * audit trail this feature adds.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->assertExists($id);
        $this->repository->setActive($id, $active);
    }

    /**
     * Removes the page. Its text goes with it.
     *
     * **Nothing here deletes the content row**, and that is the point:
     * `editable_contents.text_page_id` is a foreign key with
     * `ON DELETE CASCADE`, so the database removes it in the same
     * statement. A second delete issued from here could fail on its own
     * — a key spelled differently, a connection lost between the two —
     * and leave rich text nobody can name, read or erase behind. The
     * constraint cannot.
     */
    public function delete(int $id): void
    {
        $this->assertExists($id);
        $this->repository->delete($id);
    }

    /**
     * **Ranks are per section, so they are computed per section.**
     *
     * `sort_order` is only ever compared with the orders of pages in the
     * same menu — `TextPageMenuProvider` orders within each `menu_id`,
     * and `maxSortOrder()` is scoped to one. A caller handing this a list
     * that spans several sections used to have its positions written as
     * one global 0..n-1 run, which no reader ever reads that way. The
     * order a section's pages appear in the submitted list is therefore
     * the order they get, and a section absent from that list is left
     * alone.
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $rankPerMenu = [];
        $positions = [];

        foreach ($orderedIds as $id) {
            $page = $this->repository->findById((int) $id);
            if ($page === null) {
                continue;
            }

            $rank = $rankPerMenu[$page->menuId] ?? 0;
            $positions[$page->id] = $rank;
            $rankPerMenu[$page->menuId] = $rank + 1;
        }

        $this->repository->reorder($positions);
    }

    /**
     * @throws TextPageException
     */
    private function assertExists(int $id): void
    {
        if ($this->repository->findById($id) === null) {
            throw new TextPageException("Cette page n'existe plus.");
        }
    }

    /**
     * The section + column pair, checked before anything is written.
     *
     * @throws TextPageException
     */
    public function assertMenuPlacement(string $menuId, ?string $menuGroup): void
    {
        if (!in_array($menuId, MenuBuilder::menuIds(), true)) {
            throw new TextPageException('Cette section de menu n\'existe pas.');
        }

        $declared = MenuBuilder::groupIdsFor($menuId);

        if ($declared === []) {
            // « Notre unité » has no columns at all. A value here would
            // be passed straight to addPage(), which would throw while
            // building the navigation of every page of the site.
            if ($menuGroup !== null) {
                throw new TextPageException('Cette section de menu n\'a pas de colonnes.');
            }

            return;
        }

        if ($menuGroup === null) {
            throw new TextPageException('Choisissez une colonne pour cette section.');
        }

        if (!in_array($menuGroup, $declared, true)) {
            throw new TextPageException('Cette colonne n\'existe pas dans cette section.');
        }
    }

    /**
     * The column a form should preselect for a section — the obvious home
     * for a page somebody wrote themselves, named per menu.
     *
     * **One entry per menu, not a list tried in turn.** It used to be a
     * shared preference list, `['pages', 'contenu', 'site']`, scanned for
     * the first id the menu declared. That reads as if each menu had a
     * chosen column, and it silently stopped being true the day two of
     * those three ids were renamed: the loop matched nothing and fell
     * through to the first declared column, so a new page in Espace
     * membres was preselected into « Mes membres » — pages about the
     * unit, filed under what concerns me personally — and one in Espace
     * chefs d'U into « Suivi ». Nothing failed, because the fallback is
     * always a valid column; only the wrong one.
     *
     * Keyed by menu, the intent is written down per menu and
     * TextPageServiceTest asserts the exact column rather than merely a
     * declared one. A menu missing from this map is a deliberate absence
     * — `notre_unite` has no columns at all — and anything else is
     * caught by the test that every key here is a column its menu really
     * declares.
     *
     * @var array<string, string>
     */
    private const PREFERRED_GROUP = [
        MenuBuilder::MENU_ESPACE_ANIMES => 'unite',
        MenuBuilder::MENU_ESPACE_CHEFS => 'communication',
        MenuBuilder::MENU_ESPACE_ADMIN => 'communication',
        MenuBuilder::MENU_CONFIGURATION => 'site',
    ];

    public function defaultGroupFor(string $menuId): ?string
    {
        $declared = MenuBuilder::groupIdsFor($menuId);
        if ($declared === []) {
            return null;
        }

        $preferred = self::PREFERRED_GROUP[$menuId] ?? null;
        if ($preferred !== null && in_array($preferred, $declared, true)) {
            return $preferred;
        }

        // A menu nobody named a column for, or one whose named column was
        // removed without this map following. Still a valid column, so the
        // form opens on something rather than on an empty picker — but the
        // test above is what keeps this branch unreachable in practice.
        return $declared[0];
    }

    /**
     * @throws TextPageException
     */
    private function assertNames(string $menuLabel, string $title): void
    {
        if ($menuLabel === '') {
            throw new TextPageException('Le nom dans le menu est obligatoire.');
        }

        if ($title === '') {
            throw new TextPageException('Le titre de la page est obligatoire.');
        }
    }

    private function normalizeGroup(?string $menuGroup): ?string
    {
        if ($menuGroup === null) {
            return null;
        }

        $menuGroup = trim($menuGroup);

        return $menuGroup === '' ? null : $menuGroup;
    }

    /**
     * The slug for a title, with a numeric suffix when it is taken.
     *
     * A title made entirely of characters the slug cannot carry — a
     * heading in an alphabet this transliteration does not cover, or
     * nothing but punctuation — would otherwise produce an empty slug
     * and a page reachable at `/pages/`. `page` is the fallback, and
     * the suffix loop makes it unique like any other.
     */
    public function uniqueSlug(string $title): string
    {
        $base = self::slugify($title);
        if ($base === '') {
            $base = 'page';
        }

        if (!$this->repository->slugExists($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = $base . '-' . $suffix;
            if (!$this->repository->slugExists($candidate)) {
                return $candidate;
            }
        }

        // A thousand pages sharing one title is not a case worth a
        // cleverer scheme; it is a case worth saying out loud.
        throw new TextPageException("Trop de pages portent déjà ce titre.");
    }

    /**
     * Lowercase, accent-free, hyphen-separated.
     *
     * Built on {@see TextNormalizerService::fold()} rather than on
     * `iconv('ASCII//TRANSLIT')`, and the difference is not cosmetic:
     * iconv's output depends on the C library the host ships, so « Bulle
     * arrêtée » would slugify to `bulle-arretee` on glibc and to
     * `bulle-arr-etee` on the libiconv of musl or macOS. An address is
     * frozen at creation and shared immediately — a slug that depends on
     * which machine created it is a link that breaks when the unit
     * changes host. `Tests\Architecture\AccentFoldingTest` refuses the
     * iconv spelling repository-wide for exactly this reason.
     *
     * `fold()` already lowercases, applies an explicit accent map on
     * every platform, and collapses every run of non-alphanumerics to a
     * single space; all that is left here is joining on hyphens and
     * bounding the length.
     */
    public static function slugify(string $title): string
    {
        $slug = str_replace(' ', '-', TextNormalizerService::fold($title));

        if (strlen($slug) > self::SLUG_MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::SLUG_MAX_LENGTH), '-');
        }

        return $slug;
    }
}
