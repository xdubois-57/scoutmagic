<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every test class that builds a database carries `database`.
 *
 * The group's last remaining job is manual selection, and this repository
 * recommends it in the one place a newcomer cannot miss — the `SessionStart`
 * hook of a Claude Code session prints
 * `run 'vendor/bin/phpunit --group=database' for the MySQL-backed suite`
 * on every open. CI does not use it: both PHP jobs run the whole suite, and
 * `AGENTS.md` § Database says why that is deliberate.
 *
 * So the group answers one question — « which tests touch a database? » —
 * and when it was measured for issue #395 it answered it for about half of
 * them. A hundred and seventy classes built one and said nothing, three
 * modules had not a single grouped file, and the boundary of the group was
 * written down nowhere: a new file carried it or not depending on which
 * neighbour had been copied. Somebody following the hook's own advice to
 * check « the database part » of a change got half of it, and nothing in
 * the output said so.
 *
 * **ONE DIRECTION, deliberately.** This asserts that a class which builds a
 * database carries the group. It does *not* assert the converse, and two
 * classes carry the group today without building one. That asymmetry is the
 * point rather than an oversight: selecting a test that needed no database
 * costs a few milliseconds, while missing one that did is the silent
 * failure — a run that looks like « the database part, checked » and was
 * not. The cheap direction is left free; the expensive one is held.
 *
 * **INHERITANCE IS RESOLVED**, and the four `Modules\Groups\Controller`
 * classes are why. They build nothing themselves — `GroupsControllerTestCase`
 * does it for them in a `setUp()` they never override. Reading each file
 * alone would ask the group of the abstract base, which PHPUnit never runs,
 * and leave the four classes that *are* run unasked. What is followed is
 * therefore the chain of parents declared under `tests/`.
 */
final class DatabaseBackedTestsCarryTheGroupTest extends TestCase
{
    /**
     * How a test gets a database in this repository.
     *
     * Three idioms, all of them a build rather than a connection: the
     * shared helper, a bare in-memory handle, and a module helper laying
     * out its own tables. A class reaching a *real* server does it from
     * `TEST_DB_*` instead, and `DatabaseBackedTestsReallyRunTest` is the
     * guard for that half — the two do not overlap.
     */
    private const MOUNTS = [
        '/DatabaseTestHelper::createTestDatabase\s*\(/',
        '/new\s+\\\\?PDO\s*\(\s*[\'"]sqlite::memory:/',
        '/\w*TestHelper::createTables\s*\(/',
    ];

    /**
     * Every spelling counts, because the repository carries all three.
     *
     * Measured when this was written: 491 files write the attribute
     * fully-qualified, 108 import it, and 555 use the doc-comment — many
     * of them two ways at once. Recognising one of the three would make
     * this guard demand a second spelling of a file that already says it,
     * which is churn rather than a rule. New files take the qualified
     * attribute, the majority form, and it needs no import.
     */
    private const CARRIES_THE_GROUP = [
        '/#\[\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?Group\(\s*[\'"]database[\'"]\s*\)\]/',
        '/@group\s+database\b/',
    ];

    /**
     * A floor under the scan, not a measurement of it.
     *
     * The assertion below is `assertSame([], …)`, which an empty scan
     * satisfies perfectly: a detector whose patterns stopped matching —
     * the helper renamed, the idiom changed — would report a clean suite
     * for ever. Measured at 748 classes when this was written; the floor
     * sits below that with room for a file to be deleted, and far above
     * zero.
     */
    private const MINIMUM_CLASSES_BUILDING_ONE = 700;

    public function testEveryClassThatBuildsADatabaseCarriesTheGroup(): void
    {
        $classes = $this->testClasses();
        $ungrouped = [];
        $building = 0;

        foreach ($classes as $name => $class) {
            if (!$this->buildsADatabase($name, $classes)) {
                continue;
            }

            $building++;

            // The abstract bases are counted as building one — they do —
            // but never demanded to carry the group: PHPUnit runs no test
            // from them, so the group there would select nothing. What is
            // demanded is the group on each class it actually runs.
            if (!$class['abstract'] && !$class['carries']) {
                $ungrouped[] = $class['file'];
            }
        }

        sort($ungrouped);

        $this->assertSame(
            [],
            $ungrouped,
            "A test class builds a database and does not carry the `database` group.\n"
                . "`vendor/bin/phpunit --group=database` is what this repository tells a newcomer to\n"
                . "run to check the database part of a change, so a class missing from it is a class\n"
                . "that selection silently does not check. Add `#[Group('database')]` above the class.\n"
                . implode("\n", $ungrouped)
        );

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_CLASSES_BUILDING_ONE,
            $building,
            'The scan found ' . $building . ' classes building a database, fewer than the '
                . self::MINIMUM_CLASSES_BUILDING_ONE . " it is meant to see.\n"
                . 'The assertion above is satisfied by an empty scan, so this is what stands between '
                . "a detector that stopped matching and a suite that looks compliant for ever.\n"
                . 'Either a new way of building one is not in MOUNTS, or the patterns no longer match.'
        );
    }

    /**
     * The premise of the reader: a class inheriting its fixture is seen.
     *
     * Without this the guard would ask the group of the abstract base and
     * leave the runnable subclasses unasked — the very shape that put the
     * four `Modules\Groups\Controller` classes in the group by hand rather
     * than by rule.
     */
    public function testAClassInheritingItsFixtureIsStillAskedForTheGroup(): void
    {
        $classes = [
            'Tests\\Fake\\Base' => ['file' => 'Base.php', 'parent' => null, 'abstract' => true, 'carries' => false, 'mounts' => true],
            'Tests\\Fake\\Child' => ['file' => 'Child.php', 'parent' => 'Tests\\Fake\\Base', 'abstract' => false, 'carries' => false, 'mounts' => false],
            'Tests\\Other\\Orphan' => ['file' => 'Orphan.php', 'parent' => null, 'abstract' => false, 'carries' => false, 'mounts' => false],
        ];

        $this->assertTrue(
            $this->buildsADatabase('Tests\\Fake\\Child', $classes),
            'a class whose parent builds the database builds one too, or the group would be '
                . 'demanded of the abstract base PHPUnit never runs'
        );
        $this->assertFalse(
            $this->buildsADatabase('Tests\\Other\\Orphan', $classes),
            'and a class with no such parent is left alone'
        );
    }

    /** The scan reads the suite rather than an empty list. */
    public function testTheScanReadsTheSuiteRatherThanAnEmptyList(): void
    {
        $this->assertGreaterThan(
            1000,
            count($this->testClasses()),
            'the class index is far smaller than this suite, so the scan is reading almost nothing'
        );
    }

    /**
     * @param array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool}> $classes
     */
    private function buildsADatabase(string $name, array $classes): bool
    {
        // Depth-bounded rather than visited-set: a parent chain in this
        // suite is three deep at most, and a bound cannot loop for ever on
        // a cycle that a `class A extends B` typo would create.
        for ($depth = 0; $depth < 10; $depth++) {
            $class = $classes[$name] ?? null;
            if ($class === null) {
                return false;
            }
            if ($class['mounts']) {
                return true;
            }
            if ($class['parent'] === null) {
                return false;
            }
            $name = $class['parent'];
        }

        return false;
    }

    /**
     * Every class declared under `tests/`, by fully-qualified name.
     *
     * **Not by short name**, and that is not caution: twenty pairs of
     * classes under `tests/` share one. `ModuleManifestTest` alone names
     * nine different classes, one per module, and
     * `Modules\\Finance\\Service\\ReconciliationServiceTest` has a namesake
     * under `Registration`. Keyed on the short name, each collision drops
     * a class out of the index — never asked for its group, silently — 
     * which is this guard's own version of the failure it exists to
     * catch. The first draft of this class was keyed that way and the
     * assertion that was supposed to justify it found the twenty instead.
     *
     * So the parent of a class is resolved the way PHP resolves it: a
     * leading backslash is absolute, a first segment matching a `use`
     * statement is that import, and anything else is the file's own
     * namespace.
     *
     * @return array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool}>
     */
    private function testClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];

        foreach ($this->testFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (
                preg_match(
                    '/^(abstract\s+|final\s+)*class\s+(\w+)(?:\s+extends\s+(\\\\?[\w\\\\]+))?/m',
                    $source,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $found) === 1 ? $found[1] : '';
            $parent = ($matches[3] ?? '') === '' ? null : $this->resolve($matches[3], $namespace, $source);

            $mounts = false;
            foreach (self::MOUNTS as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $mounts = true;

                    break;
                }
            }

            $carries = false;
            foreach (self::CARRIES_THE_GROUP as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $carries = true;

                    break;
                }
            }

            $classes[$namespace === '' ? $matches[2] : $namespace . '\\' . $matches[2]] = [
                'file' => substr($path, strlen($root) + 1),
                'parent' => $parent,
                // A class PHPUnit runs is a concrete one whose name ends
                // in `Test`; the helpers, doubles and abstract bases beside
                // them are support, and the group on support selects nothing.
                'abstract' => str_contains($matches[0], 'abstract') || !str_ends_with($matches[2], 'Test'),
                'carries' => $carries,
                'mounts' => $mounts,
            ];
        }

        return $classes;
    }

    /**
     * A parent name as written, resolved the way PHP resolves it.
     *
     * Three rules, and no more are needed under `tests/`: a leading
     * backslash is already absolute; a first segment imported by a `use`
     * statement is that import, alias included; anything else hangs off
     * the file's own namespace. A group alias (`use A\\{B, C};`) is not
     * read, and nothing under `tests/` uses one — a parent it failed to
     * resolve simply misses the index, which costs a class its inherited
     * fixture rather than inventing one.
     */
    private function resolve(string $parent, string $namespace, string $source): string
    {
        if (str_starts_with($parent, '\\')) {
            return substr($parent, 1);
        }

        $segments = explode('\\', $parent);
        $first = $segments[0];

        if (preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/mi', $source, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $use) {
                $imported = $use[1];
                $alias = ($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\' . $imported, '\\'), 1);

                if ($alias === $first) {
                    $segments[0] = $imported;

                    return implode('\\', $segments);
                }
            }
        }

        return $namespace === '' ? $parent : $namespace . '\\' . $parent;
    }

    /**
     * @return list<string>
     */
    private function testFiles(): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
