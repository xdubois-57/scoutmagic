<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPage;
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
    /** @return array<string, array{string, string}> */
    public static function renamedColumns(): array
    {
        return [
            '« Pages » became « L\'unité »' => ['pages', 'unite'],
            '« Gestion » became « Argent »' => ['gestion', 'argent'],
            '« Contenu du site » became « Communication »' => ['contenu', 'communication'],
            '« Exploitation » became « État du site »' => ['exploitation', 'etat_du_site'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testARowFiledUnderTheOldColumnIsReadAsTheNewOne(string $stored, string $expected): void
    {
        $page = TextPage::fromRow(self::row($stored));

        $this->assertSame($expected, $page->menuGroup);
    }

    /**
     * Every successor is a real column of some menu. A map pointing at an
     * id nobody declares would move the silence rather than end it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renamedColumns')]
    public function testEverySuccessorIsAColumnSomeMenuActuallyDeclares(string $stored, string $expected): void
    {
        $declared = [];
        foreach (MenuBuilder::MENU_GROUPS as $groups) {
            foreach ($groups as $group) {
                $declared[] = $group['id'];
            }
        }

        $this->assertContains($expected, $declared, "« {$stored} » is mapped onto an undeclared column.");
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
    private static function row(?string $menuGroup): array
    {
        return [
            'id' => 1,
            'slug' => 'notre-asbl',
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_ESPACE_ANIMES,
            'menu_group' => $menuGroup,
            'sort_order' => 0,
            'is_active' => 1,
        ];
    }
}
