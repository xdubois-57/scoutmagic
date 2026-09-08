<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Discovery;

use Core\Database\Connection;
use Core\Help\Discovery\SeenTopicRepository;
use PHPUnit\Framework\TestCase;

/**
 * `markSeen()` against the engine an installation actually runs.
 *
 * The repository writes one statement in two spellings — SQLite's
 * `ON CONFLICT … DO NOTHING` for the in-memory test database, MySQL's
 * `ON DUPLICATE KEY UPDATE` for MySQL and MariaDB — and
 * `Tests\Core\Help\Discovery\SeenTopicRepositoryTest` only ever exercises
 * the first. That is the exact shape of the defect
 * `Tests\Core\Scheduler\LivePrefixGuardOnMysqlTest` was written for: an
 * SQLite-green suite over a statement the real server refuses. So the
 * MySQL clause is tested where it runs.
 *
 * Its own throwaway database, for the same reason as that test: this
 * creates and drops schema, which is not something to do to the suite's
 * shared fixture mid-run.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SeenTopicUpsertOnMysqlTest extends TestCase
{
    private ?Connection $connection = null;
    private ?\PDO $server = null;
    private string $database = '';
    private int $accountId = 0;

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
            // Skip only where no server was ever configured. Where
            // TEST_DB_* IS set, that configuration is a promise a server
            // is there, and a refused connection is a failure to report
            // rather than a test to quietly drop — letting this one skip
            // itself on the one engine it was written for would rebuild
            // the blind spot it exists to close.
            if (getenv('TEST_DB_HOST') === false) {
                $this->markTestSkipped('No MySQL server configured (TEST_DB_HOST unset): ' . $e->getMessage());
            }

            throw $e;
        }

        $this->database = 'scoutmagic_discovery_' . bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `' . $this->database . '`');
        $this->server = $server;

        $this->connection = new Connection($host, $port, $this->database, $user, $password);
        $pdo = $this->connection->getPdo();
        $pdo->exec(
            'CREATE TABLE user_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email_blind_index CHAR(64) NOT NULL,
                help_discovery_snoozed_until DATETIME
            ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE help_topics_seen (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_account_id INT UNSIGNED NOT NULL,
                topic_id VARCHAR(100) NOT NULL,
                seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE INDEX idx_hts_user_topic (user_account_id, topic_id),
                CONSTRAINT fk_hts_user FOREIGN KEY (user_account_id)
                    REFERENCES user_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        $pdo->exec("INSERT INTO user_accounts (email_blind_index) VALUES ('" . str_repeat('a', 64) . "')");
        $this->accountId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }
    }

    public function testTheUpsertRunsAtAllOnThisEngine(): void
    {
        $repository = $this->repository();

        $repository->markSeen($this->accountId, ['publipostage', 'presences-feuille']);

        $this->assertSame(2, $repository->countSeen($this->accountId));
    }

    public function testReMarkingAnIdIsANoOpRatherThanAUniqueIndexError(): void
    {
        $repository = $this->repository();

        $repository->markSeen($this->accountId, ['publipostage']);
        $repository->markSeen($this->accountId, ['publipostage', 'camps-encoder']);

        $seen = $repository->findSeenIds($this->accountId);
        sort($seen);
        $this->assertSame(['camps-encoder', 'publipostage'], $seen);
    }

    public function testTheSnoozeColumnRoundTripsOnThisEngine(): void
    {
        $repository = $this->repository();
        $until = new \DateTimeImmutable('2026-12-24 18:30:00', \Core\Config\AppClock::zone());

        $repository->snooze($this->accountId, $until);

        $read = $repository->snoozedUntil($this->accountId);
        $this->assertNotNull($read);
        $this->assertSame('2026-12-24 18:30:00', $read->format('Y-m-d H:i:s'));
    }

    private function repository(): SeenTopicRepository
    {
        $connection = $this->connection;
        $this->assertNotNull($connection);

        return new SeenTopicRepository($connection->getPdo());
    }
}
