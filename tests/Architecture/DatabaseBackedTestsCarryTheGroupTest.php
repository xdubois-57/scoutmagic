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
 * and it answered it for about three quarters of them. **A hundred and
 * seventy-four classes built one and said nothing**, two whole modules
 * (`covoiturage` and `documents`) had not a single grouped file, and the
 * boundary of the group was written down nowhere: a new file carried it or
 * not depending on which neighbour had been copied. Somebody following the
 * hook's own advice to check « the database part » of a change got three
 * quarters of it, and nothing in the output said so.
 *
 * **That figure is this guard's own count against this change's base**, and
 * it is the one to quote. Issue #395 said a hundred and thirty and an
 * earlier draft of this file said a hundred and seventy; both were counted
 * by a reader that has since been corrected four times — it now indexes
 * every class in a file rather than the first, follows inheritance, reads
 * per-method attributes, and refuses a `@group` doc-comment PHPUnit 13
 * ignores. A number measured by a tool that was wrong is not a number, so
 * this one is re-derived rather than carried over, and a reviewer caught
 * the two stale copies disagreeing inside one change.
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

        // ANY class constructed with the in-memory DSN, not `PDO` spelled
        // out. What builds a database here is the DSN — `sqlite::memory:`
        // — and the class in front of it may be a subclass:
        // `Core\Database\InstrumentedPdo` is one, and `new InstrumentedPdo`
        // read as « not a build » left `InstrumentedPdoTest` and
        // `RequestTimelineTest` outside the group while both lay out
        // tables in one. Anchoring on the literal name was a bound on the
        // shape rather than on what makes the rule true — the same lesson
        // this file's siblings keep learning.
        '/new\s+\\\\?[\w\\\\]+\s*\(\s*[\'"]sqlite::memory:/',

        '/\w*TestHelper::createTables\s*\(/',
    ];

    /**
     * Where the alias is declared, when the file renames the attribute.
     *
     * `use PHPUnit\Framework\Attributes\Group as TestGroup;` is a spelling
     * PHP resolves at compile time and PHPUnit reads without hesitating —
     * and the one this guard could not see.
     */
    private const ALIASED_IMPORT = '/^use\s+PHPUnit\\\\Framework\\\\Attributes\\\\Group\s+as\s+(\w+)\s*;/m';

    /**
     * The plain import, without which the bare name means something else.
     */
    private const BARE_IMPORT = '/^use\s+PHPUnit\\\\Framework\\\\Attributes\\\\Group\s*;/m';

    /**
     * What actually puts a class in the group: the ATTRIBUTE, in whichever
     * spelling THIS FILE uses.
     *
     * **`@group database` in a doc-comment does nothing here.** This
     * repository pins `phpunit/phpunit: ^13.3`, and PHPUnit 13 reads
     * metadata from attributes only. Measured rather than inferred:
     * `Modules\Gallery\Service\StoredFileCleanerTest` carries the
     * doc-comment alone, holds six tests, and `--group=database` selects
     * **none** of them. Accepting it would have this guard certify as
     * compliant the very classes that are invisible to the command it
     * exists to make honest — issue #395's failure, reproduced by its own
     * fix. The doc-comment stays where it explains something; it is never
     * what this class reads.
     *
     * The pattern cannot be a constant, because one spelling is chosen by
     * the file itself. 491 files write the attribute fully-qualified, 108
     * import it, and one imports it UNDER ANOTHER NAME:
     * `Tests\Modules\Groups\Support\PollVoterOptionsTest` writes
     * `#[TestGroup('database')]`, which PHP resolves at compile time and
     * which `--group=database` has always selected.
     *
     * Reading only the two fixed spellings, this guard called that class
     * non-compliant — and the first fix for it ADDED a second, redundant
     * attribute beside the one already working, legal only because `Group`
     * is repeatable. That is the failure this file documents at length,
     * committed once more: a detector that narrows, and a change that
     * answers the detector instead of the rule. The alias is followed here
     * the same way `resolve()` already follows one for a parent's name.
     */
    private function carriesPattern(string $source): string
    {
        // Fully qualified, which needs no import — and the LEADING
        // BACKSLASH is not decoration: without it, `PHPUnit\Framework\…`
        // resolves inside this file's own namespace, where nothing of
        // that name lives, so PHPUnit reads no group. Accepting the
        // relative spelling would certify a class the command does not
        // select, which is this guard's own failure mode.
        $spellings = ['\\\\PHPUnit\\\\Framework\\\\Attributes\\\\Group'];

        // The bare name, and only when the file imports it. The same
        // reasoning that gates the alias below gates this: an unimported
        // `Group` is somebody else's class, and `--group=database` would
        // not select the test either. Not live today — all 118 files that
        // write the bare spelling do import it — but a detector that
        // certifies more than the command selects is the defect this
        // class exists for.
        if (preg_match(self::BARE_IMPORT, $source) === 1) {
            $spellings[] = 'Group';
        }

        if (preg_match(self::ALIASED_IMPORT, $source, $aliased) === 1) {
            $spellings[] = preg_quote($aliased[1], '/');
        }

        // **One bracket group may hold several attributes**, and the
        // marker need not be the last of them: `#[CoversClass(Foo::class),
        // Group('database')]` is legal PHP, PHPUnit honours it whatever
        // its position, and a pattern demanding `]` right after
        // `'database')` would call such a class an offender — the FALSE
        // POSITIVE direction, which this file has less practice at. So
        // other attributes may precede, and a comma may follow.
        //
        // `[^\]]*` keeps that prefix inside one bracket group. An
        // attribute whose own arguments contain `]` — `#[Foo([1, 2]),
        // Group('database')]` — is therefore still missed; no file under
        // `tests/` writes one, and widening further would mean parsing
        // rather than matching.
        return '/#\[(?:[^\]]*,\s*)?(?:' . implode('|', $spellings)
            . ')\(\s*[\'"]database[\'"]\s*\)\s*(?:,|\])/';
    }

    /**
     * The inert spelling, kept only to be refused.
     *
     * A doc-comment saying `@group database` reads like a marker and is
     * not one. It is worth a message of its own, because the file looks
     * right to a human and selects nothing.
     */
    private const INERT_DOC_COMMENT = '/@group\s+database\b/';

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
        $root = dirname(__DIR__, 2);
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
            if ($class['abstract'] || $class['carries']) {
                continue;
            }

            // The marker does not have to be on the CLASS. What the group
            // has to select is every test that needs a database, and a
            // class whose build sits inside individual test methods says
            // that by marking those methods. `Core\Import\DeskCsvParserTest`
            // is the case: four of its eighteen tests build one, each
            // carries the attribute itself, and `--group=database` selects
            // exactly those four. Demanding the class-level marker there
            // reported a compliant file as an offender.
            //
            // Only a class that builds one IN ITS OWN BODY may answer this
            // way. A class that inherits the build from a parent's setUp()
            // has it run before every one of its tests, so nothing short
            // of the class-level marker covers them.
            if ($class['mounts'] && !$this->inheritsABuild($name, $classes) && $class['uncovered'] === []) {
                continue;
            }

            // A file carrying the inert doc-comment and no attribute at
            // all deserves its own words: it LOOKS right to a reader and
            // selects nothing, which is a worse place to be than carrying
            // no marker at all. Said only when the file holds no working
            // attribute anywhere — otherwise this named the wrong defect,
            // calling `DeskCsvParserTest` doc-comment-only while it held
            // four attributes that work.
            $source = (string) file_get_contents($root . '/' . $class['file']);
            $inertOnly = preg_match(self::INERT_DOC_COMMENT, $source) === 1
                && preg_match($this->carriesPattern($source), $source) !== 1;

            $ungrouped[] = $class['file']
                . ($inertOnly ? '  (carries `@group database` only — inert under PHPUnit 13)' : '')
                . ($class['uncovered'] === []
                    ? ''
                    : '  (builds one in ' . implode(', ', $class['uncovered']) . ')');
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
            'Tests\\Fake\\Base' => [
                'file' => 'Base.php', 'parent' => null, 'abstract' => true,
                'carries' => false, 'mounts' => true, 'names' => [], 'builders' => [],
            ],
            'Tests\\Fake\\Child' => [
                'file' => 'Child.php', 'parent' => 'Tests\\Fake\\Base', 'abstract' => false,
                'carries' => false, 'mounts' => false, 'names' => [], 'builders' => [],
            ],
            'Tests\\Other\\Orphan' => [
                'file' => 'Orphan.php', 'parent' => null, 'abstract' => false,
                'carries' => false, 'mounts' => false, 'names' => [], 'builders' => [],
            ],
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
     * A class whose build sits in its own test methods, each marked.
     *
     * The rule is « every test that needs a database is selected », not
     * « every such class carries a class-level attribute ». Read as the
     * latter, this guard reported `Core\Import\DeskCsvParserTest` — four
     * of eighteen tests build one, each carries the attribute, and
     * `--group=database` selects exactly those four — as an offender, and
     * CI is where that was found rather than here.
     *
     * The loophole it must not open is the other half: a test method that
     * builds one WITHOUT the marker is still reported, and so is a build
     * anywhere that is not a marked test method.
     */
    /**
     * The attribute imported under another name.
     *
     * `use PHPUnit\Framework\Attributes\Group as TestGroup;` then
     * `#[TestGroup('database')]` is what `PollVoterOptionsTest` has always
     * written, and `--group=database` has always selected it: PHP resolves
     * the alias at compile time, so PHPUnit never sees the other name.
     *
     * This guard did. Reading two fixed spellings and no alias, it called
     * that class non-compliant, and the change that answered it added a
     * SECOND attribute next to the working one rather than widening the
     * reader — which is the exact shape of failure the rest of this file
     * is about. The alias is the third spelling, and the detector's job is
     * to know every spelling that works.
     */
    public function testTheAttributeImportedUnderAnotherNameIsStillTheMarker(): void
    {
        $aliased = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            use PHPUnit\Framework\Attributes\Group as TestGroup;

            #[TestGroup('database')]
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $aliased['Tests\Fake\ThingTest']['carries'],
            'an attribute imported under another name is the same attribute'
        );

        // And the alias is only the marker in the file that declares it:
        // another file's `TestGroup` is somebody else's class.
        $borrowed = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;

            #[TestGroup('database')]
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $borrowed['Tests\Fake\OtherTest']['carries'],
            'without the import the name means nothing, and PHPUnit would not read it either'
        );
    }

    /**
     * A subclass of PDO carrying the in-memory DSN.
     *
     * What builds a database is the DSN, not the class name in front of
     * it. Matching the literal `PDO` left `Core\Database\InstrumentedPdo`
     * — a subclass this repository wrote itself — reading as « not a
     * build », and two classes that lay out tables in one stayed outside
     * the group: `InstrumentedPdoTest` and `RequestTimelineTest`.
     */
    public function testAnInMemoryHandleBuiltThroughASubclassIsStillABuild(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                private function pdo(): \PDO
                {
                    $pdo = new InstrumentedPdo('sqlite::memory:');
                    $pdo->exec('CREATE TABLE t (id INT)');

                    return $pdo;
                }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $classes['Tests\Fake\ThingTest']['mounts'],
            'the DSN is what builds a database; the class in front of it may be a subclass'
        );
    }

    /**
     * A docblock QUOTING the attribute is not the attribute.
     *
     * The heading is doc-comment and attribute together — the right window
     * for « the group is on the class » — but the marker was looked for
     * across the whole of it, so a sentence naming
     * `#[Group('database')]` passed for one.
     * `Core\Mail\Feedback\Bounce\BounceSendReceiptMysqlTest` writes that
     * sentence today, harmlessly, because it also carries the real
     * attribute — which is how a defect like this waits for the file that
     * writes the sentence and nothing else.
     *
     * **Both fixtures import `Group`, and that import is what makes this
     * test able to fail at all.** A reviewer showed that the first version
     * could not: it quoted the BARE spelling in prose without importing
     * `Group`, and carriesPattern() only puts the bare spelling in the
     * pattern for a file that imports it — so the sentence could not have
     * matched with or without the stripping, and deleting the stripping
     * left this test green. The method-level sibling below had already been
     * corrected for exactly this, by quoting the fully-qualified spelling
     * instead; here the fixture takes the other way out and imports, which
     * is also the shape `BounceSendReceiptMysqlTest` really has.
     */
    public function testProseQuotingTheAttributeInADocblockIsNotTheAttribute(): void
    {
        $quotingIt = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            use PHPUnit\Framework\Attributes\Group;
            /**
             * Carrying `#[Group('database')]` is not what makes a test
             * reach MySQL — the connection below is.
             */
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $quotingIt['Tests\Fake\ThingTest']['carries'],
            'a sentence about the marker is not the marker, however exactly it spells it'
        );

        // And the real thing, one line below the same sentence, still is —
        // written the way a file that imports `Group` writes it.
        $bothAtOnce = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            use PHPUnit\Framework\Attributes\Group;
            /**
             * Carrying `#[Group('database')]` is not what makes a test
             * reach MySQL — the connection below is.
             */
            #[Group('database')]
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $bothAtOnce['Tests\Fake\OtherTest']['carries'],
            'and stripping the prose must not take the attribute with it'
        );
    }

    /**
     * The same rule at method level, which had been left out.
     *
     * The class heading was comment-stripped one review round earlier;
     * the method heading was not, so a docblock above a `test*` method
     * quoting the attribute in prose exempted that method from being
     * reported — a build hidden behind a sentence.
     *
     * The prose quotes the FULLY-QUALIFIED spelling deliberately. A first
     * draft quoted the bare one, and passed against the unstripped
     * heading too: the bare name is only in the pattern when the file
     * imports it, and this fixture does not — so the sentence proved
     * nothing about comments. A test that cannot fail is the failure this
     * whole file is about, met once more in the test written for it.
     */
    public function testProseAboveAMethodIsNotTheMarkerEither(): void
    {
        $quotingIt = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                /**
                 * No `#[\PHPUnit\Framework\Attributes\Group('database')]`
                 * here: this builds nothing that needs MySQL.
                 */
                public function testBuildsOneAnyway(): void { $pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertSame(
            ['testBuildsOneAnyway()'],
            $quotingIt['Tests\Fake\ThingTest']['uncovered'],
            'a sentence above a method is not a marker on it, however exactly it spells one'
        );

        // And the real attribute, written under the same sentence, still
        // covers the method — stripping the prose must not take it too.
        $bothAtOnce = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class OtherTest extends TestCase
            {
                /**
                 * No `#[\PHPUnit\Framework\Attributes\Group('database')]`
                 * here — says the sentence.
                 */
                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testBuildsOne(): void { $pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertSame([], $bothAtOnce['Tests\Fake\OtherTest']['uncovered']);
    }

    /**
     * The marker need not be alone in its bracket group, nor last in it.
     *
     * `#[CoversClass(Foo::class), Group('database')]` is legal PHP and
     * PHPUnit honours it whatever the position; a pattern demanding `]`
     * right after `'database')` would report such a class as an offender.
     * That is the FALSE POSITIVE direction — the guard accusing a file
     * that the command does select — which this file has less practice at
     * than the other, and which a contributor would answer by adding a
     * second attribute rather than by doubting the guard. It has happened
     * once already, in this PR's own history, over the aliased import.
     */
    public function testTheMarkerIsFoundBesideOtherAttributes(): void
    {
        foreach ([
            'alone' => "#[\\PHPUnit\\Framework\\Attributes\\Group('database')]",
            'first of two' => "#[\\PHPUnit\\Framework\\Attributes\\Group('database'), \\PHPUnit\\Framework\\Attributes\\Small]",
            'last of two' => "#[\\PHPUnit\\Framework\\Attributes\\Small, \\PHPUnit\\Framework\\Attributes\\Group('database')]",
            'on its own line beside another group' => "#[\\PHPUnit\\Framework\\Attributes\\Small]\n            #[\\PHPUnit\\Framework\\Attributes\\Group('database')]",
        ] as $shape => $attributes) {
            $classes = $this->classesIn(<<<PHP
                <?php
                namespace Tests\\Fake;
                {$attributes}
                class ThingTest extends TestCase
                {
                    protected function setUp(): void { \$this->pdo = DatabaseTestHelper::createTestDatabase(); }
                }
                PHP, 'Fake.php');

            $this->assertTrue(
                $classes['Tests\\Fake\\ThingTest']['carries'],
                'the marker written ' . $shape . ' is the same marker'
            );
        }

        // And a group of attributes holding no marker still carries none.
        $without = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            #[\PHPUnit\Framework\Attributes\Small, \PHPUnit\Framework\Attributes\Group('slow')]
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse($without['Tests\Fake\OtherTest']['carries']);
    }

    /**
     * A name nobody imported is somebody else's class.
     *
     * `#[Group('database')]` selects nothing unless the file imports
     * `PHPUnit\Framework\Attributes\Group`, and a fully-qualified
     * spelling without its leading backslash resolves inside the file's
     * own namespace, where no such class lives. Certifying either would
     * be this guard declaring compliant a class the command does not
     * select — the shape of the very defect it exists for. The alias was
     * already gated on its import; these two were not.
     */
    public function testTheBareNameIsTheMarkerOnlyWhenTheFileImportsIt(): void
    {
        $imported = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            use PHPUnit\Framework\Attributes\Group;
            #[Group('database')]
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $imported['Tests\Fake\ThingTest']['carries'],
            'the bare name, imported, is how 118 files in this repository spell the marker'
        );

        $notImported = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            #[Group('database')]
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $notImported['Tests\Fake\OtherTest']['carries'],
            'an unimported Group is another class, and --group=database would select nothing'
        );

        $relative = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            #[PHPUnit\Framework\Attributes\Group('database')]
            class ThirdTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $relative['Tests\Fake\ThirdTest']['carries'],
            'without its leading backslash that name resolves inside Tests\Fake, where it does not exist'
        );
    }

    public function testAClassMarkingEachBuildingMethodNeedsNoClassLevelMarker(): void
    {
        $eachMarked = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                public function testNeedsNothing(): void {}

                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testNeedsOne(): void { $pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertSame(
            [],
            $eachMarked['Tests\Fake\ThingTest']['uncovered'],
            'a build inside a test method is covered by the marker on that method'
        );

        $oneBare = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testNeedsOne(): void { $pdo = DatabaseTestHelper::createTestDatabase(); }

                public function testAlsoNeedsOne(): void { $pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        $this->assertSame(
            ['testAlsoNeedsOne()'],
            $oneBare['Tests\Fake\ThingTest']['uncovered'],
            'the unmarked builder is named, and its marked neighbour does not answer for it'
        );

        // A build outside a test method is never answerable per method:
        // setUp() runs before every test, and a private helper is reached
        // from whichever tests call it — a set this reader cannot see.
        $inSetUp = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }

                #[\PHPUnit\Framework\Attributes\Group('database')]
                public function testOne(): void {}
            }
            PHP, 'Fake.php');

        $this->assertSame(
            ['setUp()'],
            $inSetUp['Tests\Fake\ThingTest']['uncovered'],
            'a build in setUp() reaches every test, so only the class-level marker covers it'
        );
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

        // **It spells the ATTRIBUTE, and it imports `Group`** — both on
        // purpose, and a reviewer had to say why. The first version of this
        // fixture wrote « No `@group database`, on purpose », which carries
        // no `#[` at all: since carriesPattern() needs a literal `#[`, that
        // assertion was false whatever withoutComments() did, so it never
        // exercised the stripping it claimed to. Its failure message even
        // said « even one spelling the attribute exactly » about a fixture
        // that did not. The same defect as its sibling above, and found the
        // same way.
        $denyingIt = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            use PHPUnit\Framework\Attributes\Group;
            /**
             * **No `#[Group('database')]`, on purpose.** Nothing here needs a server.
             */
            class OtherTest extends TestCase
            {
                protected function setUp(): void { $this->pdo = DatabaseTestHelper::createTestDatabase(); }
            }
            PHP, 'Fake.php');

        // This used to be the opposite assertion, and the docblock called
        // it a known cost: prose naming the marker read as the marker.
        //
        // Reading only the attribute closed HALF of that, and the message
        // here claimed the whole — « a sentence naming the group can no
        // longer pass for a marker ». It could, as long as the sentence
        // spelled the ATTRIBUTE rather than the doc-comment, which is what
        // `BounceSendReceiptMysqlTest` writes. A guard overstating what it
        // holds is worse than one holding less, because somebody believes
        // it. The heading now has its comment lines taken out, which is
        // what makes the claim true — see withoutComments().
        $this->assertFalse(
            $denyingIt['Tests\Fake\OtherTest']['carries'],
            'prose in the heading is prose: comments are taken out before the marker is looked '
                . 'for, so a sentence naming the group — even one spelling the attribute exactly, '
                . 'even one refusing it — cannot pass for a marker'
        );
    }

    /**
     * **Composition reaches a database too, and `extends` never sees it.**
     *
     * Eight rounds into this guard, a reviewer pointed out that
     * `inheritsABuild()` walks the parent chain and nothing else. Three
     * classes in this suite touched an in-memory database on every run by
     * BUILDING a support class that opens one, carried no marker, and were
     * called green — the guard's own failure mode, once more. A fourth,
     * `Core\Maintenance\Remote\RemotePassphraseTest`, came out of the fix
     * and was in nobody's list: it names `RefusingSettingService`, whose
     * PARENT's constructor opens the connection.
     *
     * The verdict already existed — the detector classified those helpers
     * as building one — and went nowhere. That is the shape worth
     * remembering: not a detector that failed to see, one whose answer was
     * never asked for.
     */
    public function testAClassThatBuildsAHelperWhichOpensADatabaseIsHeldToTheRule(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class Store
            {
                public function __construct() { $this->pdo = new \PDO('sqlite::memory:'); }
            }
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->store = new Store(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $classes['Tests\Fake\ThingTest']['mounts'],
            'it builds nothing in its own body — which is exactly why extends and mounts both miss it'
        );
        $this->assertTrue(
            $this->buildsADatabase('Tests\Fake\ThingTest', $classes),
            'the class hands the work to one that opens a database, so it reaches one'
        );
    }

    /**
     * **And the method named is the method read**, which is what keeps this
     * from being noise.
     *
     * A first version asked only « does this class open one anywhere? » and
     * reported nine classes where four were the point: every test calling
     * `AttestationsTestHelper::writeTemporaryPdf()` — which touches no
     * database — was named an offender because that helper's
     * `createTables()` does. Six false positives, on a guard whose own
     * history says a false positive reads as an order rather than as a
     * defect.
     */
    public function testCallingAnotherMethodOfTheSameHelperIsNotBuildingOne(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class Helper
            {
                public static function tables(): void { $pdo = new \PDO('sqlite::memory:'); }
                public static function aTemporaryFile(): string { return '/tmp/x'; }
            }
            class ThingTest extends TestCase
            {
                public function testSomething(): void { $path = Helper::aTemporaryFile(); }
            }
            class OtherTest extends TestCase
            {
                protected function setUp(): void { Helper::tables(); }
            }
            PHP, 'Fake.php');

        $this->assertFalse(
            $this->buildsADatabase('Tests\Fake\ThingTest', $classes),
            'the method it calls opens nothing, so naming the helper is not enough'
        );
        $this->assertTrue(
            $this->buildsADatabase('Tests\Fake\OtherTest', $classes),
            'and the one that calls the method which does opens one'
        );
    }

    /**
     * A method handing it to a SIBLING method counts, within one class.
     *
     * `Core\Mail\Template\EmailTemplateRendererFactory::shippedOnlyForModule()`
     * is the real case: it opens nothing, it calls `self::emptyStore()`,
     * and two test classes reach a database through that call alone.
     * Without this pass the fix above found four of the six and missed
     * those two.
     */
    public function testAMethodThatDelegatesToASiblingWhichOpensOneCountsToo(): void
    {
        $classes = $this->classesIn(<<<'PHP'
            <?php
            namespace Tests\Fake;
            class Factory
            {
                public static function forModule(): object { return self::emptyStore(); }
                private static function emptyStore(): \PDO { return new \PDO('sqlite::memory:'); }
            }
            class ThingTest extends TestCase
            {
                protected function setUp(): void { $this->renderer = Factory::forModule(); }
            }
            PHP, 'Fake.php');

        $this->assertTrue(
            $this->buildsADatabase('Tests\Fake\ThingTest', $classes),
            'the method it calls opens one by way of its own sibling'
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
     * Whether a PARENT builds the database, rather than this class.
     *
     * The distinction decides which marker is enough. A build in a
     * parent's `setUp()` runs before every test the child declares, so
     * only the class-level marker selects them all; a build the class
     * performs inside its own test methods is covered by marking those.
     *
     * @param array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool, names: list<string>, uncovered: list<string>}> $classes
     */
    private function inheritsABuild(string $name, array $classes): bool
    {
        $parent = $classes[$name]['parent'] ?? null;

        return $parent !== null && $this->buildsADatabase($parent, $classes);
    }

    /**
     * @param array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool, names: list<string>, uncovered: list<string>}> $classes
     */
    private function buildsADatabase(string $name, array $classes, array $seen = []): bool
    {
        // A visited set now, not a depth bound: composition has no shape to
        // bound — a support class may name another, which may name a third
        // — and two classes naming each other would loop for ever.
        if (isset($seen[$name])) {
            return false;
        }
        $seen[$name] = true;

        $class = $classes[$name] ?? null;
        if ($class === null) {
            return false;
        }
        if ($class['mounts']) {
            return true;
        }
        if ($class['parent'] !== null && $this->buildsADatabase($class['parent'], $classes, $seen)) {
            return true;
        }

        return $this->namesABuilder($class, $classes, $seen);
    }

    /**
     * Does this class hand the work to something that builds a database?
     *
     * **`extends` is not the only way to reach one**, and a reviewer had to
     * point that out after eight rounds on this guard. Three classes in this
     * suite touch an in-memory database on every run while writing none of
     * the three idioms and inheriting nothing:
     *
     * - `Core\Maintenance\Remote\RemoteRetentionTest` builds an
     *   `InMemorySettingService`, whose constructor opens one;
     * - `Modules\SosStaff\Service\RedirectServiceTest` and
     *   `Modules\Rental\Service\RentalBookingMailServiceTest` both call
     *   `EmailTemplateRendererFactory::shippedOnlyForModule()`, which opens
     *   one.
     *
     * The guard's own detector already classified both support classes as
     * building a database. What it never did was carry that answer to the
     * classes composing them — so the verdict existed and went nowhere,
     * which is this guard's failure mode committed once more.
     *
     * **Only classes declared under `tests/` count**, which is the line
     * that keeps this from becoming a scan of the whole product: a
     * repository or a service under `core/` opens the connection the
     * application gives it, and following those would report every
     * controller test in the suite.
     *
     * @param array{names: list<string>} $class
     * @param array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool, names: list<string>, uncovered: list<string>}> $classes
     * @param array<string, true> $seen
     */
    private function namesABuilder(array $class, array $classes, array $seen): bool
    {
        foreach ($class['names'] as $call) {
            $split = strrpos($call, '::');
            if ($split === false) {
                continue;
            }

            $named = substr($call, 0, $split);
            $method = substr($call, $split + 2);

            if ($this->methodBuildsADatabase($named, $method, $classes, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does `$named::$method` reach a database?
     *
     * **The method matters, and getting that wrong was measured rather than
     * imagined.** A first version asked only « does this class build one
     * anywhere? », and reported nine classes where three were the point:
     * `AttestationsTestHelper` mounts in `createTables()`, so every test
     * calling its `writeTemporaryPdf()` — which touches no database at all
     * — was named an offender. Six false positives against three real
     * misses, on a guard whose own PR documents that a false positive reads
     * as an order rather than as a defect.
     *
     * Inherited methods count, so a helper extending one that mounts is not
     * a way through. What is NOT followed is a second hop: a method that
     * merely calls another class's mounting method is invisible here.
     * Nothing in this suite is shaped that way today, and going further
     * means resolving calls rather than matching them.
     *
     * @param array<string, array{parent: ?string, builders: list<string>, ...}> $classes
     * @param array<string, true> $seen
     */
    private function methodBuildsADatabase(
        string $named,
        string $method,
        array $classes,
        array $seen
    ): bool {
        for ($depth = 0; $depth < 10; $depth++) {
            $class = $classes[$named] ?? null;
            if ($class === null) {
                return false;
            }
            if (in_array($method, $class['builders'], true)) {
                return true;
            }
            if ($class['parent'] === null) {
                return false;
            }
            $named = $class['parent'];
        }

        return false;
    }

    /**
     * The classes a body BUILDS or CALLS, resolved as PHP resolves them.
     *
     * `new X(` and `X::` only — the two ways a body reaches another class's
     * code. A type in a property declaration or a parameter is not one: it
     * says what may be handed in, not what is constructed, and counting it
     * would make every test naming a repository type look like a builder.
     *
     * Fed the COMMENT-STRIPPED body, for the reason this file has had to
     * learn twice: a docblock naming a class is prose.
     *
     * @return list<string>
     */
    private function namedCalls(string $body, string $namespace, string $source): array
    {
        // `new X(` runs X's constructor; `X::m(` runs X::m. Nothing else is
        // resolvable from text: `$service->save()` names no class, and a
        // type in a property or a parameter says what MAY be handed in
        // rather than what is built.
        preg_match_all(
            '/new\s+(\\\\?[A-Z]\w*(?:\\\\\w+)*)\s*\(/',
            $body,
            $built
        );
        preg_match_all(
            '/(\\\\?[A-Z]\w*(?:\\\\\w+)*)::(\w+)\s*\(/',
            $body,
            $called,
            PREG_SET_ORDER
        );

        $calls = [];

        foreach ($built[1] as $written) {
            $calls[$this->resolve($written, $namespace, $source) . '::__construct'] = true;
        }

        foreach ($called as $call) {
            $calls[$this->resolve($call[1], $namespace, $source) . '::' . $call[2]] = true;
        }

        return array_keys($calls);
    }

    /**
     * The methods of one class body that build a database, by name.
     *
     * The same split as buildersWithoutTheGroup(), asked a different
     * question: not « is this method marked? » but « does this method reach
     * a database? ». It is what makes the composition step above precise
     * enough to be worth having — see namesABuilder().
     *
     * @return list<string>
     */
    private function mountingMethods(string $own): array
    {
        $found = preg_match_all(
            '/^[ \t]*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/m',
            $own,
            $methods,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if ($found === 0 || $found === false) {
            return [];
        }

        $bodies = [];

        foreach ($methods as $index => $method) {
            $offset = (int) $method[0][1];
            $end = isset($methods[$index + 1])
                ? $this->headingStart($own, (int) $methods[$index + 1][0][1])
                : strlen($own);
            $bodies[(string) $method[1][0]] = substr($own, $offset, $end - $offset);
        }

        $mounting = [];

        foreach ($bodies as $name => $body) {
            foreach (self::MOUNTS as $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    $mounting[$name] = true;

                    break;
                }
            }
        }

        // **And a method that hands it to a sibling.** Measured, not
        // imagined: `EmailTemplateRendererFactory::shippedOnlyForModule()`
        // builds nothing itself, it calls `self::emptyStore()` — and two
        // test classes reach a database through exactly that call. Without
        // this pass the composition step above found the four that name a
        // mounting method directly and missed those two, which is half the
        // finding it exists to answer.
        //
        // Within one class only, and until the set stops growing. A hop
        // ACROSS classes is deliberately not followed: see
        // methodBuildsADatabase() for what that costs and why it is not
        // paid here.
        for ($pass = 0; $pass < 10; $pass++) {
            $grew = false;

            foreach ($bodies as $name => $body) {
                if (isset($mounting[$name])) {
                    continue;
                }

                foreach (array_keys($mounting) as $builder) {
                    $calls = '/(?:self::|static::|\$this->)' . preg_quote((string) $builder, '/') . '\s*\(/';
                    if (preg_match($calls, $body) === 1) {
                        $mounting[$name] = true;
                        $grew = true;

                        break;
                    }
                }
            }

            if (!$grew) {
                break;
            }
        }

        return array_keys($mounting);
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
     * @return array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool, uncovered: list<string>}>
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
     * @return array<string, array{file: string, parent: ?string, abstract: bool, carries: bool, mounts: bool, uncovered: list<string>}>
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
            // said « No `@group database`, on purpose » and passed on the
            // strength of the words refusing it. That sentence is gone
            // from that file now: it was wrong about what the group means,
            // and it sat beside the attribute this change gave the class.
            $headingStart = $this->headingStart($source, $offset);
            $heading = self::withoutComments(substr($source, $headingStart, $offset - $headingStart));

            $mounts = false;
            foreach (self::MOUNTS as $pattern) {
                if (preg_match($pattern, $own) === 1) {
                    $mounts = true;

                    break;
                }
            }

            // **And what this class HANDS THE WORK TO.** A class can reach
            // a database without writing one of the three idioms itself,
            // by building a support class that does — which is composition,
            // and `extends` never sees it. Three classes were in exactly
            // that position while this guard called them green; see
            // namesABuilder().
            $names = $this->namedCalls(self::withoutComments($own), $namespace, $source);
            $builders = $this->mountingMethods($own);

            $carries = false;
            if (preg_match($this->carriesPattern($source), $heading) === 1) {
                $carries = true;
            }

            $classes[$namespace === '' ? $name : $namespace . '\\' . $name] = [
                'file' => $file,
                'parent' => $parent,
                'names' => $names,
                'builders' => $builders,
                'uncovered' => $carries ? [] : $this->buildersWithoutTheGroup($own, $this->carriesPattern($source)),
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
     * The methods of one class body that build a database without saying so.
     *
     * A build inside a `test*` method concerns that test alone, and the
     * attribute on that method selects it — which is how
     * `Core\Import\DeskCsvParserTest` is written, and correctly so.
     *
     * A build ANYWHERE ELSE in the body is not answerable that way and is
     * always reported: `setUp()` runs before every test, and a private
     * helper is reached from whichever tests call it — a set this reader
     * cannot see and will not guess at. Both are named here under their
     * own method name, so the demand stays the class-level marker, and
     * the message says which method put it there.
     *
     * @return list<string> the offending method names, empty when covered
     */
    private function buildersWithoutTheGroup(string $own, string $carriesPattern): array
    {
        $found = preg_match_all(
            '/^[ \t]*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/m',
            $own,
            $methods,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if ($found === 0 || $found === false) {
            return [];
        }

        $uncovered = [];

        foreach ($methods as $index => $method) {
            $offset = (int) $method[0][1];
            $name = (string) $method[1][0];

            $end = isset($methods[$index + 1])
                ? $this->headingStart($own, (int) $methods[$index + 1][0][1])
                : strlen($own);
            $body = substr($own, $offset, $end - $offset);

            $builds = false;
            foreach (self::MOUNTS as $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    $builds = true;

                    break;
                }
            }

            if (!$builds) {
                continue;
            }

            // Comment-stripped, exactly as the class-level check is, and
            // for the same reason: a docblock ABOVE A METHOD that merely
            // quotes the attribute — or denies it — would otherwise exempt
            // a database-building method from being reported. The class
            // level learned this one review round earlier
            // (testProseQuotingTheAttributeInADocblockIsNotTheAttribute);
            // this window had been left raw.
            $headingStart = $this->headingStart($own, $offset);
            $heading = self::withoutComments(substr($own, $headingStart, $offset - $headingStart));

            if (str_starts_with($name, 'test') && preg_match($carriesPattern, $heading) === 1) {
                continue;
            }

            $uncovered[] = $name . '()';
        }

        return $uncovered;
    }

    /**
     * A heading with its comment lines taken out.
     *
     * The heading is doc-comment AND attribute — that is what makes it the
     * right window for « the group is on the class ». But the marker was
     * then looked for across the whole of it, so a docblock QUOTING
     * `#[Group('database')]` in a sentence was indistinguishable from the
     * class carrying it. `Core\Mail\Feedback\Bounce\BounceSendReceiptMysqlTest`
     * writes exactly that sentence — harmlessly, because it also carries
     * the real attribute, which is how a defect like this waits.
     *
     * The failure message a few lines down claims « a sentence naming the
     * group — even one refusing it — can no longer pass for a marker ».
     * That was true of the inert `@group database` spelling and false of
     * this one, and a guard that overstates what it holds is worse than
     * one that holds less: somebody believes it.
     *
     * Comments go; attributes stay.
     */
    private static function withoutComments(string $heading): string
    {
        $kept = [];

        foreach (explode("\n", $heading) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//')) {
                continue;
            }

            // A docblock's opening, body and close all read as comment
            // here; an attribute never starts with any of them.
            if (str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
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
