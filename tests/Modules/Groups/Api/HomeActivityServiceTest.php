<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Api;

use Core\Notification\NotificationRepository;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Groups\Api\HomeActivityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The homepage hook (Core\Module\HomeGroupActivityProvider): "how much is
 * waiting for you in your groups", as the three numbers the homepage
 * banner shows.
 *
 * What is worth proving here: an anonymous visitor gets zeros rather than
 * an error, replies and reactions are summed into one "activity" number,
 * already-read notifications never count, and another account's rows are
 * never mixed in.
 *
 * @group database
 */
#[Group('database')]
class HomeActivityServiceTest extends TestCase
{
    private \PDO $pdo;
    private NotificationRepository $repository;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new NotificationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->userId = $this->createAccount('one');
        $this->otherUserId = $this->createAccount('two');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    private function createAccount(string $seed): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc-' . $seed, 'idx-' . $seed]);

        return (int) $this->pdo->lastInsertId();
    }

    private function notify(int $userAccountId, string $typeId): int
    {
        return $this->repository->create($userAccountId, null, $typeId, 'Titre', 'Corps', '/groups/1');
    }

    private function service(): HomeActivityService
    {
        return new HomeActivityService($this->repository);
    }

    private function signIn(int $userAccountId): void
    {
        AuthSession::login($userAccountId, 'someone@test.be', 'identified');
    }

    public function testAnAnonymousVisitorGetsZeros(): void
    {
        $this->notify($this->userId, 'groups.post_published');

        $this->assertSame(
            ['posts' => 0, 'activity' => 0, 'mentions' => 0],
            $this->service()->getUnreadActivitySummaryForCurrentUser()
        );
    }

    public function testAMemberWithNothingWaitingGetsZeros(): void
    {
        $this->signIn($this->userId);

        $this->assertSame(
            ['posts' => 0, 'activity' => 0, 'mentions' => 0],
            $this->service()->getUnreadActivitySummaryForCurrentUser()
        );
    }

    public function testEachTypeIsCountedIntoItsOwnBucket(): void
    {
        $this->signIn($this->userId);
        $this->notify($this->userId, 'groups.post_published');
        $this->notify($this->userId, 'groups.post_published');
        $this->notify($this->userId, 'groups.mentioned');

        $this->assertSame(
            ['posts' => 2, 'activity' => 0, 'mentions' => 1],
            $this->service()->getUnreadActivitySummaryForCurrentUser()
        );
    }

    public function testRepliesAndReactionsAreSummedIntoOneActivityNumber(): void
    {
        $this->signIn($this->userId);
        $this->notify($this->userId, 'groups.reply_received');
        $this->notify($this->userId, 'groups.reaction_received');
        $this->notify($this->userId, 'groups.reaction_received');

        $summary = $this->service()->getUnreadActivitySummaryForCurrentUser();

        $this->assertSame(3, $summary['activity']);
        $this->assertSame(0, $summary['posts']);
    }

    public function testAlreadyReadNotificationsDoNotCount(): void
    {
        $this->signIn($this->userId);
        $read = $this->notify($this->userId, 'groups.post_published');
        $this->notify($this->userId, 'groups.post_published');
        $this->repository->markRead($read);

        $this->assertSame(1, $this->service()->getUnreadActivitySummaryForCurrentUser()['posts']);
    }

    public function testAnotherAccountsNotificationsAreNeverCounted(): void
    {
        $this->signIn($this->userId);
        $this->notify($this->otherUserId, 'groups.post_published');
        $this->notify($this->otherUserId, 'groups.mentioned');

        $this->assertSame(
            ['posts' => 0, 'activity' => 0, 'mentions' => 0],
            $this->service()->getUnreadActivitySummaryForCurrentUser()
        );
    }

    public function testNotificationsFromOtherModulesAreIgnored(): void
    {
        $this->signIn($this->userId);
        $this->notify($this->userId, 'core.system');
        $this->notify($this->userId, 'calendar.event_created');

        $this->assertSame(
            ['posts' => 0, 'activity' => 0, 'mentions' => 0],
            $this->service()->getUnreadActivitySummaryForCurrentUser()
        );
    }
}
