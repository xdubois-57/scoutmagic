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
use Tests\UsesProductionEngine;

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
 * where it will actually run — against the tables `schema/core.sql`
 * declares, migrated by the real runner (`Tests\UsesProductionEngine`),
 * and through the connection the site opens.
 */
#[Group('database')]
class SeedCopyRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SeedCopyRepository $copies;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        // The whole schema as the migration builds it, `mail_seed_copies`
        // and `mail_domain_providers` included — the second since issue
        // #422, because both readings of the screen now fold through it.
        // A database of this class's own, emptied before every test: the
        // shared `TEST_DB_NAME` would hand this file whatever rows another
        // one left behind.
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->copies = new SeedCopyRepository($this->pdo, $this->encryption);
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
