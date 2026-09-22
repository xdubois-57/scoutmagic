<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPage;
use Core\Page\TextPageMenuProvider;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A free-text page filed before the menu reorganisation names a column
 * that no longer exists. It must land in that column's successor, and it
 * must do so by being READ — there is no migration to run, and nothing
 * anywhere rewrites the row.
 *
 * **What is at stake is silence.** An unknown column is not an error
 * anywhere: TextPageMenuProvider::placementIsStillValid() simply declines
 * to place the entry, so the page a chef d'unité wrote stops appearing
 * where they put it, with nothing said to anybody. Every page filed under
 * « Pages », « Gestion », « Contenu du site » or « Exploitation » would
 * have gone that way on the morning of the update.
 */
final class TextPageRenamedColumnsTest extends TestCase
{
    /**
     * Each renamed column with the MENU it belonged to.
     *
     * **The menu matters, and leaving it out made this test weaker than
     * it read.** « Gestion » was a column of Espace animateurs,
     * « Contenu du site » and « Exploitation » of Espace chefs d'U and
     * Configuration. Hydrating all four under Espace membres exercised
     * the map, but never the thing the map exists for: a real row,
     * carrying the menu it was actually filed in, coming back placeable.
     * A successor that belonged to the wrong menu would have passed.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function renamedColumns(): array
    {
        return [
            '« Pages » became « L\'unité »' => [MenuBuilder::MENU_ESPACE_ANIMES, 'pages', 'unite'],
            '« Gestion » became « Argent »' => [MenuBuilder::MENU_ESPACE_CHEFS, 'gestion', 'argent'],
            '« Contenu du site » became « Communication »' => [MenuBuilder::MENU_ESPACE_ADMIN, 'contenu', 'communication'],
            '« Exploitation » became « État du site »' => [MenuBuilder::MENU_CONFIGURATION, 'exploitation', 'etat_du_site'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testARowFiledUnderTheOldColumnIsReadAsTheNewOne(string $menuId, string $stored, string $expected): void
    {
        $page = TextPage::fromRow(self::row($stored, $menuId));

        $this->assertSame($expected, $page->menuGroup);
    }

    /**
     * And the successor belongs to the menu the row was filed in, so the
     * page comes back PLACEABLE rather than merely renamed. This is the
     * assertion that makes the map worth having: a successor from
     * another menu would be dropped by
     * TextPageMenuProvider::placementIsStillValid(), silently, which is
     * the exact failure the map exists to prevent.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testTheSuccessorIsAColumnOfTheMenuTheRowWasFiledIn(string $menuId, string $stored, string $expected): void
    {
        $this->assertContains(
            $expected,
            MenuBuilder::groupIdsFor($menuId),
            "« {$stored} » is mapped onto « {$expected} », which menu {$menuId} does not declare."
        );
    }

    /**
     * Every successor is a real column of some menu. A map pointing at an
     * id nobody declares would move the silence rather than end it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testEverySuccessorIsAColumnSomeMenuActuallyDeclares(string $menuId, string $stored, string $expected): void
    {
        $declared = [];
        foreach (MenuBuilder::MENU_GROUPS as $groups) {
            foreach ($groups as $group) {
                $declared[] = $group['id'];
            }
        }

        $this->assertContains($expected, $declared, "« {$stored} » is mapped onto an undeclared column.");
    }

    /**
     * **The end of the chain, which is the only place the map is really
     * proved.** The two tests above say the successor is right and that
     * its menu declares it; this one hands the hydrated page to the real
     * `TextPageMenuProvider` and checks an entry actually comes out.
     *
     * That provider is where the silence would happen:
     * `placementIsStillValid()` drops a page whose column its menu does
     * not declare, without an exception, a log line or a mark on the
     * page. Asserting the group string can be right while the page still
     * vanishes — only running the provider rules that out.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testTheRehydratedPageIsStillOfferedByTheMenuProvider(string $menuId, string $stored, string $expected): void
    {
        $page = TextPage::fromRow(self::row($stored, $menuId));

        $entries = (new TextPageMenuProvider([$page]))->getMenuEntries(null);

        $this->assertCount(1, $entries, "A page filed under « {$stored} » is no longer placed anywhere.");
        $this->assertSame($menuId, $entries[0]->menuId);
        $this->assertSame($expected, $entries[0]->menuGroup);
    }

    /** A current id is passed straight through, never translated twice. */
    public function testACurrentColumnIsLeftAlone(): void
    {
        $this->assertSame('ma_section', TextPage::fromRow(self::row('ma_section'))->menuGroup);
        $this->assertSame('modules', TextPage::fromRow(self::row('modules'))->menuGroup);
    }

    /** A page filed in no column at all stays in none. */
    public function testNoColumnStaysNoColumn(): void
    {
        $this->assertNull(TextPage::fromRow(self::row(null))->menuGroup);
        $this->assertNull(TextPage::fromRow(self::row(''))->menuGroup);
    }

    /** @return array<string, mixed> */
    private static function row(?string $menuGroup, string $menuId = MenuBuilder::MENU_ESPACE_ANIMES): array
    {
        return [
            'id' => 1,
            'slug' => 'notre-asbl',
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => $menuId,
            'menu_group' => $menuGroup,
            'sort_order' => 0,
            'is_active' => 1,
        ];
    }
}
