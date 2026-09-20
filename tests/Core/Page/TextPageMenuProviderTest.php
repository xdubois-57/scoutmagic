<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPage;
use Core\Page\TextPageMenuProvider;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/** The menu half of issue #368. */
class TextPageMenuProviderTest extends TestCase
{
    private static function page(
        int $id,
        string $menuId,
        ?string $menuGroup,
        string $slug = 'une-page',
        int $sortOrder = 0,
    ): TextPage {
        return new TextPage(
            id: $id,
            slug: $slug,
            menuLabel: 'Une page',
            title: 'Une page',
            menuId: $menuId,
            menuGroup: $menuGroup,
            sortOrder: $sortOrder,
            isActive: true,
        );
    }

    public function testAnEntryLandsInItsSectionItsColumnAndItsOrder(): void
    {
        $provider = new TextPageMenuProvider([
            self::page(1, MenuBuilder::MENU_ESPACE_ANIMES, 'pages', 'a', 0),
            self::page(2, MenuBuilder::MENU_ESPACE_ANIMES, 'pages', 'b', 1),
        ]);

        $entries = $provider->getMenuEntries(null);

        $this->assertCount(2, $entries);
        $this->assertSame(MenuBuilder::MENU_ESPACE_ANIMES, $entries[0]->menuId);
        $this->assertSame('pages', $entries[0]->menuGroup);
        $this->assertSame('/pages/a', $entries[0]->url);
        $this->assertLessThan($entries[1]->order, $entries[0]->order, 'sort_order must decide the order');
    }

    /**
     * A menu entry's floor is the same floor its route enforces.
     *
     * They are never the protection — a visible entry is not an ACL
     * (ARCHITECTURE.md §12) — but a page listed to somebody who would be
     * refused on click is a link to a 403, which is its own defect.
     */
    public function testAnEntrysFloorIsTheOneItsRouteEnforces(): void
    {
        foreach (MenuBuilder::menuIds() as $menuId) {
            $group = MenuBuilder::groupIdsFor($menuId)[0] ?? null;
            $entry = (new TextPageMenuProvider([self::page(1, $menuId, $group)]))->getMenuEntries(null)[0];

            $this->assertSame(MenuBuilder::roleMinFor($menuId), $entry->roleMin, "menu {$menuId}");
        }
    }

    /** The entries do not vary per visitor: the menu's own role filter does that. */
    public function testTheVisitorsAddressChangesNothing(): void
    {
        $provider = new TextPageMenuProvider([self::page(1, MenuBuilder::MENU_NOTRE_UNITE, null)]);

        $anonymous = $provider->getMenuEntries(null);
        $signedIn = $provider->getMenuEntries('chef@example.com');

        $this->assertEquals($anonymous, $signedIn);
    }

    public function testNoPagesMeansNoEntries(): void
    {
        $this->assertSame([], (new TextPageMenuProvider([]))->getMenuEntries(null));
    }

    /**
     * The whole point of validating the section + column pair before it
     * is written: this is the call that would throw, while building the
     * navigation of every page of the site.
     *
     * Exercised here on the real `MenuBuilder` so the two halves — the
     * service's refusal and the builder's exception — are known to be
     * about the same vocabulary.
     */
    public function testEveryEntryThisProviderEmitsIsOneMenuBuilderAccepts(): void
    {
        foreach (MenuBuilder::menuIds() as $menuId) {
            foreach (array_merge(MenuBuilder::groupIdsFor($menuId), [null]) as $group) {
                if ($group === null && MenuBuilder::groupIdsFor($menuId) !== []) {
                    // A grouped menu with no column is refused by the
                    // service before it can ever reach here.
                    continue;
                }
                if ($group !== null && MenuBuilder::groupIdsFor($menuId) === []) {
                    continue;
                }

                $entry = (new TextPageMenuProvider([self::page(1, $menuId, $group)]))->getMenuEntries(null)[0];

                $builder = new MenuBuilder(Role::SUPERADMIN);
                $builder->addPage(
                    $entry->menuId,
                    $entry->label,
                    $entry->url,
                    $entry->roleMin,
                    $entry->order,
                    $entry->isDynamic,
                    $entry->subtitle,
                    $entry->sortGroup,
                    $entry->icon,
                    null,
                    $entry->menuGroup,
                );

                $this->addToAssertionCount(1);
            }
        }
    }
}
