<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

class MenuBuilderTest extends TestCase
{
    public function testPublicRoleSeeOnlyNotreUnite(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Config', '/setup', 'admin', 10);

        $menus = $builder->build();

        $this->assertCount(1, $menus);
        $this->assertSame('notre_unite', $menus[0]['id']);
    }

    public function testIdentifiedRoleSeesNotreUniteAndEspaceAnimes(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);

        $menus = $builder->build();

        $this->assertCount(2, $menus);
        $this->assertSame('notre_unite', $menus[0]['id']);
        $this->assertSame('espace_animes', $menus[1]['id']);
    }

    public function testIntendantRoleSeesEspaceChefs(): void
    {
        $builder = new MenuBuilder(Role::INTENDANT);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);

        $menus = $builder->build();

        $this->assertCount(3, $menus);
        $this->assertSame('espace_chefs', $menus[2]['id']);
    }

    public function testChiefRoleSeesNeitherEspaceAdminNorConfiguration(): void
    {
        $builder = new MenuBuilder(Role::CHIEF);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import', '/admin/import', 'admin', 10);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Config', '/setup', 'superadmin', 10);

        $menus = $builder->build();

        // Espace chefs d'U now requires "Chef d'Unité" (admin); Configuration requires superadmin.
        $this->assertCount(3, $menus);
        $ids = array_column($menus, 'id');
        $this->assertNotContains('espace_admin', $ids);
        $this->assertNotContains('configuration', $ids);
    }

    public function testAdminRoleSeesEspaceAdminButNotConfiguration(): void
    {
        $builder = new MenuBuilder(Role::ADMIN);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import', '/admin/import', 'admin', 10);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Config', '/setup', 'superadmin', 10);

        $menus = $builder->build();

        $this->assertCount(4, $menus);
        $ids = array_column($menus, 'id');
        $this->assertContains('espace_admin', $ids);
        $this->assertNotContains('configuration', $ids);
    }

    public function testSuperAdminRoleSeesAllFiveMenus(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import', '/admin/import', 'admin', 10);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Config', '/setup', 'superadmin', 10);

        $menus = $builder->build();

        $this->assertCount(5, $menus);
    }

    public function testSubPageFilteringByRole(): void
    {
        $builder = new MenuBuilder(Role::INTENDANT);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Admin only', '/chefs/admin', 'chief', 20);

        $menus = $builder->build();

        $this->assertCount(1, $menus);
        $pages = $menus[0]['pages'];
        $this->assertCount(1, $pages);
        $this->assertSame('Staffs', $pages[0]['label']);
    }

    public function testMenusWithNoVisibleSubPagesAreExcluded(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        // No pages added for MENU_NOTRE_UNITE
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);

        $menus = $builder->build();

        $this->assertCount(0, $menus);
    }

    public function testPagesAreSortedByOrderWithinTheSameGroup(): void
    {
        $builder = new MenuBuilder(Role::ADMIN);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Third', '/third', 'public', 30);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'First', '/first', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Second', '/second', 'public', 20);

        $menus = $builder->build();

        $pages = $menus[0]['pages'];
        $this->assertSame('First', $pages[0]['label']);
        $this->assertSame('Second', $pages[1]['label']);
        $this->assertSame('Third', $pages[2]['label']);
    }

    /**
     * The real bug this sort was fixed for (ARCHITECTURE §7.1): a module
     * page declaring a very low menu_order (trombinoscope: 5, gallery: 6)
     * used to sort ahead of dynamic per-member entries and core pages with
     * a numerically higher order. Group (dynamic → core → module) is now
     * checked before `order` at all, so this can no longer happen —
     * however low a module sets its `menu_order`.
     */
    public function testDynamicEntriesAlwaysSortBeforeCorePagesWhichAlwaysSortBeforeModulePagesRegardlessOfOrder(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        // Registered out of "natural" order and with module pages using a
        // numerically LOWER order than the core/dynamic entries, matching
        // the real trombinoscope (5) / gallery (6) case.
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Trombinoscope', '/trombinoscope', 'identified', 5, false, null, MenuBuilder::SORT_GROUP_MODULE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Galerie', '/gallery', 'identified', 6, false, null, MenuBuilder::SORT_GROUP_MODULE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10, false, null, MenuBuilder::SORT_GROUP_CORE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Kaa', '/members/2', 'identified', 11, true, null, MenuBuilder::SORT_GROUP_DYNAMIC);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Baloo', '/members/1', 'identified', 10, true, null, MenuBuilder::SORT_GROUP_DYNAMIC);

        $menus = $builder->build();

        $labels = array_column($menus[0]['pages'], 'label');
        $this->assertSame(['Baloo', 'Kaa', 'Notifications', 'Trombinoscope', 'Galerie'], $labels);
    }

    public function testModuleGroupDefaultsWhenGroupIsOmitted(): void
    {
        // addPage()'s default $group is SORT_GROUP_CORE, not SORT_GROUP_MODULE — a
        // caller must opt in to the module group explicitly (only
        // Core\Module\ModuleManager::loadModule() does).
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Core page', '/core-page', 'identified', 100);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Dynamic', '/members/1', 'identified', 10, true, null, MenuBuilder::SORT_GROUP_DYNAMIC);

        $menus = $builder->build();

        $labels = array_column($menus[0]['pages'], 'label');
        $this->assertSame(['Dynamic', 'Core page'], $labels);
    }

    /**
     * usort() has been stable since PHP 8.0 — two entries with the same
     * group and the same numeric order must keep their registration order.
     */
    public function testSortIsStableForEntriesWithTheSameGroupAndOrder(): void
    {
        $builder = new MenuBuilder(Role::ADMIN);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Registered first', '/a', 'public', 50);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Registered second', '/b', 'public', 50);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Registered third', '/c', 'public', 50);

        $menus = $builder->build();

        $labels = array_column($menus[0]['pages'], 'label');
        $this->assertSame(['Registered first', 'Registered second', 'Registered third'], $labels);
    }

    public function testNoPageEverCarriesAnIsSeparatorKey(): void
    {
        // Separators were removed entirely (ARCHITECTURE §6.3 of the menu
        // reorg) — the built page shape must never expose that key at all.
        $builder = new MenuBuilder(Role::PUBLIC);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);

        $menus = $builder->build();

        $this->assertArrayNotHasKey('isSeparator', $menus[0]['pages'][0]);
    }

    public function testLabelForReturnsTheRegisteredMenuLabel(): void
    {
        $this->assertSame('Notre unité', MenuBuilder::labelFor(MenuBuilder::MENU_NOTRE_UNITE));
        $this->assertSame('Espace membres', MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ANIMES));
        $this->assertSame('Espace animateurs', MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_CHEFS));
        $this->assertSame("Espace chefs d'U", MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN));
        $this->assertSame('Configuration', MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION));
    }

    public function testLabelForReturnsEmptyStringForAnUnknownMenuId(): void
    {
        $this->assertSame('', MenuBuilder::labelFor('not_a_real_menu'));
    }

    /**
     * What the mobile menu draws in front of an entry: an icon for an
     * ordinary page, the member's own avatar for a per-member one — so
     * every label in a menu starts at the same x
     * (partials/nav.html.twig).
     */
    public function testAPageCarriesItsIconAndAMemberEntryCarriesItsMember(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Akéla',
            '/members/7',
            'identified',
            10,
            true,
            'Louveteaux',
            MenuBuilder::SORT_GROUP_DYNAMIC,
            null,
            42
        );
        $builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Notifications',
            '/notifications',
            'identified',
            20,
            false,
            null,
            MenuBuilder::SORT_GROUP_CORE,
            'bi-bell'
        );

        $pages = $builder->build()[0]['pages'];

        $this->assertSame(42, $pages[0]['avatarMemberId']);
        $this->assertNull($pages[0]['icon']);
        $this->assertSame('bi-bell', $pages[1]['icon']);
        $this->assertNull($pages[1]['avatarMemberId']);
    }

    /**
     * Both are optional, and an entry that names neither still renders —
     * the template falls back to a neutral icon rather than a hole.
     */
    public function testAnEntryThatNamesNeitherKeepsBothKeysAsNull(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);

        $page = $builder->build()[0]['pages'][0];

        $this->assertNull($page['icon']);
        $this->assertNull($page['avatarMemberId']);
    }

    // --- menu groups (the desktop mega-menu's named columns) -------------

    /**
     * Columns are drawn in MENU_GROUPS' declaration order, whatever the
     * pages' numeric `order` says — `order` still sorts *within* a column,
     * and nothing else.
     */
    public function testGroupOrderFollowsTheDeclarationOrderNotThePagesOrder(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);
        // "Exploitation" is declared last but registered first, and with
        // the lowest `order` of the three.
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'superadmin', 1, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'exploitation');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/settings', 'superadmin', 20, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'site');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Desk', '/config/functions', 'superadmin', 30, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'unite_donnees');

        $groups = $builder->build()[0]['groups'];

        $this->assertSame(['unite_donnees', 'site', 'exploitation'], array_column($groups, 'id'));
        $this->assertSame(['Unité & données', 'Site', 'Exploitation'], array_column($groups, 'label'));
    }

    /**
     * A module page and a core page share a column on purpose (Finances
     * belongs under "Gestion" whichever registered it) — inside it, the
     * existing sort applies untouched: sort group first, `order` second.
     */
    public function testPagesInsideAGroupKeepTheExistingSortGroupThenOrderSort(): void
    {
        $builder = new MenuBuilder(Role::INTENDANT);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Finances', '/finance', 'intendant', 1, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'gestion');
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Statistiques', '/chiefs/stats', 'intendant', 2, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'gestion');
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Page core', '/chefs/core', 'intendant', 99, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'gestion');

        $groups = $builder->build()[0]['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('gestion', $groups[0]['id']);
        $this->assertSame(['Page core', 'Finances', 'Statistiques'], array_column($groups[0]['pages'], 'label'));
    }

    /**
     * Role filtering runs before grouping, so a column whose every page is
     * filtered out is absent entirely: an intendant must never be shown a
     * titled column with nothing under it.
     */
    public function testAGroupWhoseEveryPageIsRoleFilteredIsAbsentRatherThanEmpty(): void
    {
        $builder = new MenuBuilder(Role::INTENDANT);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'ma_section');
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Finances', '/finance', 'chief', 10, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'gestion');

        $groups = $builder->build()[0]['groups'];

        $this->assertSame(['ma_section'], array_column($groups, 'id'));
    }

    /**
     * A page that names no group lands in the menu's *last* declared one —
     * a core page added later and forgotten shows up under "Exploitation",
     * where the omission is visible, rather than inventing an unnamed
     * column of its own.
     */
    public function testAPageWithNoMenuGroupLandsInTheLastDeclaredGroup(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/settings', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'site');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Page oubliée', '/config/oops', 'superadmin', 20);

        $groups = $builder->build()[0]['groups'];

        $this->assertSame(['site', 'exploitation'], array_column($groups, 'id'));
        $this->assertSame(['Page oubliée'], array_column($groups[1]['pages'], 'label'));
    }

    /**
     * Fail loud at boot, never silently into a fallback column: a typo'd
     * group id must be impossible to ship.
     */
    public function testAnUndeclaredMenuGroupIsRefused(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Menu group 'explotation' is not declared for menu 'configuration'");

        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'explotation');
    }

    /**
     * A group declared for *another* menu is just as wrong as a typo — the
     * vocabulary is closed per menu, not globally.
     */
    public function testAGroupDeclaredForAnotherMenuIsRefused(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);

        $this->expectException(\InvalidArgumentException::class);

        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Finances', '/config/finance', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'gestion');
    }

    /**
     * An ungrouped menu returns one untitled group holding every page —
     * one shape for the templates to consume, no "is this menu grouped?"
     * branch downstream.
     */
    public function testAnUngroupedMenuReturnsOneUntitledGroupWithEveryPage(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20);

        $groups = $builder->build()[0]['groups'];

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['id']);
        $this->assertNull($groups[0]['label']);
        $this->assertSame(['Accueil', 'Contact'], array_column($groups[0]['pages'], 'label'));
    }

    /**
     * Grouping is an added view of the same list, never a replacement:
     * `pages` stays the flat, sorted, role-filtered list every existing
     * consumer (the mobile offcanvas) reads, carrying exactly the same
     * keys it did before groups existed.
     */
    public function testPagesStaysTheFlatListItAlwaysWasAlongsideGroups(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'superadmin', 30, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-tools', null, 'exploitation');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/settings', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-gear-wide-connected', null, 'site');

        $menu = $builder->build()[0];

        $this->assertSame(['Réglages', 'Maintenance'], array_column($menu['pages'], 'label'));
        $this->assertSame(
            ['label', 'url', 'isDynamic', 'subtitle', 'icon', 'avatarMemberId'],
            array_keys($menu['pages'][0])
        );
        // The very same page shape, whichever way it was reached.
        $this->assertSame($menu['pages'][0], $menu['groups'][0]['pages'][0]);
    }

    /**
     * Every group id MENU_GROUPS declares must be unique inside its menu —
     * a duplicate would silently swallow one column's pages into another.
     */
    public function testEveryDeclaredGroupIdIsUniqueWithinItsMenu(): void
    {
        foreach (MenuBuilder::MENU_GROUPS as $menuId => $groups) {
            $ids = array_column($groups, 'id');
            $this->assertSame(array_unique($ids), $ids, "duplicate group id in '{$menuId}'");
        }
    }
}
