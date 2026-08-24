<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Espace membres' dynamic per-member entries — same production shape as
 * public/index.php's own registration (SORT_GROUP_DYNAMIC + isDynamic: true for
 * real member pages; SORT_GROUP_DYNAMIC + isDynamic: false for the empty-state
 * placeholder, which occupies the same sort slot but must not render with
 * the per-member avatar-circle styling). Broader group-vs-order sorting
 * coverage (dynamic/core/module, stability, labelFor()) lives in
 * MenuBuilderTest — this file stays focused on the per-member entry shape.
 */
class MenuBuilderDynamicTest extends TestCase
{
    private MenuBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MenuBuilder(Role::fromString('identified'));
    }

    public function testDynamicMemberEntriesAppearInEspaceAnimesMenu(): void
    {
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Baloo',
            '/members/1',
            'identified',
            10,
            true,  // isDynamic
            'Meute Akela',  // subtitle
            MenuBuilder::SORT_GROUP_DYNAMIC
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

    /**
     * No separator exists anymore (ARCHITECTURE §6.3) — the dynamic
     * member entry and the static page that follows it sort correctly
     * purely by group (SORT_GROUP_DYNAMIC before SORT_GROUP_CORE), with nothing
     * placed between them in the built pages array.
     */
    public function testDynamicEntryStillSortsBeforeAStaticEntryWithNoSeparatorBetweenThem(): void
    {
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Baloo',
            '/members/1',
            'identified',
            10,
            true,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC
        );

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
        $this->assertCount(2, $espaceAnimesMenu['pages']);

        $this->assertTrue($espaceAnimesMenu['pages'][0]['isDynamic']);
        $this->assertSame('Baloo', $espaceAnimesMenu['pages'][0]['label']);
        $this->assertFalse($espaceAnimesMenu['pages'][1]['isDynamic']);
        $this->assertSame('Configuration', $espaceAnimesMenu['pages'][1]['label']);
        $this->assertArrayNotHasKey('isSeparator', $espaceAnimesMenu['pages'][0]);
        $this->assertArrayNotHasKey('isSeparator', $espaceAnimesMenu['pages'][1]);
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
            'Sizaine Loups',
            MenuBuilder::SORT_GROUP_DYNAMIC
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

        // Add dynamic entries (lower order) — group: SORT_GROUP_DYNAMIC, same as
        // public/index.php's real registration.
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'First Member',
            '/members/1',
            'identified',
            10,
            true,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC
        );

        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Second Member',
            '/members/2',
            'identified',
            20,
            true,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC
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
        $this->assertSame('Configuration', $page['label']);
    }

    /**
     * The "no linked members" placeholder — SORT_GROUP_DYNAMIC (so it still
     * occupies the front-of-menu slot real member entries would) but
     * isDynamic: false (so it renders as a plain line, not the per-member
     * avatar-circle treatment), matching public/index.php's own
     * registration exactly.
     */
    public function testEmptyStateMessageIsAddedWhenNoMembers(): void
    {
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Aucun membre associé à votre compte pour l\'année 2025-2026',
            '#',
            'identified',
            10,
            false,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC
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
        $this->assertStringContainsString('Aucun membre associé', $page['label']);
        $this->assertSame('#', $page['url']);
    }

    /**
     * The empty-state placeholder must still sort ahead of a core static
     * page even though both would share the same numeric `order` (10) —
     * it's SORT_GROUP_DYNAMIC, not SORT_GROUP_CORE.
     */
    public function testEmptyStateMessageSortsBeforeACorePageAtTheSameOrder(): void
    {
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Aucun membre associé à votre compte pour l\'année 2025-2026',
            '#',
            'identified',
            10,
            false,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC
        );
        $this->builder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Notifications',
            '/notifications',
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
        $labels = array_column($espaceAnimesMenu['pages'], 'label');
        $this->assertSame(['Aucun membre associé à votre compte pour l\'année 2025-2026', 'Notifications'], $labels);
    }
}
