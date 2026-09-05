<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Database\Connection;
use Core\Scheduler\SchedulerRepository;
use PHPUnit\Framework\TestCase;

/**
 * `hasLiveStartingWith()` against the engine an installation actually runs.
 *
 * **This exists because the SQLite-backed version of the same test passed
 * while the query was a syntax error in production.** The guard was
 * written as `LIKE ? ESCAPE '\'`; MySQL reads the backslash inside that
 * literal as escaping the closing quote and refuses the whole statement,
 * while SQLite is happy to take it as a literal backslash. Every test
 * that exercised the guard ran on SQLite, so all of them were green and
 * the reenrollment campaign would have scheduled no e-mail at all.
 *
 * That is the trap `.claude/skills/steward/SKILL.md` names — several CI
 * jobs are handed MySQL 8 while a developer's suite falls back to the
 * MariaDB this container starts, and a divergence between them "will sit
 * there staying green". A prefix match is exactly the kind of SQL where
 * the two engines disagree, so it is tested where it runs.
 *
 * Its own throwaway database, like the restore round trip: this creates
 * and drops schema, which is not something to do to the suite's shared
 * fixture mid-run.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class LivePrefixGuardOnMysqlTest extends TestCase
{
    private ?Connection $connection = null;
    private ?\PDO $server = null;
    private string $database = '';

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $server = new \PDO(
                sprintf('mysql:host=%s;port=%d', $host, $port),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            // Skip only where no server was ever configured — a developer's
            // laptop with nothing on 3306. Where TEST_DB_* IS set, the
            // configuration is a promise that a server is there, and a bad
            // password or a refused connection is a failure to report, not
            // a test to quietly drop.
            //
            // This test exists because a MySQL-only defect stayed green
            // behind SQLite. Letting it skip itself on the one engine it
            // was written for would rebuild that same blind spot, one
            // level up: green, and proving nothing.
            if (getenv('TEST_DB_HOST') === false) {
                $this->markTestSkipped('No MySQL server configured (TEST_DB_HOST unset): ' . $e->getMessage());
            }

            throw $e;
        }

        $this->database = 'scoutmagic_prefix_' . bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `' . $this->database . '`');
        $this->server = $server;

        $this->connection = new Connection($host, $port, $this->database, $user, $password);
        $this->connection->getPdo()->exec(
            'CREATE TABLE scheduled_actions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(64) NOT NULL,
                task_key VARCHAR(128) NOT NULL,
                run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                payload TEXT NULL,
                reference VARCHAR(200) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "pending",
                requested_by_user_account_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }
    }

    public function testTheGuardRunsAtAllOnThisEngine(): void
    {
        $this->queue('opening:2027-05-15', 'pending');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15'),
            'A guard that cannot be parsed by the server is a hand-over that never happens.'
        );
    }

    public function testAContinuationOfTheSameChainIsSeenThroughItsPrefix(): void
    {
        $this->queue('opening:2027-05-15:37', 'pending');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testAnotherHandOverIsNotMistakenForThisOne(): void
    {
        $this->queue('closing:2027-05-15', 'pending');

        $this->assertFalse(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testADrainedChainIsNotLive(): void
    {
        $this->queue('opening:2027-05-15', 'done');

        $this->assertFalse(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testAChainBeingRunRightNowCounts(): void
    {
        $this->queue('opening:2027-05-15', 'processing');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15'),
            'A batch claimed a moment ago is a hand-over in flight.'
        );
    }

    /**
     * A reference is data, not a pattern: `%` in the prefix must match a
     * literal `%`, and the escape character must match itself.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function patternsInsideAReference(): array
    {
        return [
            'a percent matches itself' => ['100%:2027', '100%:2027', true],
            // The stored reference has no percent; a prefix carrying one
            // must therefore not match it. Unescaped, '100%' would.
            'a percent is not a wildcard' => ['100abc:2027', '100%', false],
            'an underscore matches itself' => ['a_b:2027', 'a_b', true],
            'an underscore is not any character' => ['axb:2027', 'a_b', false],
            'the escape character matches itself' => ['a!b:2027', 'a!b', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('patternsInsideAReference')]
    public function testAReferenceIsNeverReadAsAPattern(string $stored, string $prefix, bool $expected): void
    {
        $this->queue($stored, 'pending');

        $this->assertSame(
            $expected,
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', $prefix)
        );
    }

    // ── harness ───────────────────────────────────────────────────────

    private function repository(): SchedulerRepository
    {
        return new SchedulerRepository($this->connection->getPdo());
    }

    private function queue(string $reference, string $status): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, reference, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['registration', 'send_reenrollment_emails', $reference, $status]);
    }
}
