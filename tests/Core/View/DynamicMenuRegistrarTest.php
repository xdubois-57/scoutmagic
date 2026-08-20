<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\Security\Role;
use Core\View\DynamicMenuRegistrar;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Core\Module\MenuEntryProvider is the generalisation of what used to be
 * Core\Module\EspaceAnimesEntryProvider — a hook that could only ever reach
 * "Espace animés", and only for an authenticated visitor. These tests pin
 * both halves of what changed: any menu is now reachable, and an anonymous
 * visitor is a supported case rather than an impossible one.
 *
 * The acceptance criterion they exist for: **behaviour is strictly identical
 * when no provider is registered**, so nothing about the existing menus can
 * shift as a side effect of this mechanism existing.
 */
class DynamicMenuRegistrarTest extends TestCase
{
    private DynamicMenuRegistrar $registrar;

    protected function setUp(): void
    {
        $this->registrar = new DynamicMenuRegistrar();
    }

    /**
     * @param MenuEntry[] $entries
     */
    private function provider(array $entries, ?string $expectedEmail = null): MenuEntryProvider
    {
        return new class ($entries, $expectedEmail) implements MenuEntryProvider {
            /** @param MenuEntry[] $entries */
            public function __construct(
                private array $entries,
                private ?string $expectedEmail
            ) {
            }

            public function getMenuEntries(?string $email): array
            {
                if ($this->expectedEmail !== null && $email !== $this->expectedEmail) {
                    return [];
                }

                return $this->entries;
            }
        };
    }

    private function pagesOf(array $menus, string $menuId): array
    {
        foreach ($menus as $menu) {
            if ($menu['id'] === $menuId) {
                return $menu['pages'];
            }
        }

        return [];
    }

    public function testNoProvidersLeavesTheMenusUntouched(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Calendrier', '/calendar', 'identified');
        $before = $builder->build();

        $added = $this->registrar->register($builder, [], 'someone@example.org');

        $this->assertSame([], $added);
        $this->assertEquals($before, $builder->build());
    }

    public function testAProviderReturningNothingLeavesTheMenusUntouched(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Calendrier', '/calendar', 'identified');
        $before = $builder->build();

        $added = $this->registrar->register($builder, [$this->provider([])], 'someone@example.org');

        $this->assertSame([], $added);
        $this->assertEquals($before, $builder->build());
    }

    public function testAProviderCanInjectIntoEspaceAnimes(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));
        $entry = new MenuEntry(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Ma demande',
            '/inscriptions/suivi/demande/7',
            'identified',
            1000,
            true,
            "Demande d'inscription",
            MenuBuilder::GROUP_DYNAMIC
        );

        $this->registrar->register($builder, [$this->provider([$entry])], 'someone@example.org');

        $pages = $this->pagesOf($builder->build(), MenuBuilder::MENU_ESPACE_ANIMES);
        $this->assertCount(1, $pages);
        $this->assertSame('Ma demande', $pages[0]['label']);
        $this->assertSame('/inscriptions/suivi/demande/7', $pages[0]['url']);
        $this->assertTrue($pages[0]['isDynamic']);
        $this->assertSame("Demande d'inscription", $pages[0]['subtitle']);
    }

    public function testAProviderCanInjectIntoAnyMenuNotJustEspaceAnimes(): void
    {
        // This is the whole point of the generalisation: the old hook was
        // hard-wired to one menu id by the composition root.
        $builder = new MenuBuilder(Role::fromString('superadmin'));

        $this->registrar->register($builder, [$this->provider([
            new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Local Saint-Georges', '/locations/local-saint-georges'),
            new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Mes locations', '/mes-locations', 'identified'),
            new MenuEntry(MenuBuilder::MENU_ESPACE_CHEFS, 'Intendance', '/intendance', 'intendant'),
            new MenuEntry(MenuBuilder::MENU_ESPACE_ADMIN, 'Rapport', '/rapport', 'admin'),
            new MenuEntry(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/x', 'superadmin'),
        ])], null);

        $menus = $builder->build();
        $this->assertSame('Local Saint-Georges', $this->pagesOf($menus, MenuBuilder::MENU_NOTRE_UNITE)[0]['label']);
        $this->assertSame('Mes locations', $this->pagesOf($menus, MenuBuilder::MENU_ESPACE_ANIMES)[0]['label']);
        $this->assertSame('Intendance', $this->pagesOf($menus, MenuBuilder::MENU_ESPACE_CHEFS)[0]['label']);
        $this->assertSame('Rapport', $this->pagesOf($menus, MenuBuilder::MENU_ESPACE_ADMIN)[0]['label']);
        $this->assertSame('Réglages', $this->pagesOf($menus, MenuBuilder::MENU_CONFIGURATION)[0]['label']);
    }

    public function testAnAnonymousVisitorIsASupportedCase(): void
    {
        // The old interface took a non-nullable string email, so a public
        // menu entry was simply not expressible.
        $builder = new MenuBuilder(Role::fromString('public'));

        $added = $this->registrar->register($builder, [$this->provider([
            new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Locations', '/locations'),
        ])], null);

        $this->assertCount(1, $added);
        $this->assertSame('Locations', $this->pagesOf($builder->build(), MenuBuilder::MENU_NOTRE_UNITE)[0]['label']);
    }

    public function testTheVisitorEmailIsPassedThroughToTheProvider(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));
        $entry = new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Ma demande', '/x/7', 'identified');

        $matching = $this->registrar->register(
            $builder,
            [$this->provider([$entry], 'wanted@example.org')],
            'wanted@example.org'
        );
        $this->assertCount(1, $matching);

        $other = $this->registrar->register(
            new MenuBuilder(Role::fromString('identified')),
            [$this->provider([$entry], 'wanted@example.org')],
            'someone-else@example.org'
        );
        $this->assertSame([], $other);
    }

    public function testEveryProviderInTheListContributes(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));

        $added = $this->registrar->register($builder, [
            $this->provider([new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'A', '/a', 'identified')]),
            $this->provider([]),
            $this->provider([new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'B', '/b')]),
        ], null);

        $this->assertCount(2, $added);
        $menus = $builder->build();
        $this->assertSame('A', $this->pagesOf($menus, MenuBuilder::MENU_ESPACE_ANIMES)[0]['label']);
        $this->assertSame('B', $this->pagesOf($menus, MenuBuilder::MENU_NOTRE_UNITE)[0]['label']);
    }

    public function testAnEntryIsHiddenFromAVisitorBelowItsRoleMin(): void
    {
        // The display filter. It is NOT the protection — the route the entry
        // points at carries its own role_min (ARCHITECTURE.md §12).
        $builder = new MenuBuilder(Role::fromString('identified'));

        $this->registrar->register($builder, [$this->provider([
            new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Visible', '/visible', 'identified'),
            new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Caché', '/cache', 'admin'),
        ])], null);

        $pages = $this->pagesOf($builder->build(), MenuBuilder::MENU_ESPACE_ANIMES);
        $this->assertCount(1, $pages);
        $this->assertSame('Visible', $pages[0]['label']);
    }

    public function testEntriesSortWithinTheirGroupNotAheadOfCorePages(): void
    {
        $builder = new MenuBuilder(Role::fromString('identified'));
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page core', '/core', 'identified', 50, false, null, MenuBuilder::GROUP_CORE);

        // A module entry with a far lower order must still sort after the
        // core page — MenuBuilder ranks by group first, order only as a
        // tie-break inside a group.
        $this->registrar->register($builder, [$this->provider([
            new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Page module', '/module', 'identified', 1, false, null, MenuBuilder::GROUP_MODULE),
            new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Entrée dynamique', '/dyn', 'identified', 999, true, null, MenuBuilder::GROUP_DYNAMIC),
        ])], null);

        $labels = array_column($this->pagesOf($builder->build(), MenuBuilder::MENU_ESPACE_ANIMES), 'label');
        $this->assertSame(['Entrée dynamique', 'Page core', 'Page module'], $labels);
    }

    public function testResolveActiveMatchesADynamicEntryOnAnExactPathOnly(): void
    {
        $entries = [new MenuEntry(MenuBuilder::MENU_ESPACE_ANIMES, 'Ma demande', '/x/7', 'identified', 1000, true)];

        $exact = $this->registrar->resolveActive($entries, '/x/7', '', '', -1);
        $this->assertSame(MenuBuilder::MENU_ESPACE_ANIMES, $exact['menuId']);
        $this->assertSame('/x/7', $exact['pageUrl']);
        $this->assertSame(4, $exact['matchLength']);

        // A dynamic entry must not claim the highlight for a sub-path.
        $subPath = $this->registrar->resolveActive($entries, '/x/7/edit', '', '', -1);
        $this->assertSame('', $subPath['menuId']);
        $this->assertSame('', $subPath['pageUrl']);
        $this->assertSame(-1, $subPath['matchLength']);
    }

    public function testResolveActiveMatchesANonDynamicEntryAsAPathPrefix(): void
    {
        // This is what keeps a module's top-level page highlighted while the
        // visitor browses its sub-pages.
        $entries = [new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Locations', '/locations')];

        $result = $this->registrar->resolveActive($entries, '/locations/local-saint-georges', '', '', -1);
        $this->assertSame(MenuBuilder::MENU_NOTRE_UNITE, $result['menuId']);
        $this->assertSame('/locations', $result['pageUrl']);
    }

    public function testResolveActiveDoesNotMatchAMerePrefixOfASegment(): void
    {
        // "/locations" must not light up for "/locations-archive".
        $entries = [new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Locations', '/locations')];

        $result = $this->registrar->resolveActive($entries, '/locations-archive', '', '', -1);
        $this->assertSame('', $result['menuId']);
    }

    public function testResolveActiveKeepsTheLongestMatch(): void
    {
        $entries = [
            new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Locations', '/locations'),
            new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Local', '/locations/local-saint-georges'),
        ];

        $result = $this->registrar->resolveActive($entries, '/locations/local-saint-georges', '', '', -1);
        $this->assertSame('/locations/local-saint-georges', $result['pageUrl']);
    }

    public function testResolveActiveCarriesForwardAnEarlierBetterMatch(): void
    {
        // The first (pre-module) scan in public/index.php already found a
        // longer match; a shorter late entry must not steal the highlight.
        $entries = [new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Locations', '/locations')];

        $result = $this->registrar->resolveActive(
            $entries,
            '/locations/local-saint-georges',
            MenuBuilder::MENU_NOTRE_UNITE,
            '/locations/local-saint-georges',
            strlen('/locations/local-saint-georges')
        );

        $this->assertSame('/locations/local-saint-georges', $result['pageUrl']);
        $this->assertSame(strlen('/locations/local-saint-georges'), $result['matchLength']);
    }

    public function testResolveActiveIgnoresAnEmptyUrl(): void
    {
        $entries = [new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Sans lien', '')];

        $result = $this->registrar->resolveActive($entries, '', '', '', -1);
        $this->assertSame('', $result['menuId']);
        $this->assertSame(-1, $result['matchLength']);
    }

    public function testResolveActiveNeverTreatsTheRootAsAPrefix(): void
    {
        // "/" is a prefix of literally every path; treating it as one would
        // make it permanently active.
        $entries = [new MenuEntry(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/')];

        $onOtherPage = $this->registrar->resolveActive($entries, '/locations', '', '', -1);
        $this->assertSame('', $onOtherPage['menuId']);

        $onRoot = $this->registrar->resolveActive($entries, '/', '', '', -1);
        $this->assertSame(MenuBuilder::MENU_NOTRE_UNITE, $onRoot['menuId']);
    }
}
