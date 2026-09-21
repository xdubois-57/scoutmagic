<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPage;
use Core\Page\TextPageMenuProvider;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * **A unit's own pages come after everything the site shipped.**
 *
 * That is what `TextPageMenuProvider::ORDER_BASE` has always said it
 * meant — « they are additions to a menu somebody curated, so they follow
 * what the site shipped rather than interleaving with it » — and until
 * the rank merge it was only half true. Sorting was `(rank, order)` with
 * core at 1 and modules at 2, so a free-text page at 600 was compared on
 * rank first and landed AHEAD of every module entry, whatever number
 * either declared. The 600 base only separated it from core pages.
 *
 * Merging the two ranks is what finally makes the reservation mean what
 * it says. It is also a change a unit with free-text pages can see: those
 * pages move from above the module entries of their column to below them.
 *
 * **Nothing else could catch this.** Free-text pages are database rows,
 * so `Tests\Core\View\Menu\MenuInventory` — which reads the sources —
 * never produces one, and no snapshot of the shipped menus contains one.
 * A test that feeds the real provider into the real builder is the only
 * place the interaction exists at all.
 */
final class TextPageSitAfterWhatTheSiteShippedTest extends TestCase
{
    public function testAFreeTextPageFollowsCoreAndModuleEntriesAlike(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);

        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'mes_membres');
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Photos', '/gallery', 'identified', 40, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'mes_membres');

        foreach ((new TextPageMenuProvider([self::page(1, 'notre-asbl', 'Notre ASBL', 0)]))->getMenuEntries(null) as $entry) {
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
        }

        $this->assertSame(
            ['Notifications', 'Photos', 'Notre ASBL'],
            array_column($builder->build()[0]['pages'], 'label'),
            'A unit page must follow every entry the site shipped, module ones included.'
        );
    }

    /**
     * And between themselves they keep the order their author chose, so
     * the reservation orders them as a block rather than scattering them.
     */
    public function testUnitPagesKeepTheOrderTheirAuthorGaveThem(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Photos', '/gallery', 'identified', 40, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'mes_membres');

        $pages = [
            self::page(1, 'charte', 'Charte', 2),
            self::page(2, 'notre-asbl', 'Notre ASBL', 0),
            self::page(3, 'reglement', 'Règlement', 1),
        ];

        foreach ((new TextPageMenuProvider($pages))->getMenuEntries(null) as $entry) {
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
        }

        $this->assertSame(
            ['Photos', 'Notre ASBL', 'Règlement', 'Charte'],
            array_column($builder->build()[0]['pages'], 'label')
        );
    }

    private static function page(int $id, string $slug, string $label, int $sortOrder): TextPage
    {
        return new TextPage(
            id: $id,
            slug: $slug,
            menuLabel: $label,
            title: $label,
            menuId: MenuBuilder::MENU_ESPACE_ANIMES,
            menuGroup: 'mes_membres',
            sortOrder: $sortOrder,
            isActive: true,
        );
    }
}
