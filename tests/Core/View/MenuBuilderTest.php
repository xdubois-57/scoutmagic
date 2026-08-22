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
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Trombinoscope', '/trombinoscope', 'identified', 5, false, null, MenuBuilder::GROUP_MODULE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Galerie', '/gallery', 'identified', 6, false, null, MenuBuilder::GROUP_MODULE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10, false, null, MenuBuilder::GROUP_CORE);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Kaa', '/members/2', 'identified', 11, true, null, MenuBuilder::GROUP_DYNAMIC);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Baloo', '/members/1', 'identified', 10, true, null, MenuBuilder::GROUP_DYNAMIC);

        $menus = $builder->build();

        $labels = array_column($menus[0]['pages'], 'label');
        $this->assertSame(['Baloo', 'Kaa', 'Notifications', 'Trombinoscope', 'Galerie'], $labels);
    }

    public function testModuleGroupDefaultsWhenGroupIsOmitted(): void
    {
        // addPage()'s default $group is GROUP_CORE, not GROUP_MODULE — a
        // caller must opt in to the module group explicitly (only
        // Core\Module\ModuleManager::loadModule() does).
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Core page', '/core-page', 'identified', 100);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Dynamic', '/members/1', 'identified', 10, true, null, MenuBuilder::GROUP_DYNAMIC);

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
            MenuBuilder::GROUP_DYNAMIC,
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
            MenuBuilder::GROUP_CORE,
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
}
