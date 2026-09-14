<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Config\AppClock;
use Core\Notification\PushInvitationRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `user_accounts.push_invitation_dismissed_at` (ARCHITECTURE.md §8.111) —
 * the only stored state of the invitation the installed application
 * offers.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PushInvitationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PushInvitationRepository $invitations;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->invitations = new PushInvitationRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['parent@test.be', hash('sha256', 'parent@test.be')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The starting state, and the one every row predating the column is
     * in: never answered, so the invitation is offered rather than
     * silently withheld from everybody who existed before it shipped.
     */
    public function testAFreshAccountHasNeverAnswered(): void
    {
        $this->assertNull($this->invitations->dismissedAt($this->accountId));
    }

    public function testDismissRecordsTheAnswerAtTheCurrentInstant(): void
    {
        $this->invitations->dismiss($this->accountId);

        $at = $this->invitations->dismissedAt($this->accountId);
        $this->assertNotNull($at);
        $this->assertSame(AppClock::now()->format('Y-m-d H'), $at->format('Y-m-d H'));
    }

    /**
     * The column is read as a question with two answers ("has this
     * account answered"), never as a delay, so a second write is not a
     * state to guard against.
     */
    public function testDismissingTwiceIsStillJustAnswered(): void
    {
        $this->invitations->dismiss($this->accountId);
        $this->invitations->dismiss($this->accountId);

        $this->assertNotNull($this->invitations->dismissedAt($this->accountId));
    }

    /**
     * An account row that is gone reads as "never answered" rather than
     * as an error — the same reading SeenTopicRepository::snoozedUntil()
     * gives, and the one that keeps a deleted account from turning a page
     * render into a failure.
     */
    public function testAnAccountThatNoLongerExistsReadsAsNeverAnswered(): void
    {
        $this->assertNull($this->invitations->dismissedAt($this->accountId + 4242));
    }

    /**
     * One account's answer says nothing about another's — obvious, and
     * exactly the kind of WHERE clause that is written wrong once.
     */
    public function testTheAnswerIsScopedToItsOwnAccount(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['autre@test.be', hash('sha256', 'autre@test.be')]);
        $otherId = (int) $this->pdo->lastInsertId();

        $this->invitations->dismiss($this->accountId);

        $this->assertNotNull($this->invitations->dismissedAt($this->accountId));
        $this->assertNull($this->invitations->dismissedAt($otherId));
    }
}
