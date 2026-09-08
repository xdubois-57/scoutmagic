<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Discovery;

use Core\Config\AppClock;
use Core\Help\Discovery\SeenTopicRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SeenTopicRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SeenTopicRepository $repository;
    private int $accountId;
    private int $otherAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SeenTopicRepository($this->pdo);
        $this->accountId = $this->createAccount('un@test.be');
        $this->otherAccountId = $this->createAccount('deux@test.be');
    }

    public function testAnAccountThatHasSeenNothingReportsNothing(): void
    {
        $this->assertSame([], $this->repository->findSeenIds($this->accountId));
        $this->assertSame(0, $this->repository->countSeen($this->accountId));
        $this->assertNull($this->repository->snoozedUntil($this->accountId));
    }

    public function testMarkSeenRecordsABatchAndCountsIt(): void
    {
        $this->repository->markSeen($this->accountId, ['publipostage', 'presences-feuille']);

        $seen = $this->repository->findSeenIds($this->accountId);
        sort($seen);

        $this->assertSame(['presences-feuille', 'publipostage'], $seen);
        $this->assertSame(2, $this->repository->countSeen($this->accountId));
    }

    /**
     * The dialog is closed from a page that can be open in two tabs, so
     * the same id arrives twice. It must be a no-op, not a unique-index
     * error — and it must not move the instant the tip was first seen.
     */
    public function testMarkingTheSameIdTwiceIsANoOpAndKeepsTheFirstInstant(): void
    {
        $this->repository->markSeen($this->accountId, ['publipostage']);
        $firstSeenAt = $this->seenAtOf('publipostage');

        $this->repository->markSeen($this->accountId, ['publipostage', 'camps-encoder']);

        $this->assertSame(2, $this->repository->countSeen($this->accountId));
        $this->assertSame($firstSeenAt, $this->seenAtOf('publipostage'));
    }

    public function testTheSameIdInOneBatchTwiceIsRecordedOnce(): void
    {
        $this->repository->markSeen($this->accountId, ['publipostage', 'publipostage']);

        $this->assertSame(1, $this->repository->countSeen($this->accountId));
    }

    public function testAnEmptyBatchWritesNothing(): void
    {
        $this->repository->markSeen($this->accountId, []);

        $this->assertSame(0, $this->repository->countSeen($this->accountId));
    }

    public function testOneAccountNeverSeesAnother(): void
    {
        $this->repository->markSeen($this->accountId, ['publipostage']);

        $this->assertSame([], $this->repository->findSeenIds($this->otherAccountId));
        $this->assertSame(0, $this->repository->countSeen($this->otherAccountId));
    }

    public function testClearForgetsOnlyTheAccountAskedFor(): void
    {
        $this->repository->markSeen($this->accountId, ['publipostage']);
        $this->repository->markSeen($this->otherAccountId, ['publipostage']);

        $this->repository->clear($this->accountId);

        $this->assertSame([], $this->repository->findSeenIds($this->accountId));
        $this->assertSame(['publipostage'], $this->repository->findSeenIds($this->otherAccountId));
    }

    public function testSnoozeStoresAnInstantAndReadsItBackOnTheApplicationClock(): void
    {
        $until = AppClock::now()->modify('+3 hours');

        $this->repository->snooze($this->accountId, $until);

        $read = $this->repository->snoozedUntil($this->accountId);
        $this->assertNotNull($read);
        $this->assertSame($until->format('Y-m-d H:i:s'), $read->format('Y-m-d H:i:s'));
        $this->assertSame(AppClock::TIMEZONE, $read->getTimezone()->getName());
    }

    public function testSnoozeWithNullReleasesTheAccount(): void
    {
        $this->repository->snooze($this->accountId, AppClock::now()->modify('+1 day'));
        $this->repository->snooze($this->accountId, null);

        $this->assertNull($this->repository->snoozedUntil($this->accountId));
    }

    /**
     * A session can outlive the account row it points at (a wipe and
     * reinstall). Reading a snooze for an account that is gone answers
     * "nothing holds it back" rather than throwing on the hot path.
     */
    public function testSnoozedUntilForAnUnknownAccountIsNull(): void
    {
        $this->assertNull($this->repository->snoozedUntil(999999));
    }

    private function seenAtOf(string $topicId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT seen_at FROM help_topics_seen WHERE user_account_id = ? AND topic_id = ?'
        );
        $stmt->execute([$this->accountId, $topicId]);

        return (string) $stmt->fetchColumn();
    }

    private function createAccount(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)'
        );
        $stmt->execute([$email, hash('sha256', $email)]);

        return (int) $this->pdo->lastInsertId();
    }
}
