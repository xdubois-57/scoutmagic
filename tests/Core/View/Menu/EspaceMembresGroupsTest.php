<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View\Menu;

use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The Espace membres after the carpool chantier (docs/chantiers/
 * covoiturage.md, IT-06):
 *
 *     Mes membres (dynamic entries, Notifications)
 *     · Activités (Photos, Covoiturage)
 *     · L'unité (Les animateurs, Discussions, Gérer mes locations)
 *
 * and the two carpool entries in two menus under two labels (D2).
 */
final class EspaceMembresGroupsTest extends TestCase
{
    /**
     * @return array<string, list<string>> column label => entries
     */
    private static function columns(string $role, string $menuLabel): array
    {
        $columns = [];
        foreach (MenuInventory::render($role, false)[$menuLabel] ?? [] as $line) {
            [$column, $entry] = explode(' › ', $line, 2) + [1 => ''];
            $columns[$column][] = $entry;
        }

        return $columns;
    }

    public function testTheThreeColumnsOfTheEspaceMembres(): void
    {
        $this->assertSame(
            ['Mes membres', 'Activités', "L'unité"],
            array_map(static fn(array $g): string => $g['label'], MenuBuilder::MENU_GROUPS[MenuBuilder::MENU_ESPACE_ANIMES])
        );

        $columns = self::columns('identified', 'Espace membres');
        $this->assertSame(['Photos', 'Covoiturage'], $columns['Activités']);
        $this->assertSame(['Les animateurs', 'Discussions', 'Gérer mes locations'], $columns["L'unité"]);
        $this->assertSame(['Notifications'], $columns['Mes membres']);
    }

    public function testTheTwoCarpoolPagesAreTwoEntriesUnderTwoLabels(): void
    {
        $this->assertContains('Covoiturage', self::columns('identified', 'Espace membres')['Activités']);
        $this->assertArrayNotHasKey('Espace animateurs', MenuInventory::render('identified', false));

        $staff = self::columns('chief', 'Espace animateurs')['Activités'];
        $this->assertContains('Organiser les covoiturages', $staff);
        $this->assertNotContains('Covoiturage', $staff, 'One label per page: no seventh duplicated pair.');
        // An intendant reaches the Espace animateurs, not the organiser.
        $this->assertNotContains('Organiser les covoiturages', self::columns('intendant', 'Espace animateurs')['Activités'] ?? []);
    }

    public function testTheRentalEntriesAreNoLongerTwoWordsApart(): void
    {
        $this->assertContains('Biens à louer', self::columns('admin', "Espace chefs d'U")["Services de l'unité"]);
        $all = (string) json_encode(MenuInventory::render('superadmin', false));
        $this->assertStringNotContainsString('Gérer les locations', $all);
    }

    public function testAColumnWithNothingVisibleForTheRoleDrawsNoTitle(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10, false, null, MenuBuilder::SORT_GROUP_CORE, null, null, 'mes_membres');
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Réservé', '/x', 'chief', 20, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'activites');
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Discussions', '/groups', 'identified', 50, false, null, MenuBuilder::SORT_GROUP_MODULE, null, null, 'unite');

        $menus = $builder->build();
        $labels = [];
        foreach ($menus as $menu) {
            if ($menu['id'] === MenuBuilder::MENU_ESPACE_ANIMES) {
                $labels = array_map(static fn(array $group): ?string => $group['label'], $menu['groups']);
            }
        }

        $this->assertSame(['Mes membres', "L'unité"], $labels);
    }
}
