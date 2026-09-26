<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The statements of this repository that only the REAL engine can judge,
 * run against it (roadmap IT-07).
 *
 * **Why this file exists, and it is not a precaution.** The rest of the
 * suite runs on SQLite — `DatabaseTestHelper::createTestDatabase()` opens
 * `sqlite::memory:` unconditionally, `@group database` or not — and
 * SQLite accepts SQL that MySQL and MariaDB both refuse. `runsSince()`
 * shipped with a `LIMIT` directly inside `IN (SELECT …)`: green on every
 * local run and on both database jobs, and a `PDOException` the first
 * time somebody opened the « Boîtes témoins » page on a real
 * installation. Error 1235, « This version of MySQL doesn't yet support
 * 'LIMIT & IN/ALL/ANY/SOME subquery' ».
 *
 * A green SQLite run proves less than it looks (`CLAUDE.md`), so every
 * statement here whose shape the two dialects disagree about gets parsed
 * where it will actually run.
 *
 * @group database
 */
#[Group('database')]
class SeedCopyRepositoryOnMysqlTest extends TestCase
{
    private \PDO $pdo;
    private SeedCopyRepository $copies;
    private EncryptionService $encryption;
    /** @var list<string> the tables this test created, and so drops */
    private array $createdTables = [];

    protected function setUp(): void
    {
        $this->pdo = $this->connect();

        // The shared test database is not migrated, so the table is
        // created here from `schema/core.sql` itself — which also means
        // the declaration this iteration added is parsed by the real
        // engine rather than only by SQLite's dialect.
        //
        // Created only when absent and dropped only when this test
        // created it: the database is shared with every other
        // `@group database` test, and dropping a table another one left
        // behind would make this file's result depend on the order the
        // suite happens to run in.
        // `mail_domain_providers` since issue #422: both readings of the
        // screen now fold through it, so it has to exist here as it does
        // in production.
        foreach (['mail_seed_copies', 'mail_domain_providers'] as $table) {
            if ($this->pdo->query("SHOW TABLES LIKE '{$table}'")?->fetchColumn() === false) {
                $this->pdo->exec(self::createTableStatement($table));
                $this->createdTables[] = $table;
            }
        }

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->copies = new SeedCopyRepository($this->pdo, $this->encryption);
    }

    private function connect(): \PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            return new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach ($this->createdTables as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }

        // Somebody else's table: leave it, but take this test's own rows
        // back out of it.
        if (!in_array('mail_seed_copies', $this->createdTables, true)) {
            $this->pdo->prepare("DELETE FROM mail_seed_copies WHERE run_reference LIKE 'mysql-probe-%'")->execute();
        }
        // The domain is encrypted there (SECURITY.md §5), so this test's
        // rows are recognised by decrypting them, never by a `LIKE`. A row
        // another key wrote is not this test's to judge.
        if (!in_array('mail_domain_providers', $this->createdTables, true)) {
            $rows = $this->pdo->query('SELECT id, domain_encrypted FROM mail_domain_providers');
            $delete = $this->pdo->prepare('DELETE FROM mail_domain_providers WHERE id = ?');
            foreach ($rows === false ? [] : $rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                try {
                    $domain = $this->encryption->decrypt(
                        (string) $row['domain_encrypted'],
                        'mail_domain_providers.domain'
                    );
                } catch (\Throwable) {
                    continue;
                }
                if (str_starts_with($domain, 'mysql-probe-')) {
                    $delete->execute([$row['id']]);
                }
            }
        }
    }

    /**
     * The `CREATE TABLE` block of `schema/core.sql` for one table, as
     * written.
     */
    private static function createTableStatement(string $table): string
    {
        // The `--` comments are dropped BEFORE looking for the
        // statement's terminator: a comment containing a semicolon would
        // otherwise cut the declaration in half.
        $lines = [];
        foreach (explode("\n", (string) file_get_contents(__DIR__ . '/../../../../../schema/core.sql')) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $schema = implode("\n", $lines);

        $start = strpos($schema, 'CREATE TABLE IF NOT EXISTS ' . $table . ' (');
        self::assertNotFalse($start, 'schema/core.sql no longer declares ' . $table . '.');
        $end = strpos($schema, ';', $start);
        self::assertNotFalse($end);

        return substr($schema, $start, $end - $start + 1);
    }

    private function recordRun(string $reference, string $address, string $folder): void
    {
        $sent = new \DateTimeImmutable('-1 day');
        $this->copies->claim($reference, $address, $sent);
        $this->copies->recordLanding($reference, $address, $folder, $sent);
    }

    /**
     * **The one that shipped broken.** A `LIMIT` inside `IN (SELECT …)`
     * is error 1235 on both engines and perfectly fine on SQLite.
     */
    public function testTheRunListingParsesAndRunsOnTheRealEngine(): void
    {
        $this->recordRun('mysql-probe-a', 'temoin@gmail.com', 'INBOX');
        $this->recordRun('mysql-probe-b', 'temoin@outlook.com', 'Junk');

        $runs = $this->copies->runsSince(new \DateTimeImmutable('-30 days'));

        $this->assertArrayHasKey('mysql-probe-a', $runs);
        $this->assertArrayHasKey('mysql-probe-b', $runs);
    }

    /** The aggregate the screen and the routing both read. */
    public function testTheProviderTallyParsesAndRunsOnTheRealEngine(): void
    {
        $this->recordRun('mysql-probe-c', 'temoin@gmail.com', 'Junk');

        $rows = array_values(array_filter(
            $this->copies->tallyByProviderSince(new \DateTimeImmutable('-30 days')),
            static fn(array $row): bool => $row['provider'] === 'gmail.com'
        ));

        $this->assertNotSame([], $rows);
        $this->assertGreaterThanOrEqual(1, $rows[0]['runs']);
    }

    /**
     * **`claim()` answers false on the duplicate rather than throwing**,
     * and the driver code it reads for that (1062) is MySQL's — SQLite
     * reports 19, so the branch this relies on is one SQLite can never
     * exercise.
     */
    public function testASecondClaimForTheSameBoxIsRefusedOnTheRealEngine(): void
    {
        $sent = new \DateTimeImmutable('-1 day');

        $this->assertTrue($this->copies->claim('mysql-probe-d', 'temoin@gmail.com', $sent));
        $this->assertFalse($this->copies->claim('mysql-probe-d', 'temoin@gmail.com', $sent));
    }

    /**
     * **`recordLanding()` reports rows MATCHED, not rows changed.** SQLite
     * reports the first and MySQL the second unless
     * `PDO::MYSQL_ATTR_FOUND_ROWS` is set, which this application does not
     * set — so a guard read off `rowCount()` would answer differently on
     * the two engines. The guard is in the WHERE clause for that reason,
     * and this is where the reason is checked.
     */
    public function testASecondLandingForTheSamePairIsIgnoredOnTheRealEngine(): void
    {
        $sent = new \DateTimeImmutable('-1 day');
        $this->copies->claim('mysql-probe-e', 'temoin@gmail.com', $sent);

        $this->assertTrue($this->copies->recordLanding('mysql-probe-e', 'temoin@gmail.com', 'INBOX', $sent));
        $this->assertFalse($this->copies->recordLanding('mysql-probe-e', 'temoin@gmail.com', 'Junk', $sent));
    }

    /**
     * **The MX attribution folds on the real engine** (issue #422): the
     * two aggregates the fold reads are grouped queries, the shape
     * ONLY_FULL_GROUP_BY judges, and SQLite judges nothing.
     */
    public function testAnAttributedDomainFoldsIntoItsProviderOnTheRealEngine(): void
    {
        $this->recordRun('mysql-probe-f', 'temoin@mysql-probe-famille.be', 'Junk');
        $cache = new \Core\Mail\Transport\MailboxProviderRepository($this->pdo, $this->encryption);
        $cache->note('mysql-probe-famille.be', new \DateTimeImmutable());
        $cache->recordResolved('mysql-probe-famille.be', 'mysql-probe-provider.test', new \DateTimeImmutable());

        $providers = array_column(
            $this->copies->tallyByProviderSince(new \DateTimeImmutable('-30 days')),
            'provider'
        );
        $this->assertContains('mysql-probe-provider.test', $providers);
        $this->assertNotContains('mysql-probe-famille.be', $providers);

        $runs = $this->copies->runsSince(new \DateTimeImmutable('-30 days'));
        $this->assertSame('mysql-probe-provider.test', $runs['mysql-probe-f'][0]->provider);
    }

    /**
     * The cache's own statements on the real engine: the duplicate code a
     * second note meets (1062, where SQLite says 19), and the back-off
     * query's `LIMIT` placeholder and date comparisons.
     */
    public function testTheMxCacheRunsOnTheRealEngine(): void
    {
        $now = new \DateTimeImmutable();
        $cache = new \Core\Mail\Transport\MailboxProviderRepository($this->pdo, $this->encryption);
        $cache->providerOf('warm.up');
        (new \Core\Mail\Transport\MailboxProviderRepository($this->pdo, $this->encryption))
            ->note('mysql-probe-a.be', $now);

        $this->assertTrue($cache->note('mysql-probe-a.be', $now), 'A duplicate insert is not an error.');

        $cache->recordFailure('mysql-probe-a.be', 'no_answer', $now->modify('+1 day'));
        $due = array_column($cache->due($now->modify('+2 days'), $now, 500), 'domain');
        $this->assertContains('mysql-probe-a.be', $due);
        $this->assertNotContains('mysql-probe-a.be', array_column($cache->due($now, $now, 500), 'domain'));
    }
}
