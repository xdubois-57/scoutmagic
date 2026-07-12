<?php

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

    /** @var array<array{id: string, label: string, icon: string, role_min: string}> */
    private const MENUS = [
        ['id' => self::MENU_NOTRE_UNITE,   'label' => 'Notre unité',       'icon' => 'bi-house',    'role_min' => 'public'],
        ['id' => self::MENU_ESPACE_ANIMES, 'label' => 'Espace des animés', 'icon' => 'bi-people',   'role_min' => 'identified'],
        ['id' => self::MENU_ESPACE_CHEFS,  'label' => 'Espace des chefs',  'icon' => 'bi-star',     'role_min' => 'intendant'],
        ['id' => self::MENU_ESPACE_ADMIN,  'label' => 'Espace admin',      'icon' => 'bi-gear',     'role_min' => 'chief'],
        ['id' => self::MENU_CONFIGURATION, 'label' => 'Configuration',     'icon' => 'bi-sliders',  'role_min' => 'admin'],
    ];

    /** @var array<string, array<array{label?: string, url?: string, roleMin: string, order: int, isDynamic: bool, isSeparator: bool}>> */
    private array $pages = [];

    public function __construct(
        private Role $currentRole
    ) {
    }

    /**
     * Register a sub-page for a menu.
     */
    public function addPage(string $menuId, string $label, string $url, string $roleMin = 'public', int $order = 100, bool $isDynamic = false): void
    {
        $this->pages[$menuId][] = [
            'label' => $label,
            'url' => $url,
            'roleMin' => $roleMin,
            'order' => $order,
            'isDynamic' => $isDynamic,
            'isSeparator' => false,
        ];
    }

    /**
     * Add a separator in a menu's sub-pages.
     */
    public function addSeparator(string $menuId, int $order = 50): void
    {
        $this->pages[$menuId][] = [
            'roleMin' => 'public',
            'order' => $order,
            'isDynamic' => false,
            'isSeparator' => true,
        ];
    }

    /**
     * Build the complete menu structure filtered for the current role.
     *
     * @return array<array{id: string, label: string, icon: string, pages: array<array{label?: string, url?: string, isDynamic: bool, isSeparator: bool}>}>
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

            // Only include menus that have at least one non-separator page
            $hasRealPage = false;
            foreach ($pages as $page) {
                if (!$page['isSeparator']) {
                    $hasRealPage = true;
                    break;
                }
            }

            if (!$hasRealPage) {
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
     * Build filtered and sorted pages for a menu.
     *
     * @return array<array{label?: string, url?: string, isDynamic: bool, isSeparator: bool}>
     */
    private function buildPages(string $menuId): array
    {
        if (!isset($this->pages[$menuId])) {
            return [];
        }

        $entries = $this->pages[$menuId];

        // Sort by order
        usort($entries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        $pages = [];
        foreach ($entries as $entry) {
            $pageRole = Role::fromString($entry['roleMin']);

            if (!$this->currentRole->hasAccess($pageRole)) {
                continue;
            }

            if ($entry['isSeparator']) {
                $pages[] = [
                    'isDynamic' => false,
                    'isSeparator' => true,
                ];
            } else {
                $pages[] = [
                    'label' => $entry['label'] ?? '',
                    'url' => $entry['url'] ?? '',
                    'isDynamic' => $entry['isDynamic'],
                    'isSeparator' => false,
                ];
            }
        }

        return $pages;
    }
}
