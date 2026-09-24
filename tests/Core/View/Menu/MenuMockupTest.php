<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View\Menu;

use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The menus this iteration produces must be the menus the mockup draws.
 *
 * See Tests\Core\View\Menu\MenuMockup for why the expected value is read
 * from a design document instead of a fixture regenerated from the code.
 */
final class MenuMockupTest extends TestCase
{
    /**
     * Entries the mockup does not draw, each with the decision taken for
     * it, because a rule that skipped "whatever the mockup omits" would
     * absorb a genuinely lost entry without a word.
     *
     * Five are contributed at runtime through `MenuEntryProvider`: they
     * appear only for a unit that has the data behind them, which a
     * drawing of a menu cannot show. Two carry `visible_when` and belong
     * to an installation profile rather than to a unit.
     *
     * This list is the reason the count below is asserted: it may only
     * ever shrink by a deliberate change to this array.
     *
     * @var array<string, string>
     */
    private const NOT_DRAWN = [
        'Locations' => 'provider (rental) — public index, shown once an asset is public',
        'Gérer mes locations' => 'provider (rental) — only for someone who manages an asset',
        'Scanner un billet' => 'provider (news) — only for a unit that ticketed an event',
        'Bannière' => 'provider (banner) — unit-chief hook',
        'Rétrospective' => 'provider (retro) — unit-chief hook',
        'Supervision' => 'visible_when (support_dashboard) — support installations',
        'Outils de test' => 'visible_when (test_tools) — test installations',
    ];

    /**
     * The shape the reading must find. A mockup that stopped parsing
     * would yield no menus and no columns, and every comparison below
     * would then pass on nothing at all — the failure mode this whole
     * test exists to avoid.
     */
    public function testTheMockupStillReadsAsFiveMenusAndTwentyColumns(): void
    {
        $proposed = MenuMockup::proposed();

        $this->assertCount(5, $proposed);

        $columns = array_sum(array_map('count', $proposed));
        // Twenty since the carpool chantier gave the Espace membres its
        // « Activités » column (docs/chantiers/covoiturage.md, IT-06).
        $this->assertSame(20, $columns, 'The mockup no longer draws twenty columns.');

        $entries = 0;
        foreach ($proposed as $menu) {
            foreach ($menu as $column) {
                $entries += count($column);
            }
        }
        $this->assertGreaterThan(50, $entries, 'The mockup reading found almost no entries.');
    }

    /**
     * MENU_GROUPS declares the columns the mockup draws, in the order it
     * draws them. Column order is declaration order, never `menu_order`,
     * so this is the only place it can be checked.
     */
    public function testEveryMenuDeclaresTheColumnsTheMockupDrawsInThatOrder(): void
    {
        foreach (MenuMockup::proposed() as $menuId => $columns) {
            $drawn = array_values(array_filter(array_keys($columns), static fn(string $c): bool => $c !== ''));

            $declared = array_map(
                static fn(array $group): string => $group['label'],
                MenuBuilder::MENU_GROUPS[$menuId]
            );

            $this->assertSame($drawn, $declared, "Columns of menu « {$menuId} » differ from the mockup.");
        }
    }

    /**
     * The entries themselves, column by column, in order.
     *
     * Read at `superadmin` because that is the one role that sees every
     * menu, and the other five roles are covered entry by entry below.
     * Checking them here too would only make this test slower at saying
     * the same thing.
     */
    public function testEveryColumnHoldsTheEntriesTheMockupDrawsInThatOrder(): void
    {
        $rendered = MenuInventory::render('superadmin', false);

        foreach (MenuMockup::proposed() as $menuId => $columns) {
            $menuLabel = MenuBuilder::labelFor($menuId);
            $this->assertArrayHasKey($menuLabel, $rendered);

            foreach ($columns as $column => $drawn) {
                $actual = [];
                foreach ($rendered[$menuLabel] as $line) {
                    if ($column === '') {
                        $actual[] = $line;
                        continue;
                    }
                    if (str_starts_with($line, $column . ' › ')) {
                        $actual[] = substr($line, strlen($column . ' › '));
                    }
                }

                $actual = array_values(array_diff($actual, array_keys(self::NOT_DRAWN)));

                $this->assertSame(
                    $drawn,
                    $actual,
                    "« {$menuLabel} › {$column} » differs from the mockup."
                );
            }
        }
    }

    /**
     * **What each of the six roles sees**, read from the same drawing.
     *
     * This replaces `MenuSnapshotTest`, whose fixture proved that IT-01
     * changed nothing visible. That claim is deliberately false from this
     * iteration on, so re-recording that fixture from the new code would
     * have turned it into the code agreeing with itself — the defect that
     * took three review rounds to find in IT-01, and which its own
     * docblock warned against in these words: « by the structure the
     * maquette describes, not by whatever the code happens to produce ».
     *
     * The mockup carries a role floor on every entry AND on every menu,
     * and those floors were checked against the real `role_min` of all 68
     * shipped entries before this test was written: they agree
     * everywhere. So the drawing can say what a parent sees and what a
     * superadmin sees, and be wrong out loud when the code disagrees.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testWhatThisRoleSeesIsWhatTheMockupDrawsForThem(string $role): void
    {
        $rendered = MenuInventory::render($role, false);
        $proposed = MenuMockup::proposed($role);

        $this->assertSame(
            array_map(MenuBuilder::labelFor(...), array_keys($proposed)),
            array_values(array_filter(
                array_keys($rendered),
                static fn(string $key): bool => !str_ends_with($key, ' (mobile)')
            )),
            "The menus « {$role} » is offered differ from the mockup."
        );

        foreach ($proposed as $menuId => $columns) {
            $menuLabel = MenuBuilder::labelFor($menuId);

            foreach ($columns as $column => $drawn) {
                $actual = [];
                foreach ($rendered[$menuLabel] as $line) {
                    if ($column === '') {
                        $actual[] = $line;
                    } elseif (str_starts_with($line, $column . ' › ')) {
                        $actual[] = substr($line, strlen($column . ' › '));
                    }
                }

                $this->assertSame(
                    $drawn,
                    array_values(array_diff($actual, array_keys(self::NOT_DRAWN))),
                    "« {$menuLabel} › {$column} » differs from the mockup for « {$role} »."
                );
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        $cases = [];
        foreach (MenuMockup::ROLES as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    /**
     * Every role must actually be exercised above: a provider that
     * silently returned fewer cases would leave roles unchecked and say
     * nothing, which is how a data-driven test stops testing.
     */
    public function testAllSixRolesAreExercised(): void
    {
        $this->assertSame(
            ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'],
            array_keys(self::roles())
        );
    }

    /**
     * **The mobile list is drawn from a different array**, and it is the
     * one that used to be forgotten: nav.html.twig builds the desktop
     * mega-menu from `groups` and the mobile offcanvas from `pages`, the
     * flat sorted list. Checking only columns passes while the phone
     * shows another order entirely — that was IT-01's fifth finding.
     *
     * Numbering along the flat list makes the two agree, so the flat list
     * must equal the columns read end to end.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testTheMobileListIsTheColumnsReadEndToEnd(string $role): void
    {
        $rendered = MenuInventory::render($role, false);

        foreach (array_keys(MenuMockup::proposed($role)) as $menuId) {
            $menuLabel = MenuBuilder::labelFor($menuId);

            $flattenedColumns = array_map(
                static function (string $line): string {
                    $at = strpos($line, ' › ');

                    return $at === false ? $line : substr($line, $at + strlen(' › '));
                },
                $rendered[$menuLabel]
            );

            $this->assertSame(
                $flattenedColumns,
                $rendered[$menuLabel . ' (mobile)'],
                "« {$menuLabel} » renders a different order on a phone for « {$role} »."
            );
        }
    }

    /**
     * Everything the site ships is either drawn by the mockup or named in
     * NOT_DRAWN with its reason. An entry that is neither has been added
     * without anyone deciding where it goes, or lost.
     */
    public function testEveryShippedEntryIsEitherDrawnOrNamedWithItsReason(): void
    {
        $drawn = [];
        foreach (MenuMockup::proposed() as $columns) {
            foreach ($columns as $entries) {
                $drawn = [...$drawn, ...$entries];
            }
        }

        $shipped = [];
        foreach ([...MenuInventory::corePages(), ...MenuInventory::modulePages(false), ...MenuInventory::providerPages()] as $page) {
            $shipped[] = (string) $page['label'];
        }

        $unaccounted = array_diff(array_unique($shipped), $drawn, array_keys(self::NOT_DRAWN));

        $this->assertSame([], array_values($unaccounted), 'Menu entries neither drawn by the mockup nor listed as not drawn.');
    }

    /**
     * The other direction: the mockup may not promise an entry the site
     * does not have. A renaming that was applied to the drawing and
     * forgotten in a manifest would land exactly here.
     */
    public function testTheMockupPromisesNothingTheSiteDoesNotShip(): void
    {
        $shipped = [];
        foreach ([...MenuInventory::corePages(), ...MenuInventory::modulePages(false), ...MenuInventory::providerPages()] as $page) {
            $shipped[] = (string) $page['label'];
        }
        // Two entries are real but hidden behind `visible_when`, so the
        // static reading above cannot see them.
        $shipped[] = 'Supervision';
        $shipped[] = 'Outils de test';

        $drawn = [];
        foreach (MenuMockup::proposed() as $columns) {
            foreach ($columns as $entries) {
                $drawn = [...$drawn, ...$entries];
            }
        }

        $promised = array_diff(array_unique($drawn), $shipped);

        $this->assertSame([], array_values($promised), 'The mockup draws entries the site does not ship.');
    }
}
