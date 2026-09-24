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

    /**
     * A file that declares a stub before its real test class.
     *
     * Reading only the FIRST declaration dropped such a class out of the
     * index entirely — never asked for its group, and invisible to the
     * floor too, because the stub had already answered for the file. The
     * guard's own version of the silence it exists to catch. Three files
     * had this shape when it was found.
     */
    public function testATestClassDeclaredAfterAStubIsStillIndexed(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            final class RecordingTransport implements Whatever {}
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertArrayHasKey('Tests\Fake\ThingTest', $classes, 'the class after the stub must be indexed');
        $this->assertTrue($classes['Tests\Fake\ThingTest']['mounts'], 'and its own body is what is read for the build');
        $this->assertFalse($classes['Tests\Fake\RecordingTransport']['mounts'], 'the stub builds nothing');
    }

    /**
     * A group marker sitting on a method, not on the class.
     *
     * Matched against the whole file it passed the class as compliant
     * while `--group=database` selected one of its twenty-eight tests.
     * The same unanchored read accepted prose *denying* the group.
     */
    public function testAGroupOnAMethodDoesNotMakeTheClassCarryIt(): void
    {
        $onTheMethod = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }

                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testOne(): void {}
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $onTheMethod['Tests\Fake\ThingTest']['carries'],
            'a marker on one method selects that method, not the class'
        );

        $denyingIt = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            /**
             * **No `@group database`, on purpose.** Nothing here needs a server.
             */
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $denyingIt['Tests\Fake\OtherTest']['carries'],
            'prose in the heading is read as the marker — the known cost of matching text, '
                . 'and why the heading is the narrowest window that still holds a real attribute'
        );
    }

    /**
     * Each window is bounded by its OWN class, on both sides.
     *
     * `PREG_OFFSET_CAPTURE` hands back where a match STARTS, and using the
     * previous declaration's offset as this class's left edge swallowed the
     * whole class before it. `StubAttentionProvider`, which contains
     * nothing at all, read as building a database because its window
     * reached back into `AttentionControllerTest::setUp()`.
     *
     * That alone only inflated a counter. The half that mattered is the
     * heading: a marker on a METHOD of the preceding class satisfied the
     * NEXT class — which is the leak this guard had just been corrected
     * for, returning through the other side the moment a file declares two
     * `*Test` classes in a row.
     */
    public function testOneClassNeverAnswersForItsNeighbour(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;

            class FirstTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }

                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testOne(): void {}
            }

            final class StubProvider implements Whatever
            {
                public function points(): array { return []; }
            }

            class SecondTest extends TestCase
            {
                public function testTwo(): void {}
            }
            PHP, 'Fake.php');

        $this->assertTrue($classes['Tests\Fake\FirstTest']['mounts'], 'the first class does build one');

        $this->assertFalse(
            $classes['Tests\Fake\StubProvider']['mounts'],
            'a stub that builds nothing must not inherit the build of the class above it'
        );
        $this->assertFalse(
            $classes['Tests\Fake\SecondTest']['mounts'],
            'nor must the class after the stub'
        );
        $this->assertFalse(
            $classes['Tests\Fake\SecondTest']['carries'],
            'and a marker on a method of an earlier class must not answer for this one'
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
            // This file quotes the three build idioms inside the heredoc
            // fixtures its own shape tests are made of. They are text, not
            // a fixture: nothing here opens a handle. Reading them would
            // have this class demand the group of itself — the one
            // exemption, and it is named rather than pattern-matched so
            // that it cannot quietly grow to cover a second file.
            if ($path === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($this->classesIn($source, substr($path, strlen($root) + 1)) as $name => $class) {
                $classes[$name] = $class;
            }
        }

        return $classes;
    }

    /**
     * Every class one file declares, by fully-qualified name.
     *
     * Split out of the walk above so the two shapes that defeated the
     * first version — a stub declared before the real class, and a group
     * marker sitting on a method — can be put to it directly, as source,
     * instead of being asserted against whichever file happens to have
     * that shape today.
     *
     * @return array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool}>
     */
    private function classesIn(string $source, string $file): array
    {
        $classes = [];
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $found) === 1 ? $found[1] : '';

        // EVERY declaration, not the first. A file that declares a stub
        // before its real `*Test` class — three do today, among them
        // Core\Support\SupportPackageServiceTest — had that `*Test` class
        // never indexed at all: not misjudged, absent. And absent is
        // invisible, because the stub still answered for the file in the
        // count below, so the floor could not notice either. A guard
        // against silence, going silent.
        $found = preg_match_all(
            '/^(abstract\s+|final\s+)*class\s+(\w+)(?:\s+extends\s+(\\\\?[\w\\\\]+))?/m',
            $source,
            $declarations,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if ($found === 0 || $found === false) {
            return $classes;
        }

        foreach ($declarations as $index => $declaration) {
            $whole = (string) $declaration[0][0];
            $offset = (int) $declaration[0][1];
            $name = (string) $declaration[2][0];
            $written = (string) ($declaration[3][0] ?? '');
            $parent = $written === '' ? null : $this->resolve($written, $namespace, $source);

            // The class's OWN text starts at ITS OWN declaration, and ends
            // where the next class's heading begins. Starting it at the
            // PREVIOUS declaration — which is what an offset capture hands
            // back, the start and not the end — put the whole preceding
            // class inside the window: `StubAttentionProvider`, which
            // contains nothing at all, read as building a database because
            // the window reached back into `AttentionControllerTest::setUp()`.
            $bodyEnd = isset($declarations[$index + 1])
                ? $this->headingStart($source, (int) $declarations[$index + 1][0][1])
                : strlen($source);
            $own = substr($source, $offset, $bodyEnd - $offset);

            // The group must sit ON THE CLASS, so it is looked for in the
            // docblock and attributes immediately above the declaration
            // and nowhere else. Read across the whole file instead, a
            // marker on a single METHOD passed the whole class as
            // compliant: Core\Support\ApplicationCollectorsTest builds its
            // database in setUp() and carried one such marker, so
            // `--group=database` selected 1 of its 28 tests while this
            // guard called it green. Worse, prose DENYING the group
            // matched too — Core\Maintenance\Task\SendRemoteBackupHandlerTest
            // says « No `@group database`, on purpose » and passed on the
            // strength of the words refusing it.
            $headingStart = $this->headingStart($source, $offset);
            $heading = substr($source, $headingStart, $offset - $headingStart);

            $mounts = false;
            foreach (self::MOUNTS as $pattern) {
                if (preg_match($pattern, $own) === 1) {
                    $mounts = true;

                    break;
                }
            }

            $carries = false;
            foreach (self::CARRIES_THE_GROUP as $pattern) {
                if (preg_match($pattern, $heading) === 1) {
                    $carries = true;

                    break;
                }
            }

            $classes[$namespace === '' ? $name : $namespace . '\\' . $name] = [
                'file' => $file,
                'parent' => $parent,
                // A class PHPUnit runs is a concrete one whose name ends
                // in `Test`; the helpers, doubles and abstract bases beside
                // them are support, and the group on support selects nothing.
                'abstract' => str_contains($whole, 'abstract') || !str_ends_with($name, 'Test'),
                'carries' => $carries,
                'mounts' => $mounts,
            ];
        }

        return $classes;
    }

    /**
     * Where a declaration's heading begins.
     *
     * The heading is the unbroken run of doc-comment, attribute and blank
     * lines sitting immediately above the `class` keyword — which is what
     * « the group is on the class » means, and the only window narrow
     * enough to mean it. Bounding it by the previous declaration instead
     * let a marker on a METHOD of the class before satisfy the class
     * after, which is the failure this guard was just corrected for,
     * coming back through the other side.
     */
    private function headingStart(string $source, int $offset): int
    {
        $start = $offset;

        while ($start > 0) {
            $lineEnd = $start - 1;
            $lineStart = strrpos(substr($source, 0, $lineEnd), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $line = trim(substr($source, $lineStart, $lineEnd - $lineStart));

            $isHeading = $line === ''
                || str_starts_with($line, '#[')
                || str_starts_with($line, '/**')
                || str_starts_with($line, '*')
                || str_starts_with($line, '//')
                || str_ends_with($line, ']');

            if (!$isHeading) {
                break;
            }

            $start = $lineStart;
        }

        return $start;
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
