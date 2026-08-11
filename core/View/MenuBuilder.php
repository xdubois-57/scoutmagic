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
    // *within* a group, never across groups.
    public const GROUP_DYNAMIC = 'dynamic';
    public const GROUP_CORE = 'core';
    public const GROUP_MODULE = 'module';

    /** @var array<string, int> */
    private const GROUP_RANK = [
        self::GROUP_DYNAMIC => 0,
        self::GROUP_CORE => 1,
        self::GROUP_MODULE => 2,
    ];

    /** @var array<array{id: string, label: string, icon: string, role_min: string}> */
    private const MENUS = [
        ['id' => self::MENU_NOTRE_UNITE,   'label' => 'Notre unité',       'icon' => 'bi-house',    'role_min' => 'public'],
        ['id' => self::MENU_ESPACE_ANIMES, 'label' => 'Espace animés',     'icon' => 'bi-people',   'role_min' => 'identified'],
        ['id' => self::MENU_ESPACE_CHEFS,  'label' => 'Espace chefs',      'icon' => 'bi-star',     'role_min' => 'intendant'],
        ['id' => self::MENU_ESPACE_ADMIN,  'label' => "Espace chefs d'U",  'icon' => 'bi-gear',     'role_min' => 'admin'],
        ['id' => self::MENU_CONFIGURATION, 'label' => 'Configuration',     'icon' => 'bi-sliders',  'role_min' => 'superadmin'],
    ];

    /** @var array<string, array<array{label: string, url: string, roleMin: string, order: int, isDynamic: bool, subtitle: ?string, group: string}>> */
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
     * Register a sub-page for a menu. $group controls sort placement
     * (see buildPages()) independently of $isDynamic, which only controls
     * rendering (nav.html.twig's avatar-circle-with-initials treatment for
     * per-member pages) — the two are deliberately separate: the "Espace
     * animés" empty-state placeholder (no linked members) is conceptually
     * part of the dynamic member-list slot for sorting purposes but must
     * never render with the per-member avatar styling, so it passes
     * group: GROUP_DYNAMIC with isDynamic: false.
     */
    public function addPage(string $menuId, string $label, string $url, string $roleMin = 'public', int $order = 100, bool $isDynamic = false, ?string $subtitle = null, string $group = self::GROUP_CORE): void
    {
        $this->pages[$menuId][] = [
            'label' => $label,
            'url' => $url,
            'roleMin' => $roleMin,
            'order' => $order,
            'isDynamic' => $isDynamic,
            'subtitle' => $subtitle,
            'group' => $group,
        ];
    }

    /**
     * Build the complete menu structure filtered for the current role.
     *
     * @return array<array{id: string, label: string, icon: string, pages: array<array{label: string, url: string, isDynamic: bool, subtitle: ?string}>}>
     */
    public function build(): array
    {
        $result = [];

        foreach (self::MENUS as $menu) {
            $menuRole = Role::fromString($menu['role_min']);

            if (!$this->currentRole->hasAccess($menuRole)) {
                continue;
            }

            $pages = $this->buildPages($menu['id']);

            if (count($pages) === 0) {
                continue;
            }

            $result[] = [
                'id' => $menu['id'],
                'label' => $menu['label'],
                'icon' => $menu['icon'],
                'pages' => $pages,
            ];
        }

        return $result;
    }

    /**
     * Build filtered and sorted pages for a menu. Sorted by GROUP_RANK
     * first (dynamic entries, e.g. per-member pages, always before core
     * static pages, always before module-provided pages), then by `order`
     * within each group — a module's `menu_order` (however low) can no
     * longer place it ahead of core pages or dynamic entries, only ahead
     * of other modules. usort() has been stable since PHP 8.0, so two
     * entries with the same group and order keep their registration
     * order.
     *
     * @return array<array{label: string, url: string, isDynamic: bool, subtitle: ?string}>
     */
    private function buildPages(string $menuId): array
    {
        if (!isset($this->pages[$menuId])) {
            return [];
        }

        $entries = $this->pages[$menuId];

        usort(
            $entries,
            fn(array $a, array $b) => (self::GROUP_RANK[$a['group']] <=> self::GROUP_RANK[$b['group']])
                ?: ($a['order'] <=> $b['order'])
        );

        $pages = [];
        foreach ($entries as $entry) {
            $pageRole = Role::fromString($entry['roleMin']);

            if (!$this->currentRole->hasAccess($pageRole)) {
                continue;
            }

            $pages[] = [
                'label' => $entry['label'],
                'url' => $entry['url'],
                'isDynamic' => $entry['isDynamic'],
                'subtitle' => $entry['subtitle'],
            ];
        }

        return $pages;
    }
}
