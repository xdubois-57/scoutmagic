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
}
