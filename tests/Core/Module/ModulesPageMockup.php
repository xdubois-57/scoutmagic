<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Module;

use PHPUnit\Framework\Assert;

/**
 * Reads `docs/chantiers/maquettes/maquette-modules.jsx` — the mockup the
 * Modules page was designed from — as the expected shelving.
 *
 * Same technique and same reason as
 * Tests\Core\View\Menu\MenuMockup: the expected value has to come from
 * a document a human reviewed as a design, not from the code it is meant
 * to check. A fixture regenerated from `module.json` would say that the
 * categories are whatever the manifests happen to declare, which is
 * true by construction and worth nothing.
 *
 * The mockup states plainly what it leaves out: « Outils de test » and
 * « Supervision » are reserved by their `visible_when` to the reference
 * installation, local ones and the one collecting statistics. It draws
 * an ordinary unit's screen, and ModulesPageMockupTest names those two
 * rather than filtering them by a rule.
 */
final class ModulesPageMockup
{
    private const PATH = '/docs/chantiers/maquettes/maquette-modules.jsx';

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . self::PATH);
    }

    /**
     * The shelves the mockup declares, id to French label, in order.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        $source = self::source();

        // The full declaration, `= [` included: anchoring on the bare
        // name matched `const CATEGORIES_RENAMED` too, so a mockup whose
        // arrays had been renamed went on parsing and the guard below
        // passed on a reading that was no longer the one intended. A
        // prefix match is not an anchor.
        $start = strpos($source, 'const CATEGORIES = [');
        $end = strpos($source, 'const MODULES = [');
        Assert::assertNotFalse($start, 'The mockup no longer declares a CATEGORIES array.');
        Assert::assertNotFalse($end, 'The mockup no longer declares a MODULES array.');

        preg_match_all('/\["([a-z_]+)", "([^"]+)"\]/u', substr($source, $start, $end - $start), $found, PREG_SET_ORDER);

        $categories = [];
        foreach ($found as $category) {
            $categories[$category[1]] = $category[2];
        }

        return $categories;
    }

    /**
     * Every module the mockup draws: name to `[category, description]`.
     *
     * @return array<string, array{string, string}>
     */
    public static function modules(): array
    {
        preg_match_all(
            '/\{ name: "([^"]+)", cat: "([a-z_]+)", v: "[^"]*", on: \w+, desc: "([^"]*)" \}/u',
            self::source(),
            $found,
            PREG_SET_ORDER
        );

        $modules = [];
        foreach ($found as $module) {
            $modules[$module[1]] = [$module[2], $module[3]];
        }

        return $modules;
    }
}
