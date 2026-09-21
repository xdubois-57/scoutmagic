<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View\Menu;

use PHPUnit\Framework\TestCase;

/**
 * **What every role sees, frozen.**
 *
 * IT-01 of the menu reorganisation replaced an order that was *calculated*
 * — core before modules, plus 1000 per module position — with an order
 * each entry *declares*. The whole point of that iteration is that it
 * changes nothing a person can see, and a claim like that is worth
 * exactly as much as the test that holds it.
 *
 * The fixture was taken on `da64c8b`, before a single order was written,
 * by rendering `MenuInventory` with the offset still applied. Every run
 * since renders the same menus from the declared orders and compares.
 *
 * **How it was taken, because the first attempt got it wrong in a way
 * that left no trace.** It was generated in a `git worktree` of that
 * commit — and the first time, that worktree borrowed the main
 * checkout's `vendor/`. Composer's `$baseDir` resolves `__DIR__` through
 * the symlink to the real path, so `Core\` loaded from the *modified*
 * checkout: the run used the new ranks and produced the new order, which
 * was then recorded as « before » and compared against itself. The
 * snapshot passed, and proved nothing for the one column where the rank
 * merge changes anything.
 *
 * Anyone re-recording this fixture must therefore autoload `Core\` from
 * the worktree itself, never through a shared `vendor/`. The value below
 * is the check: at `da64c8b`, « Espace membres › Pages » began with
 * Notifications, a core page ordered 10 sitting ahead of three module
 * pages ordered 5, 6 and 7 — which is only possible under the two-rank
 * sort this PR removes. A regenerated fixture that does not start that
 * column with Notifications was taken with the wrong code.
 *
 * **IT-02 will change these menus on purpose**, and this fixture is meant
 * to be replaced in that iteration — by the structure the maquette
 * describes, not by whatever the code happens to produce. Re-recording it
 * to make a red run green is how a snapshot test stops testing anything.
 */
final class MenuSnapshotTest extends TestCase
{
    private const ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    private static function fixture(): array
    {
        $path = __DIR__ . '/fixtures/menus-before-reorganisation.json';
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, 'The menu fixture is missing or unreadable.');

        /** @var array<string, array<string, array<int, string>>> $data */
        return $data;
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function roles(): array
    {
        return array_map(static fn(string $role): array => [$role], self::ROLES);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testWhatThisRoleSeesIsUnchanged(string $role): void
    {
        $this->assertSame(
            self::fixture()[$role],
            MenuInventory::render($role, false),
            "The menus a '{$role}' sees have moved. If that was the intention, the fixture is the "
            . 'thing to change — deliberately, entry by entry — and not this assertion.'
        );
    }

    /**
     * **The fixture describes the old sort, not the new one.**
     *
     * « Espace membres › Pages » is the one column where the two-rank
     * sort was load-bearing: a core page ordered 10 rendered ahead of
     * three module pages ordered 5, 6 and 7, which the merged rank makes
     * impossible. A fixture whose column starts anywhere else was
     * recorded with the post-change code, and every other assertion in
     * this file then compares the new behaviour with itself.
     */
    public function testTheFixtureRecordsTheOrderOnlyTheOldRankCouldProduce(): void
    {
        foreach (['intendant', 'chief', 'admin', 'superadmin'] as $role) {
            $this->assertSame(
                'Pages › Notifications',
                self::fixture()[$role]['Espace membres'][0] ?? null,
                "The fixture no longer starts « Espace membres » with Notifications for a '{$role}'. "
                . 'At da64c8b a core page ordered 10 outranked module pages ordered 5-7; if this '
                . 'fixture disagrees, it was regenerated with the new code and proves nothing.'
            );
        }
    }

    /**
     * The fixture is only evidence while it describes every role. A role
     * quietly dropped from it would turn six assertions into five without
     * a single failure.
     */
    public function testTheFixtureCoversEveryRole(): void
    {
        $this->assertSame(self::ROLES, array_keys(self::fixture()));
    }

    /**
     * And the harness only proves something while it reads real entries.
     * A regex that stops matching `public/index.php` would render empty
     * menus, which would then match an empty fixture forever.
     */
    public function testTheHarnessStillFindsTheEntriesItReads(): void
    {
        $this->assertGreaterThan(
            25,
            count(MenuInventory::corePages()),
            'No core menu page was extracted from public/index.php — the extraction broke.'
        );
        $this->assertGreaterThan(
            25,
            count(MenuInventory::modulePages(false)),
            'No module menu entry was extracted from the manifests — the extraction broke.'
        );
    }
}
