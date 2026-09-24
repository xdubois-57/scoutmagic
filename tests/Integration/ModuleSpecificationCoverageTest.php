<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Every module owes the functional specification a section, and
 * specifications.md §1.1 is the index that says which one.
 *
 * Ten modules had shipped without one before this test existed, which is
 * exactly the failure it exists to make loud: a module nobody wrote down
 * is a module the next person reverse-engineers from its controllers,
 * and by then the reasons behind its decisions are gone. Same intent as
 * Tests\Modules\Groups\DocumentationTest, applied to all of them at once
 * so there is nothing per-module to remember.
 */
class ModuleSpecificationCoverageTest extends TestCase
{
    /**
     * Which §4 subsection lists a given menu's pages. See
     * testEveryMenuEntryAModuleAddsHasItsRowInSectionFour() for why the
     * keys cannot be read as the French names they resemble.
     */
    private const MENU_SECTIONS = [
        'notre_unite' => '4.1',
        'espace_animes' => '4.2',
        'espace_chefs' => '4.3',
        'espace_admin' => '4.4',
        'configuration' => '4.5',
    ];

    public function testEveryModuleIsListedInTheSpecificationIndex(): void
    {
        $specs = $this->read('specifications.md');

        foreach ($this->moduleIds() as $moduleId) {
            $this->assertMatchesRegularExpression(
                '/^\| `' . preg_quote($moduleId, '/') . '` \|/m',
                $specs,
                "modules/{$moduleId} has no row in specifications.md §1.1 — add its section and index it there."
            );
        }
    }

    /**
     * A row pointing at a section that does not exist is worse than no
     * row: it reads as covered.
     */
    public function testEverySectionTheIndexPointsAtExists(): void
    {
        $specs = $this->read('specifications.md');

        preg_match_all('/^\| `([a-z_]+)` \| [^|]+ \| ([^|]+) \|$/m', $specs, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows, 'The §1.1 module index could not be parsed.');

        foreach ($rows as [, $moduleId, $references]) {
            preg_match_all('/§(\d+)/u', $references, $sections);
            $this->assertNotEmpty(
                $sections[1],
                "The §1.1 row for `{$moduleId}` names no section."
            );

            foreach ($sections[1] as $section) {
                $this->assertMatchesRegularExpression(
                    '/^## ' . $section . '\. /m',
                    $specs,
                    "specifications.md §1.1 sends `{$moduleId}` to §{$section}, which does not exist."
                );
            }
        }
    }

    /**
     * The index is only trustworthy while it is exhaustive in both
     * directions — a row left behind by a deleted module sends the
     * reader looking for code that is gone.
     */
    public function testTheIndexListsNoModuleThatDoesNotExist(): void
    {
        preg_match_all('/^\| `([a-z_]+)` \|/m', $this->read('specifications.md'), $rows);

        foreach ($rows[1] as $moduleId) {
            $this->assertDirectoryExists(
                dirname(__DIR__, 2) . '/modules/' . $moduleId,
                "specifications.md §1.1 lists `{$moduleId}`, which is not a module."
            );
        }
    }

    /**
     * §1.1's middle column is headed « Name in the interface », and it
     * must be the name the manifest gives — that is the string the module
     * registry renders, and the one every dependency message quotes.
     *
     * This rule could not be written when the rest of this file was: the
     * index then said « Groupes » where `modules/groups` declared
     * « Groupes de discussion », and « Intelligence artificielle » where
     * `llm_connector` declared « Connecteur IA ». Both were defensible —
     * they were the MENU labels — so the column had two possible
     * meanings and no rule to state.
     *
     * `main` has since renamed both modules, and the ambiguity went with
     * them: all 24 rows now equal their manifest's `name`. The index kept
     * saying « Groupes » for a while afterwards, which is no longer a
     * second reading of the column but simply a stale row — and nothing
     * caught it until a reviewer did. Hence this.
     */
    public function testTheIndexNamesEachModuleAsItsManifestDoes(): void
    {
        preg_match_all(
            '/^\| `([a-z_]+)` \| ([^|]+?) \| [^|]+ \|$/m',
            $this->read('specifications.md'),
            $rows,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($rows, 'The §1.1 module index could not be parsed.');

        $wrong = [];

        foreach ($rows as [, $moduleId, $quoted]) {
            $manifest = dirname(__DIR__, 2) . "/modules/{$moduleId}/module.json";
            if (!is_file($manifest)) {
                continue;
            }

            /** @var array{name?: string} $data */
            $data = json_decode((string) file_get_contents($manifest), true);
            $name = (string) ($data['name'] ?? '');

            if ($quoted !== $name) {
                $wrong[] = "`{$moduleId}`: §1.1 says « {$quoted} », the manifest says « {$name} »";
            }
        }

        $this->assertSame(
            [],
            $wrong,
            "specifications.md §1.1 names a module differently from its manifest:\n  "
            . implode("\n  ", $wrong)
        );
    }

    /**
     * §1.1 promises twice over: a section per module, and "the pages a
     * module adds are also listed, per menu, in §4". The three tests
     * above hold the first half. This one holds the second, which had
     * nothing holding it — and five menu entries had drifted out of §4
     * by the time it was written.
     *
     * A menu entry is a GET route carrying a non-empty `label`: that
     * label is the words the site puts in the menu, so it is also what
     * a reader comparing this document to the screen searches for.
     *
     * **The menu keys do not say what they mean**, and reading them as
     * French is the trap this map exists to remove: `espace_animes` is
     * the Espace MEMBRES (§4.2, `identified`), `espace_chefs` is the
     * Espace ANIMATEURS (§4.3, `intendant`/`chief`), and `espace_admin`
     * is the Espace CHEFS D'U (§4.4, `admin`). The mapping is settled by
     * each route's `role_min`, not by the key's spelling.
     *
     * Matching is anchored on the row's first cell rather than searched
     * for anywhere in the section: « Courrier » appears in §4.5 as
     * « Courrier entrant » and « Courrier sortant », and a loose search
     * would call the Espace chefs d'U entry documented because two other
     * pages of another menu begin with the same word.
     */
    public function testEveryMenuEntryAModuleAddsHasItsRowInSectionFour(): void
    {
        $specs = $this->read('specifications.md');
        $missing = [];

        foreach ($this->menuEntries() as [$moduleId, $path, $menu, $label]) {
            $this->assertArrayHasKey(
                $menu,
                self::MENU_SECTIONS,
                "modules/{$moduleId} puts {$path} in the unknown menu '{$menu}'."
            );

            $section = self::MENU_SECTIONS[$menu];
            $listed = preg_match(
                '/^\|\s*' . preg_quote($label, '/') . '\s*(?=[(|\x{2014}])/mu',
                $this->subsection($specs, $section)
            ) === 1;

            if (!$listed) {
                $missing[] = "§{$section} « {$label} » ({$moduleId}, {$path})";
            }
        }

        sort($missing);

        // Every one of them at once, and the section it belongs in:
        // asserting per entry would report the first and hide the rest,
        // and print the whole subsection as its haystack.
        $this->assertSame(
            [],
            $missing,
            "Menu entries a module adds that specifications.md §4 does not list:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * The `### 4.x` heading and everything under it until the next one.
     */
    private function subsection(string $specs, string $number): string
    {
        $matched = preg_match(
            '/^### ' . preg_quote($number, '/') . ' [^\n]*\n(.*?)(?=^### |\z)/msu',
            $specs,
            $found
        );

        $this->assertSame(1, $matched, "specifications.md has no §{$number} subsection.");

        return $found[1];
    }

    /**
     * Every menu entry every module adds: [module id, path, menu, label].
     *
     * A route with an empty `label` is reachable but deliberately not in
     * a menu — a detail page, a fragment, a feed — and §4 lists menus.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function menuEntries(): array
    {
        $entries = [];

        foreach ((array) glob(dirname(__DIR__, 2) . '/modules/*/module.json') as $manifest) {
            $moduleId = basename(dirname((string) $manifest));
            /** @var array{routes?: list<array<string, mixed>>} $data */
            $data = json_decode((string) file_get_contents((string) $manifest), true);

            foreach ($data['routes'] ?? [] as $route) {
                $label = trim((string) ($route['label'] ?? ''));
                if ($label === '' || ($route['method'] ?? 'GET') !== 'GET') {
                    continue;
                }

                $entries[] = [
                    $moduleId,
                    (string) ($route['path'] ?? ''),
                    (string) ($route['menu'] ?? ''),
                    $label,
                ];
            }
        }

        $this->assertNotEmpty($entries, 'No module declares a menu entry.');

        return $entries;
    }

    /**
     * @return string[]
     */
    private function moduleIds(): array
    {
        $ids = [];
        foreach ((array) glob(dirname(__DIR__, 2) . '/modules/*/module.json') as $manifest) {
            $ids[] = basename(dirname((string) $manifest));
        }
        sort($ids);

        $this->assertNotEmpty($ids, 'No module manifest found.');

        return $ids;
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    /**
     * The document's numbering is a promise about its order.
     *
     * §18.5 sat before §18.4 (#488). Nothing broke, and that is the point:
     * a reader descending §18 for §18.4 walks past §18.5 and concludes
     * they have gone too far. A reference document that numbers its
     * sections has promised they follow, and the only cost of breaking
     * that promise is paid by whoever is looking something up.
     *
     * Written for the WHOLE document rather than for §18, because the one
     * out-of-order pair was found by eye during an unrelated review — and
     * an eye that found one is not a method that finds the next.
     *
     * A `bis` suffix sorts immediately after the number it extends — a
     * `3bis` follows a `3` and precedes a `4`, which is what the suffix
     * means here.
     *
     * No example here carries the section sign, and that is deliberate:
     * `Tests\Architecture\CrossReferenceResolutionRatchetTest` reads this
     * file and takes any sign-plus-number in a comment for a real
     * reference, then looks for the section it names. It rejected this
     * docblock's first draft — and then the sentence written to explain
     * the rejection, which quoted the offending example to warn about it.
     */
    public function testEverySectionAndSubsectionIsWrittenInNumericalOrder(): void
    {
        $specs = $this->read('specifications.md');
        $outOfOrder = [];
        $seen = 0;

        // Major sections: `## 18. …`
        preg_match_all('/^## (\d+)\./m', $specs, $majors);
        $previous = null;
        foreach ($majors[1] as $number) {
            $seen++;
            $current = (int) $number;
            if ($previous !== null && $current < $previous) {
                $outOfOrder[] = sprintf('§%d is written after §%d', $current, $previous);
            }
            $previous = $current;
        }

        // Subsections, compared only against their own parent: `### 18.4 …`
        preg_match_all('/^### (\d+)\.(\d+)(bis|ter)?/m', $specs, $minors, PREG_SET_ORDER);
        $previousBySection = [];
        foreach ($minors as $heading) {
            $seen++;
            $section = (int) $heading[1];
            $rank = [(int) $heading[2], $heading[3] ?? ''];
            $before = $previousBySection[$section] ?? null;

            if ($before !== null && ($rank[0] < $before[0] || ($rank[0] === $before[0] && $rank[1] < $before[1]))) {
                $outOfOrder[] = sprintf(
                    '§%d.%s%s is written after §%d.%s%s',
                    $section,
                    $rank[0],
                    $rank[1],
                    $section,
                    $before[0],
                    $before[1]
                );
            }

            $previousBySection[$section] = $rank;
        }

        $this->assertSame(
            [],
            $outOfOrder,
            "specifications.md numbers its sections, which promises they follow one another.\n"
                . "Move the block rather than renumbering it: the numbers are cited from the\n"
                . "document itself and from the code, so a swap makes every one of those\n"
                . "references wrong at once (#488).\n\n"
                . implode("\n", $outOfOrder)
        );

        // A floor under the scan: the assertion above is `assertSame([], …)`,
        // which an expression that stopped matching would satisfy for ever.
        $this->assertGreaterThanOrEqual(
            250,
            $seen,
            'the heading scan reads far less of specifications.md than the document holds, '
                . 'so the order above was checked against almost nothing'
        );
    }
}
