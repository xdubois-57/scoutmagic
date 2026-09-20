<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

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
     * the literal passed to `markTestSkipped()`, case-insensitively.
     */
    private const DATABASE_WORDS = '/Database|MySQL|MariaDB|TEST_DB_/i';

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
        $host = getenv('TEST_DB_HOST');
        if ($host === false && getenv('CI') === false) {
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
        $host = $host === false ? '127.0.0.1' : $host;
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $database = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->fail(
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
                    getenv('TEST_DB_HOST') === false ? 'unset' : 'set',
                    getenv('CI') === false ? 'unset' : 'set',
                    $e->getMessage()
                )
            );
        }

        // Answering is not enough: it has to be the engine those classes
        // were written for. SQLite is what the rest of the suite runs on
        // (Tests\DatabaseTestHelper), and it is exactly what they are NOT
        // testing — docs/quality-pipeline.md § The engine a test actually
        // runs on.
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+/',
            $version,
            'The server answered but did not say which version it is, so nothing identifies it as MySQL or MariaDB.'
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
            if (str_contains($source, 'TEST_DB_HOST')) {
                continue;
            }

            preg_match_all("/markTestSkipped\\(\\s*'([^']*)'/", $source, $matches);
            foreach ($matches[1] as $message) {
                if (preg_match(self::DATABASE_WORDS, $message) === 1) {
                    $uncovered[] = substr($path, strlen(dirname(__DIR__, 2)) + 1) . ' — « ' . $message . ' »';
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
    public function testTheScanReadsTheSuiteRatherThanAnEmptyList(): void
    {
        $this->assertGreaterThan(1000, count($this->testFiles()));
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
