<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\ReactionNoticeThrottle;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The debounce that keeps a popular post from turning into a dozen
 * notifications for its author.
 *
 * @group database
 */
#[Group('database')]
class ReactionNoticeThrottleTest extends TestCase
{
    private \PDO $pdo;
    private int $postId;
    private int $replyId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');
    }

    public function testTheDefaultWindowIsOneHour(): void
    {
        $this->assertSame(60, $this->throttle()->windowMinutes());
    }

    public function testTheFirstReactionOnAPostNotifies(): void
    {
        $this->assertTrue($this->throttle()->shouldNotifyForPost($this->postId));
    }

    /**
     * The whole point: twelve reactions over a coffee break are one
     * notification, not twelve.
     */
    public function testABurstOfReactionsProducesExactlyOneNotification(): void
    {
        $throttle = $this->throttle();

        $notified = 0;
        for ($i = 0; $i < 12; $i++) {
            if ($throttle->shouldNotifyForPost($this->postId)) {
                $notified++;
            }
        }

        $this->assertSame(1, $notified);
    }

    /**
     * Past the window, the item is notifiable again — a conversation that
     * picks up a day later is news, not the same burst.
     */
    public function testAReactionPastTheWindowNotifiesAgain(): void
    {
        $throttle = $this->throttle();
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
        $this->assertFalse($throttle->shouldNotifyForPost($this->postId));

        $this->backdatePostNotice($this->postId, '-2 hours');

        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
    }

    public function testAReactionStillInsideTheWindowDoesNotNotify(): void
    {
        $throttle = $this->throttle();
        $throttle->shouldNotifyForPost($this->postId);

        // Well inside the default 60-minute window.
        $this->backdatePostNotice($this->postId, '-30 minutes');

        $this->assertFalse($throttle->shouldNotifyForPost($this->postId));
    }

    public function testTheWindowIsPerItemNotGlobal(): void
    {
        $groupId = (new GroupRepository($this->pdo))->create('Autre', null, null, 1);
        $other = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'other', '2026-01-01 11:00:00');
        $throttle = $this->throttle();

        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
        // A different post has its own budget — one busy thread must not
        // silence every other one.
        $this->assertTrue($throttle->shouldNotifyForPost($other));
    }

    public function testPostsAndRepliesHaveSeparateBudgets(): void
    {
        $throttle = $this->throttle();

        // A post id and a reply id collide numerically here on purpose:
        // two tables is what makes them never mean the same row.
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
        $this->assertTrue($throttle->shouldNotifyForReply($this->replyId));
        $this->assertFalse($throttle->shouldNotifyForReply($this->replyId));
    }

    /**
     * A configurable window means an admin can turn debouncing off
     * entirely. Unlike the report threshold, 0 is honoured rather than
     * floored: it only makes the feature noisy, which is the admin's call.
     */
    public function testAZeroWindowNotifiesEveryTime(): void
    {
        $this->setWindow('0');
        $throttle = $this->throttle();

        $this->assertSame(0, $throttle->windowMinutes());
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
    }

    public function testANegativeWindowIsTreatedAsZeroRatherThanReversingTheComparison(): void
    {
        $this->setWindow('-30');

        $this->assertSame(0, $this->throttle()->windowMinutes());
    }

    public function testANonNumericWindowFallsBackToTheDefault(): void
    {
        $this->setWindow('bientôt');

        $this->assertSame(60, $this->throttle()->windowMinutes());
    }

    public function testAShorterConfiguredWindowIsHonoured(): void
    {
        $this->setWindow('5');
        $throttle = $this->throttle();

        $throttle->shouldNotifyForPost($this->postId);
        $this->backdatePostNotice($this->postId, '-10 minutes');

        // 10 minutes ago is outside a 5-minute window but inside the
        // 60-minute default — so this passing proves the setting is read.
        $this->assertTrue($throttle->shouldNotifyForPost($this->postId));
    }

    /**
     * Deleting a post takes its debounce row with it: the CASCADE is why
     * these are two tables with a real foreign key rather than one
     * polymorphic table, and why there is no purge task to write.
     */
    public function testDeletingAPostRemovesItsDebounceRow(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->throttle()->shouldNotifyForPost($this->postId);
        $this->assertSame(1, $this->noticeCount());

        $this->pdo->prepare('DELETE FROM discussion_group_posts WHERE id = ?')->execute([$this->postId]);

        $this->assertSame(0, $this->noticeCount());
    }

    private function throttle(): ReactionNoticeThrottle
    {
        return GroupsTestHelper::reactionNoticeThrottle(
            $this->pdo,
            new SettingService(new SettingRepository($this->pdo))
        );
    }

    private function noticeCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_post_reaction_notices')->fetchColumn();
    }

    private function backdatePostNotice(int $postId, string $modifier): void
    {
        $at = Timestamps::at($modifier);
        $stmt = $this->pdo->prepare('UPDATE discussion_group_post_reaction_notices SET notified_at = ? WHERE post_id = ?');
        $stmt->execute([$at, $postId]);
    }

    private function setWindow(string $value): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?');
        $stmt->execute(['groups', ReactionNoticeThrottle::SETTING_WINDOW]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', ReactionNoticeThrottle::SETTING_WINDOW, $value, 'number', 'Fenêtre', 'Fenêtre']);
    }
}
