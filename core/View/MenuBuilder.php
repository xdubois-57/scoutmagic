<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Security\Role;

class MenuBuilder
{
    public const MENU_NOTRE_UNITE = 'notre_unite';
    public const MENU_ESPACE_ANIMES = 'espace_animes';
    public const MENU_ESPACE_CHEFS = 'espace_chefs';
    public const MENU_ESPACE_ADMIN = 'espace_admin';
    public const MENU_CONFIGURATION = 'configuration';

    // Sort groups for buildPages() below — see its own docblock. Lower
    // rank sorts earlier; a page's numeric `order` only breaks ties
    // *within* a sort group, never across sort groups.
    //
    // Deliberately named SORT_GROUP_*, not GROUP_*: this class carries a
    // second, entirely unrelated notion of "group" — MENU_GROUPS below,
    // the named columns the desktop mega-menu draws. A sort group decides
    // *where in the list* an entry lands; a menu group decides *which
    // titled column* it is drawn in. Nothing connects the two.
    public const SORT_GROUP_DYNAMIC = 'dynamic';
    public const SORT_GROUP_CORE = 'core';
    public const SORT_GROUP_MODULE = 'module';

    /** @var array<string, int> */
    private const SORT_GROUP_RANK = [
        self::SORT_GROUP_DYNAMIC => 0,
        self::SORT_GROUP_CORE => 1,
        self::SORT_GROUP_MODULE => 2,
    ];

    /**
     * The named columns each menu is split into, in the order they are
     * drawn — the single source of truth for both the group id vocabulary
     * and its French label, closed exactly the way `menu` itself is closed
     * (Core\Module\ModuleManifest::VALID_MENUS). A free string in
     * module.json would let two modules write "Gestion" and "gestion" and
     * produce two columns that mean the same thing.
     *
     * An empty list means "this menu is not grouped": build() then returns
     * one untitled group holding every page, so a consumer never needs an
     * "is this menu grouped?" branch. Grouping is opt-in per menu, never
     * forced.
     *
     * @var array<string, array<array{id: string, label: string}>>
     */
    public const MENU_GROUPS = [
        self::MENU_NOTRE_UNITE => [],
        self::MENU_ESPACE_ANIMES => [
            ['id' => 'mes_membres', 'label' => 'Mes membres'],
            ['id' => 'pages',       'label' => 'Pages'],
        ],
        self::MENU_ESPACE_CHEFS => [
            ['id' => 'ma_section',    'label' => 'Ma section'],
            ['id' => 'activites',     'label' => 'Activités'],
            ['id' => 'communication', 'label' => 'Communication'],
            ['id' => 'gestion',       'label' => 'Gestion'],
        ],
        self::MENU_ESPACE_ADMIN => [
            ['id' => 'membres_annee', 'label' => 'Membres & année'],
            ['id' => 'contenu',       'label' => 'Contenu du site'],
            ['id' => 'services',      'label' => 'Services'],
            ['id' => 'suivi',         'label' => 'Suivi'],
        ],
        self::MENU_CONFIGURATION => [
            ['id' => 'unite_donnees', 'label' => 'Unité & données'],
            ['id' => 'site',          'label' => 'Site'],
            ['id' => 'modules',       'label' => 'Réglages des modules'],
            ['id' => 'exploitation',  'label' => 'Exploitation'],
        ],
    ];

    /** @var array<array{id: string, label: string, icon: string, role_min: string}> */
    private const MENUS = [
        ['id' => self::MENU_NOTRE_UNITE,   'label' => 'Notre unité',       'icon' => 'bi-house',    'role_min' => 'public'],
        ['id' => self::MENU_ESPACE_ANIMES, 'label' => 'Espace membres',     'icon' => 'bi-people',   'role_min' => 'identified'],
        ['id' => self::MENU_ESPACE_CHEFS,  'label' => 'Espace animateurs',      'icon' => 'bi-star',     'role_min' => 'intendant'],
        ['id' => self::MENU_ESPACE_ADMIN,  'label' => "Espace chefs d'U",  'icon' => 'bi-gear',     'role_min' => 'admin'],
        ['id' => self::MENU_CONFIGURATION, 'label' => 'Configuration',     'icon' => 'bi-sliders',  'role_min' => 'superadmin'],
    ];

    /** @var array<string, array<array{label: string, url: string, roleMin: string, order: int, isDynamic: bool, subtitle: ?string, sortGroup: string, icon: ?string, avatarMemberId: ?int, menuGroup: ?string}>> */
    private array $pages = [];

    public function __construct(
        private Role $currentRole
    ) {
    }

    /**
     * The menu label for a given menu id — the single source of truth
     * consumed both by the nav (build() below) and by callers building a
     * route's breadcrumb `parents` (Core\Http\Router::addRoute()'s 6th
     * argument), so a label only ever needs changing here (see
     * partials/breadcrumb_bar.html.twig, which matches a `parents` string
     * against this same label at render time). Module-declared breadcrumbs
     * (module.json is plain data, no PHP to call this from) still hardcode
     * their own copy of the label text — that duplication is an accepted,
     * structural limit of a JSON manifest, not something this accessor
     * can reach into.
     */
    public static function labelFor(string $menuId): string
    {
        foreach (self::MENUS as $menu) {
            if ($menu['id'] === $menuId) {
                return $menu['label'];
            }
        }

        return '';
    }

    /**
     * Register a sub-page for a menu. $sortGroup controls sort placement
     * (see buildPages()) independently of $isDynamic, which only controls
     * rendering (nav.html.twig's avatar-circle-with-initials treatment for
     * per-member pages) — the two are deliberately separate: the "Espace
     * animés" empty-state placeholder (no linked members) is conceptually
     * part of the dynamic member-list slot for sorting purposes but must
     * never render with the per-member avatar styling, so it passes
     * sortGroup: SORT_GROUP_DYNAMIC with isDynamic: false.
     *
     * @param string|null $icon a Bootstrap Icons class ("bi-calendar3")
     *        shown in front of the entry, so every sub-page of a menu
     *        lines its label up with the per-member entries' avatars
     *        rather than starting further left than they do. Null falls
     *        back to a neutral one at render time (partials/nav.html.twig).
     * @param int|null $avatarMemberId the member a per-member entry
     *        stands for — what lets the mobile menu draw that member's
     *        own photo instead of two letters of their name
     *        (person_avatar(), Core\View\PersonAvatar). Only ever set on
     *        a dynamic entry.
     * @param string|null $menuGroup which named column of MENU_GROUPS this
     *        entry is drawn in — nothing to do with $sortGroup above, which
     *        only orders the flat list. Must be a group declared for
     *        $menuId; anything else is refused here rather than falling
     *        back silently, so a typo'd id cannot ship as a page that
     *        quietly moved column. Null on a grouped menu means the *last*
     *        declared group: a core page added later and forgotten lands in
     *        "Exploitation"/"Suivi", where the omission is visible, rather
     *        than inventing an unnamed column of its own.
     *
     * @throws \InvalidArgumentException when $menuGroup is not a group declared for $menuId.
     */
    public function addPage(
        string $menuId,
        string $label,
        string $url,
        string $roleMin = 'public',
        int $order = 100,
        bool $isDynamic = false,
        ?string $subtitle = null,
        string $sortGroup = self::SORT_GROUP_CORE,
        ?string $icon = null,
        ?int $avatarMemberId = null,
        ?string $menuGroup = null
    ): void {
        if ($menuGroup !== null && !in_array($menuGroup, self::groupIdsFor($menuId), true)) {
            throw new \InvalidArgumentException(
                "Menu group '{$menuGroup}' is not declared for menu '{$menuId}' (Core\\View\\MenuBuilder::MENU_GROUPS)"
            );
        }

        $this->pages[$menuId][] = [
            'label' => $label,
            'url' => $url,
            'roleMin' => $roleMin,
            'order' => $order,
            'isDynamic' => $isDynamic,
            'subtitle' => $subtitle,
            'sortGroup' => $sortGroup,
            'icon' => $icon,
            'avatarMemberId' => $avatarMemberId,
            'menuGroup' => $menuGroup,
        ];
    }

    /**
     * The group ids declared for a menu, in declaration order — empty for
     * an ungrouped menu. Public because module.json's `menu_group` is
     * validated against exactly this list at manifest load
     * (Core\Module\ModuleManifest::validateRoute()), and a second copy of
     * the vocabulary over there is precisely what MENU_GROUPS exists to
     * prevent.
     *
     * @return array<string>
     */
    public static function groupIdsFor(string $menuId): array
    {
        return array_column(self::MENU_GROUPS[$menuId] ?? [], 'id');
    }

    /**
     * Build the complete menu structure filtered for the current role.
     *
     * `pages` is the flat, sorted, role-filtered list every consumer has
     * always read (the mobile offcanvas still does). `groups` is those very
     * same pages split into the named columns of MENU_GROUPS, for the
     * desktop mega-menu panel — one list seen two ways, never two lists
     * that could disagree.
     *
     * @return array<array{id: string, label: string, icon: string, pages: array<array{label: string, url: string, isDynamic: bool, subtitle: ?string, icon: ?string, avatarMemberId: ?int}>, groups: array<array{id: ?string, label: ?string, pages: array<array{label: string, url: string, isDynamic: bool, subtitle: ?string, icon: ?string, avatarMemberId: ?int}>}>}>
     */
    public function build(): array
    {
        $result = [];

        foreach (self::MENUS as $menu) {
            $menuRole = Role::fromString($menu['role_min']);

            if (!$this->currentRole->hasAccess($menuRole)) {
                continue;
            }

            $entries = $this->visibleEntries($menu['id']);

            if (count($entries) === 0) {
                continue;
            }

            $result[] = [
                'id' => $menu['id'],
                'label' => $menu['label'],
                'icon' => $menu['icon'],
                'pages' => array_map(static fn(array $entry) => self::toPage($entry), $entries),
                'groups' => self::buildGroups($menu['id'], $entries),
            ];
        }

        return $result;
    }

    /**
     * Filter a menu's registered entries by role and sort them. Sorted by
     * SORT_GROUP_RANK first (dynamic entries, e.g. per-member pages,
     * always before core static pages, always before module-provided
     * pages), then by `order` within each sort group — a module's
     * `menu_order` (however low) can no longer place it ahead of core
     * pages or dynamic entries, only ahead of other modules. usort() has
     * been stable since PHP 8.0, so two entries with the same sort group
     * and order keep their registration order.
     *
     * @return array<array{label: string, url: string, roleMin: string, order: int, isDynamic: bool, subtitle: ?string, sortGroup: string, icon: ?string, avatarMemberId: ?int, menuGroup: ?string}>
     */
    private function visibleEntries(string $menuId): array
    {
        if (!isset($this->pages[$menuId])) {
            return [];
        }

        $entries = $this->pages[$menuId];

        usort(
            $entries,
            fn(array $a, array $b) => (self::SORT_GROUP_RANK[$a['sortGroup']] <=> self::SORT_GROUP_RANK[$b['sortGroup']])
                ?: ($a['order'] <=> $b['order'])
        );

        return array_values(array_filter(
            $entries,
            fn(array $entry) => $this->currentRole->hasAccess(Role::fromString($entry['roleMin']))
        ));
    }

    /**
     * Split already-filtered, already-sorted entries into the menu's
     * declared columns.
     *
     * Column order is MENU_GROUPS' declaration order, never `order` /
     * `menu_order` — those still sort *within* a column, which is what
     * lets a module page and a core page share one (Finances belongs under
     * "Gestion" whichever of the two registered it).
     *
     * Role filtering has already happened by the time this runs, so a
     * column whose every page was filtered out is simply absent rather
     * than present and empty: an intendant must never be shown a titled
     * column with nothing under it.
     *
     * @param array<array{label: string, url: string, roleMin: string, order: int, isDynamic: bool, subtitle: ?string, sortGroup: string, icon: ?string, avatarMemberId: ?int, menuGroup: ?string}> $entries
     * @return array<array{id: ?string, label: ?string, pages: array<array{label: string, url: string, isDynamic: bool, subtitle: ?string, icon: ?string, avatarMemberId: ?int}>}>
     */
    private static function buildGroups(string $menuId, array $entries): array
    {
        $declared = self::MENU_GROUPS[$menuId] ?? [];

        // Ungrouped menu: one untitled column holding everything, so a
        // template consumes a single shape and never branches on "is this
        // menu grouped?".
        if ($declared === []) {
            return [[
                'id' => null,
                'label' => null,
                'pages' => array_map(static fn(array $entry) => self::toPage($entry), $entries),
            ]];
        }

        $fallbackId = $declared[count($declared) - 1]['id'];

        /** @var array<string, array<array{label: string, url: string, isDynamic: bool, subtitle: ?string, icon: ?string, avatarMemberId: ?int}>> $byGroup */
        $byGroup = [];
        foreach ($entries as $entry) {
            $byGroup[$entry['menuGroup'] ?? $fallbackId][] = self::toPage($entry);
        }

        $groups = [];
        foreach ($declared as $group) {
            if (!isset($byGroup[$group['id']])) {
                continue;
            }

            $groups[] = [
                'id' => $group['id'],
                'label' => $group['label'],
                'pages' => $byGroup[$group['id']],
            ];
        }

        return $groups;
    }

    /**
     * The public shape of one entry — what both `pages` and a group's own
     * `pages` carry, so a template reads the same page whichever way it
     * reached it.
     *
     * @param array{label: string, url: string, roleMin: string, order: int, isDynamic: bool, subtitle: ?string, sortGroup: string, icon: ?string, avatarMemberId: ?int, menuGroup: ?string} $entry
     * @return array{label: string, url: string, isDynamic: bool, subtitle: ?string, icon: ?string, avatarMemberId: ?int}
     */
    private static function toPage(array $entry): array
    {
        return [
            'label' => $entry['label'],
            'url' => $entry['url'],
            'isDynamic' => $entry['isDynamic'],
            'subtitle' => $entry['subtitle'],
            'icon' => $entry['icon'] ?? null,
            'avatarMemberId' => $entry['avatarMemberId'] ?? null,
        ];
    }
}
