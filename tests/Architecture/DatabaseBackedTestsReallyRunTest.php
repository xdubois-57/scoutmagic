<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The tests that need a real server really got one.
 *
 * Twenty-four test classes open their own MySQL/MariaDB connection from
 * `TEST_DB_*` and `markTestSkipped()` when it is refused. That is right on
 * a laptop with nothing on 3306 and wrong everywhere else, and three of
 * those classes already say so in their own words —
 * `Core\Scheduler\LivePrefixGuardOnMysqlTest`,
 * `Core\Help\Discovery\SeenTopicUpsertOnMysqlTest` and
 * `Modules\SupportDashboard\RateLimitReservationLockTest`:
 *
 * > Skip only where no server was ever configured — a developer's laptop
 * > with nothing on 3306. Where TEST_DB_* IS set, the configuration is a
 * > promise that a server is there, and a bad password or a refused
 * > connection is a failure to report, not a test to quietly drop.
 *
 * The other twenty-one skip either way. Measured on this repository, with
 * `TEST_DB_HOST` set and pointing at nothing: **142 tests skipped where 3
 * normally do**, and only 28 of them were loud about it — the three classes
 * above. A hundred and thirty-nine stopped being verified in silence, which
 * is `docs/quality-pipeline.md` § The skip that looks like a pass, one level
 * up: the suite proves less than it looks and nothing in its output says so.
 *
 * **Why this guard is a connection rather than a count of skips.** Catching
 * the skips themselves would need the run's own events, which a test cannot
 * read. It does not have to. Every one of those classes skips on the same
 * thing — a connection that was refused — so « did a database-motivated skip
 * happen? » is « could the connection have been made? », and that question a
 * test can ask directly. The second test below is what keeps the two
 * equivalent: a skip that named the database from a file that never opens a
 * connection would be one this guard does not cover, and there are none.
 */
final class DatabaseBackedTestsReallyRunTest extends TestCase
{
    /**
     * The words that make a skip message a database one. Matched against
     * the whole argument text of `markTestSkipped()`, case-insensitively.
     */
    private const DATABASE_WORDS = '/Database|MySQL|MariaDB|TEST_DB_/i';

    /** The call whose argument says why a test dropped out of the run. */
    private const SKIP_CALL = 'markTestSkipped';

    /**
     * On a runner, a refused connection is a failure to report.
     *
     * `CI` as well as `TEST_DB_HOST`, and for the case that motivates the
     * whole guard: a job that LOST its `TEST_DB_*` has no `TEST_DB_HOST`
     * to read, so keying on that variable alone would let exactly the
     * regression this exists to catch pass as "a laptop". GitHub sets
     * `CI=true` on every runner, and both PHP jobs in
     * `.github/workflows/checks.yml` export the five variables.
     */
    public function testWhereTheEnvironmentPromisesADatabaseOneReallyAnswers(): void
    {
        // Falsy, not `=== false`, and the difference is not cosmetic: the
        // twenty-four classes read `getenv('TEST_DB_HOST') ?: '127.0.0.1'`,
        // so an exported-but-empty TEST_DB_HOST sends them to the default
        // host and they connect. A guard that treated the same value as a
        // promise of a server at the empty string would go red where every
        // one of them is green, which is the opposite of mirroring them.
        $configuredHost = getenv('TEST_DB_HOST') ?: '';
        if ($configuredHost === '' && getenv('CI') === false) {
            $this->markTestSkipped(
                'Neither TEST_DB_HOST nor CI is set: nothing here promised a server, '
                    . 'so the twenty-four database-backed classes are entitled to skip. '
                    . 'See CONTRIBUTING.md § Development setup.'
            );
        }

        // The same five variables, the same defaults and the same DSN shape
        // as Tests\Core\Mail\Feedback\Bounce\BounceSendReceiptMysqlTest and
        // its twenty-three neighbours. Reading them differently here would
        // make this guard answer a question none of them asks.
        $host = $configuredHost ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $database = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        // One assertion, and it carries the whole verdict. The engine needs
        // no assertion of its own: the DSN says `mysql:`, which pdo_sqlite
        // cannot answer and a missing pdo_mysql cannot reach, so « a server
        // answered this » already means « MySQL or MariaDB answered this ».
        $refusal = null;
        try {
            new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $refusal = $e->getMessage();
        }

        $this->assertNull(
            $refusal,
            sprintf(
                "No server answered at %s:%d/%s as '%s', and this environment said there would be one "
                    . "(TEST_DB_HOST %s, CI %s).\n"
                    . "Every database-backed test is skipping itself right now, and the suite will still "
                    . "report green: that is the whole reason this test exists.\n"
                    . 'The driver said: %s',
                $host,
                $port,
                $database,
                $user,
                $configuredHost === '' ? 'unset or empty' : 'set',
                getenv('CI') === false ? 'unset' : 'set',
                (string) $refusal
            )
        );
    }

    /**
     * Nothing skips over the database outside the classes that connect.
     *
     * This is what makes the reasoning above sound rather than merely
     * plausible. If a class started saying « Database not available »
     * without ever having opened a connection from `TEST_DB_*`, the test
     * above could pass while that class skipped anyway — the equivalence
     * between « a skip happened » and « the connection failed » would be
     * broken, and silently.
     */
    public function testEveryDatabaseMotivatedSkipComesFromAFileThatOpensTheConnection(): void
    {
        $uncovered = [];

        foreach ($this->testFiles() as $path) {
            $source = (string) file_get_contents($path);
            // `getenv('TEST_DB_HOST')` rather than the bare name: a file
            // that merely says TEST_DB_HOST — in a comment, in a message,
            // in a docblock like this one — reaches no server, and
            // exempting it would be the hole this test exists to close.
            // All twenty-five files carrying the name read it this way
            // today, so the tightening changes no verdict; what it
            // removes is a way for a future one to slip through.
            if (preg_match('/getenv\\(\\s*[\'"]TEST_DB_HOST[\'"]\\s*\\)/', $source) === 1) {
                continue;
            }

            foreach ($this->skipArguments($source) as $argument) {
                if (preg_match(self::DATABASE_WORDS, $argument) === 1) {
                    $uncovered[] = substr($path, strlen(dirname(__DIR__, 2)) + 1) . ' — « ' . $argument . ' »';
                }
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "A skip blames the database in a file that never reads TEST_DB_* to reach one.\n"
                . "Whatever that skip really depends on, testWhereTheEnvironmentPromisesADatabaseOneReallyAnswers() "
                . "above does not cover it, so it can fire on a runner without anything going red.\n"
                . implode("\n", $uncovered)
        );
    }

    /**
     * The premise of the test above: there are test files to read at all.
     * A glob that stopped matching would make it pass over nothing.
     */
    /**
     * And the other half: no class decides this for itself any more.
     *
     * The guard above asks « could the connection have been made? », which
     * is enough while every database-motivated skip is a refused
     * connection. What it cannot see is a class that reaches the same
     * decision by its own reasoning and gets it wrong — and twenty-four
     * did, each writing `markTestSkipped('Database connection not
     * available: …')` with nothing in front of it.
     *
     * They all call `DatabaseTestHelper::skipOnlyWhenNoServerWasPromised()`
     * now, which is the one place the rule is stated. This keeps it the one
     * place: a database-motivated `markTestSkipped()` written anywhere else
     * is refused, so the twenty-fifth class cannot quietly re-decide it.
     *
     * The helper itself is the exemption, and it is the only one.
     */
    public function testNoTestDecidesADatabaseSkipForItself(): void
    {
        $root = dirname(__DIR__, 2);
        // The helper is where the rule lives, and this file is where the
        // rule is explained — it quotes the very call it forbids, so
        // reading itself reports its own prose as an offence.
        $exempt = [
            'tests/DatabaseTestHelper.php',
            'tests/Architecture/DatabaseBackedTestsReallyRunTest.php',
        ];
        $offenders = [];

        foreach ($this->testFiles() as $file) {
            $relative = substr($file, strlen($root) + 1);
            if (in_array($relative, $exempt, true)) {
                continue;
            }

            $source = (string) file_get_contents($file);
            foreach ($this->skipCalls($source) as ['line' => $line, 'argument' => $argument]) {
                if (preg_match(self::DATABASE_WORDS, $argument) !== 1) {
                    continue;
                }

                $offenders[] = $relative . ':' . $line . ' — ' . $argument;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A skip because the database is unreachable is decided in ONE place —\n"
            . "DatabaseTestHelper::skipOnlyWhenNoServerWasPromised() — because the decision is\n"
            . "not « is there a server? » but « was one promised? », and twenty-four classes\n"
            . "got it wrong by answering the first (issue #393). Call the helper instead:\n"
            . "it skips on a laptop and throws anywhere TEST_DB_* or CI is set.\n  "
            . implode("\n  ", $offenders)
        );
    }

    public function testTheScanReadsTheSuiteRatherThanAnEmptyList(): void
    {
        $this->assertGreaterThan(1000, count($this->testFiles()));
    }

    /**
     * The rule itself, exercised rather than read.
     *
     * Everything above scans source text: it says where the decision is
     * allowed to be taken, never what the decision IS. So the three
     * branches the whole of issue #393 turns on — nothing promised, a host
     * promised, a runner promised — are asserted here, on the helper, with
     * the environment restored whatever happens.
     */
    public function testTheHelperAsksWhatWasPromisedRatherThanWhatAnswers(): void
    {
        $host = getenv('TEST_DB_HOST');
        $ci = getenv('CI');

        try {
            putenv('TEST_DB_HOST');
            putenv('CI');
            $this->assertSame(
                'skipped',
                $this->outcomeOfTheHelper(),
                'a laptop with nothing on 3306 promised nothing, so the test is dropped'
            );

            putenv('TEST_DB_HOST=127.0.0.1');
            $this->assertSame(
                'threw',
                $this->outcomeOfTheHelper(),
                'a configured host is a promise, so a refused connection is a broken run'
            );

            putenv('TEST_DB_HOST');
            putenv('CI=true');
            $this->assertSame(
                'threw',
                $this->outcomeOfTheHelper(),
                'CI alone is the same promise — it is what the runner sets'
            );
        } finally {
            putenv($host === false ? 'TEST_DB_HOST' : 'TEST_DB_HOST=' . $host);
            putenv($ci === false ? 'CI' : 'CI=' . $ci);
        }
    }

    /**
     * The shape the test above used to miss, shown failing.
     *
     * Reading physical lines meant a call and its message on separate
     * lines were two lines, neither of which carried both halves: the
     * bracket line names no database, the message line names no call. So
     * the offender the guard exists to catch was the one spelling it could
     * not see — and that spelling is already in the suite
     * (`VolumeInventoryTest`, `SsrfUrlValidatorTest`), so it was a matter
     * of somebody writing the next one that way rather than of luck.
     */
    public function testTheScanReadsASkipSplitAcrossLines(): void
    {
        $split = <<<'PHP'
            <?php
            $this->markTestSkipped(
                'Database connection not available: ' . $e->getMessage()
            );
            PHP;

        $calls = $this->skipCalls($split);

        $this->assertCount(1, $calls);
        $this->assertSame(2, $calls[0]['line'], 'the offence is reported at the call, not at the message');
        $this->assertSame(1, preg_match(self::DATABASE_WORDS, $calls[0]['argument']));

        // And the half that must stay quiet: the same shape, blaming
        // something that has nothing to do with a database.
        $capability = <<<'PHP'
            <?php
            $this->markTestSkipped(
                'This PHP build has no AES zip encryption, which this feature refuses without.'
            );
            PHP;

        $this->assertSame(0, preg_match(self::DATABASE_WORDS, $this->skipCalls($capability)[0]['argument']));
    }

    /**
     * The whole argument text of every `markTestSkipped()` call, brackets
     * balanced and string literals stepped over.
     *
     * **Not one single-quoted literal.** Reading `'([^\']*)'` after the
     * opening bracket makes this check depend on a quoting style nothing
     * in this repository enforces — there is no `php-cs-fixer` and no
     * `phpcs` configuration — so
     * `markTestSkipped("Database not available: " . $e->getMessage())`
     * would pass it in silence, and silence is the single outcome this
     * class exists to prevent. Taking the argument whole covers double
     * quotes, concatenation, `sprintf()` and heredocs alike, and
     * `Tests\Architecture\ScheduledTasksAreTestedTest` shows the
     * concatenated shape is already in use.
     *
     * What it still cannot read is a message assembled into a variable
     * beforehand: there the words are not in the call at all, and no
     * amount of text matching will find them. That limit is the reason
     * the first test above asks the connection rather than trusting this
     * one — the two cover each other.
     *
     * @return list<string>
     */
    private function skipArguments(string $source): array
    {
        return array_map(
            static fn (array $call): string => $call['argument'],
            $this->skipCalls($source)
        );
    }

    /**
     * Which of the helper's two exits a refused connection takes here.
     *
     * The skip is caught rather than allowed to propagate: an uncaught one
     * would drop THIS test, which is the opposite of asserting it happens.
     */
    private function outcomeOfTheHelper(): string
    {
        try {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('a refused connection, for this test');
        } catch (SkippedWithMessageException) {
            return 'skipped';
        } catch (\RuntimeException) {
            return 'threw';
        }
    }

    /**
     * The same reading, keeping the line the call opens on.
     *
     * Both tests above go through this. Asking the question line by line
     * was how the multi-line spelling — the call on one line, the message
     * on the next — stayed invisible to the second one: neither line
     * carries both halves of the question.
     *
     * @return list<array{line: int, argument: string}>
     */
    private function skipCalls(string $source): array
    {
        $calls = [];
        $offset = 0;

        while (($found = strpos($source, self::SKIP_CALL, $offset)) !== false) {
            // PHP accepts `markTestSkipped ('…')`, whitespace and all, and
            // no formatter here forbids it — matching « name immediately
            // followed by a bracket » would let that spelling through.
            $open = $found + strlen(self::SKIP_CALL);
            while ($open < strlen($source) && ctype_space($source[$open])) {
                $open++;
            }

            $offset = $open + 1;
            if (($source[$open] ?? '') !== '(') {
                continue;
            }

            $end = $this->endOfArguments($source, $open + 1);
            $calls[] = [
                'line' => substr_count($source, "\n", 0, $found) + 1,
                'argument' => trim(substr($source, $open + 1, $end - $open - 2)),
            ];
            $offset = $end;
        }

        return $calls;
    }

    /**
     * The offset just past the bracket closing the one opened at $from.
     *
     * String literals are stepped over rather than read, so a bracket
     * inside a message is text and not structure.
     */
    private function endOfArguments(string $source, int $from): int
    {
        $length = strlen($source);
        $depth = 1;
        $quote = null;
        $i = $from;

        while ($i < $length && $depth > 0) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i += 2;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            $i++;
        }

        return $i;
    }

    /**
     * @return string[]
     */
    private function testFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
