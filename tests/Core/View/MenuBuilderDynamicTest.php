<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

class MenuBuilderDynamicTest extends TestCase
{
    private MenuBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MenuBuilder(Role::fromString('identified'));
    }

    public function testDynamicMemberEntriesAppearInEspaceAnimesMenu(): void
    {
        // Add a dynamic member entry
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Baloo',
            '/members/1',
            'identified',
            10,
            true,  // isDynamic
            'Meute Akela'  // subtitle
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $this->assertCount(1, $espaceAnimesMenu['pages']);

        $page = $espaceAnimesMenu['pages'][0];
        $this->assertSame('Baloo', $page['label']);
        $this->assertSame('/members/1', $page['url']);
        $this->assertTrue($page['isDynamic']);
        $this->assertSame('Meute Akela', $page['subtitle']);
    }

    public function testSeparatorIsBetweenDynamicAndStaticEntries(): void
    {
        // Add dynamic member entry
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Baloo',
            '/members/1',
            'identified',
            10,
            true
        );

        // Add separator
        $this->builder->addSeparator(MenuBuilder::MENU_ESPACE_ANIMES, 50);

        // Add static entry
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Configuration',
            '/config',
            'identified',
            60
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $this->assertCount(3, $espaceAnimesMenu['pages']);

        // Check order: dynamic, separator, static
        $this->assertTrue($espaceAnimesMenu['pages'][0]['isDynamic']);
        $this->assertTrue($espaceAnimesMenu['pages'][1]['isSeparator']);
        $this->assertFalse($espaceAnimesMenu['pages'][2]['isDynamic']);
        $this->assertFalse($espaceAnimesMenu['pages'][2]['isSeparator']);
    }

    public function testSubtitleIsSetOnDynamicEntries(): void
    {
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Mowgli',
            '/members/2',
            'identified',
            10,
            true,
            'Sizaine Loups'
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $page = $espaceAnimesMenu['pages'][0];
        $this->assertSame('Sizaine Loups', $page['subtitle']);
    }

    public function testMemberEntriesAreOrderedBeforeStaticEntries(): void
    {
        // Add static entry first (higher order)
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Static Page',
            '/static',
            'identified',
            100
        );

        // Add dynamic entries (lower order)
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'First Member',
            '/members/1',
            'identified',
            10,
            true
        );

        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Second Member',
            '/members/2',
            'identified',
            20,
            true
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $this->assertCount(3, $espaceAnimesMenu['pages']);

        // Check order: dynamic entries first, then static
        $this->assertSame('First Member', $espaceAnimesMenu['pages'][0]['label']);
        $this->assertSame('Second Member', $espaceAnimesMenu['pages'][1]['label']);
        $this->assertSame('Static Page', $espaceAnimesMenu['pages'][2]['label']);
    }

    public function testNoDynamicEntriesWhenUserHasNoLinkedMembers(): void
    {
        // Add only static entries
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Configuration',
            '/config',
            'identified',
            10
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $this->assertCount(1, $espaceAnimesMenu['pages']);

        $page = $espaceAnimesMenu['pages'][0];
        $this->assertFalse($page['isDynamic']);
        $this->assertFalse($page['isSeparator']);
        $this->assertSame('Configuration', $page['label']);
    }

    public function testEmptyStateMessageIsAddedWhenNoMembers(): void
    {
        // Add empty state message
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Aucun membre associé à votre compte pour l\'année 2025-2026',
            '#',
            'identified',
            10,
            false,
            null
        );

        $menus = $this->builder->build();

        $espaceAnimesMenu = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $espaceAnimesMenu = $menu;
                break;
            }
        }

        $this->assertNotNull($espaceAnimesMenu);
        $this->assertCount(1, $espaceAnimesMenu['pages']);

        $page = $espaceAnimesMenu['pages'][0];
        $this->assertFalse($page['isDynamic']);
        $this->assertFalse($page['isSeparator']);
        $this->assertStringContainsString('Aucun membre associé', $page['label']);
        $this->assertSame('#', $page['url']);
    }
}
