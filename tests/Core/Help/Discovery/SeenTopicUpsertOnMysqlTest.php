<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Discovery;

use Core\Help\Discovery\SeenTopicRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

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
 * Against the tables the migration builds (`Tests\UsesProductionEngine`),
 * in a database of this class's own: `help_topics_seen` with its real
 * unique index and its foreign key into the real `user_accounts`, rather
 * than a copy of them written out here that could drift from
 * `schema/core.sql` without anybody noticing.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SeenTopicUpsertOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $accountId = 0;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        // The account the foreign key needs. Its id is read back rather
        // than assumed: the counter carries on from one test to the next.
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['x', str_repeat('a', 64)]);
        $this->accountId = (int) $this->pdo->lastInsertId();
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
        return new SeenTopicRepository($this->pdo);
    }
}
