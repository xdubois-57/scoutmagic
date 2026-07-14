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

        // Espace admin now requires "Chef d'Unité" (admin); Configuration requires superadmin.
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

    public function testAddSeparatorCreatesSeparatorEntry(): void
    {
        $builder = new MenuBuilder(Role::ADMIN);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Before', '/before', 'public', 10);
        $builder->addSeparator(MenuBuilder::MENU_NOTRE_UNITE, 50);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'After', '/after', 'public', 60);

        $menus = $builder->build();

        $this->assertCount(1, $menus);
        $pages = $menus[0]['pages'];
        $this->assertCount(3, $pages);
        $this->assertFalse($pages[0]['isSeparator']);
        $this->assertTrue($pages[1]['isSeparator']);
        $this->assertFalse($pages[2]['isSeparator']);
    }

    public function testMenusWithNoVisibleSubPagesAreExcluded(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        // No pages added for MENU_NOTRE_UNITE
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);

        $menus = $builder->build();

        $this->assertCount(0, $menus);
    }

    public function testPagesAreSortedByOrder(): void
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
}
