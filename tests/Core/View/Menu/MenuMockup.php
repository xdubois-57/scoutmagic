<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View\Menu;

use PHPUnit\Framework\Assert;

/**
 * Reads `docs/chantiers/maquettes/maquette-menus.jsx` — the mockup this
 * iteration was designed from — as the expected value of the menus.
 *
 * ---------------------------------------------------------------------
 * Why the expectation is read from a document rather than a fixture
 * ---------------------------------------------------------------------
 * The previous iteration proved an IDENTITY: the menus after had to equal
 * the menus before, so a snapshot taken from the old code was a legitimate
 * expected value. This one CHANGES the menus, and there a snapshot
 * regenerated from the new code proves nothing at all — it is the test
 * comparing the code to itself, which is exactly the defect that took
 * three review rounds to find in IT-01 (see
 * docs/chantiers/reorganisation-des-menus.md). Green, and empty.
 *
 * So the expectation comes from the mockup: a file written before the
 * code, by hand, that a human reviewed as a design. If the menus and the
 * mockup disagree, one of them is wrong and someone has to choose — which
 * is the whole point.
 *
 * ---------------------------------------------------------------------
 * The mockup is JSX, and that is deliberate
 * ---------------------------------------------------------------------
 * `docs/chantiers/maquettes/README.md` records that these files are
 * analysed by neither `tsconfig.json` nor Vitest: they are drawings, not
 * product code. Reading one from PHP by pattern is the same technique
 * MenuInventory already uses on `public/index.php`, and for the same
 * reason — the thing being read is not loadable in this context.
 *
 * That makes the reading itself a thing that can break silently, so
 * MenuMockupTest asserts the shape it found (five menus, twenty
 * columns) before trusting a single comparison. A mockup that stopped
 * parsing would otherwise match everything.
 */
final class MenuMockup
{
    private const PATH = '/docs/chantiers/maquettes/maquette-menus.jsx';

    /** The mockup's menu labels, mapped to the ids MenuBuilder uses. */
    private const MENU_LABELS = [
        'Notre unité' => 'notre_unite',
        'Espace membres' => 'espace_animes',
        'Espace animateurs' => 'espace_chefs',
        "Espace chefs d'U" => 'espace_admin',
        'Configuration' => 'configuration',
    ];

    /**
     * The per-member entries the mockup draws to show where they land.
     * They are built from the database at runtime, never declared, so no
     * static reading of the sources can produce them.
     */
    private const DYNAMIC_EXAMPLES = ['Lutin (Baladins)', 'Hibou (Louveteaux)'];

    /**
     * The roles the mockup's per-entry numbers stand for, in order. Its
     * personas map one to one onto MenuBuilder's floors, which is what
     * lets the drawing carry the per-role expectation as well as the
     * per-column one.
     */
    public const ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

    /**
     * The proposed menus, as `[menu id => [column label => [entry, …]]]`,
     * columns and entries in the order the mockup draws them.
     *
     * `$upTo` keeps only the entries a given role may see, named as in
     * ROLES; the default keeps every one.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function proposed(?string $upTo = null): array
    {
        $ceiling = $upTo === null ? count(self::ROLES) - 1 : array_search($upTo, self::ROLES, true);
        Assert::assertIsInt($ceiling, "Unknown role « {$upTo} ».");

        $source = (string) file_get_contents(dirname(__DIR__, 4) . self::PATH);

        $start = strpos($source, 'const AFTER = [');
        Assert::assertNotFalse($start, 'The mockup no longer declares an AFTER tree.');

        $end = strpos($source, "\n];", $start);
        Assert::assertNotFalse($end, 'The AFTER tree is not terminated.');

        $tree = substr($source, $start, $end - $start);

        // Split on each menu header, so every column that follows one is
        // attributed to it: `{ label: "…", icon: "…", role: N, groups: [`.
        $chunks = preg_split(
            '/\{ label: "([^"]+)", icon: "[^"]*", role: (\d+), groups: \[/u',
            $tree,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        Assert::assertIsArray($chunks);

        $menus = [];
        for ($i = 1; $i < count($chunks); $i += 3) {
            $label = $chunks[$i];
            if ((int) $chunks[$i + 1] > $ceiling) {
                continue;
            }
            Assert::assertArrayHasKey($label, self::MENU_LABELS, "Unknown menu « {$label} » in the mockup.");

            $columns = [];
            preg_match_all('/\{ label: (null|"[^"]*"), items: \[(.*?)\] \}/su', $chunks[$i + 2], $found, PREG_SET_ORDER);
            foreach ($found as $column) {
                $name = $column[1] === 'null' ? '' : trim($column[1], '"');

                preg_match_all('/\["([^"]+)", (\d+)\]/u', $column[2], $entries, PREG_SET_ORDER);

                $visible = [];
                foreach ($entries as $entry) {
                    if ((int) $entry[2] > $ceiling || in_array($entry[1], self::DYNAMIC_EXAMPLES, true)) {
                        continue;
                    }
                    $visible[] = $entry[1];
                }

                $columns[$name] = $visible;
            }

            $menus[self::MENU_LABELS[$label]] = $columns;
        }

        return $menus;
    }
}
