<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Attention\AttentionPoint;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Repository\UnsettledReportRepository;
use Modules\Groups\Service\GroupsAttentionProvider;
use Modules\Groups\Service\ReportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Issue #718 § C point 6: reported content no moderator has answered, one
 * point per group, gone once the item is restored or deleted — and still
 * there when the threshold has only hidden it.
 */
#[Group('database')]
class GroupsAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private ReportService $reports;
    private int $groupId;
    private int $postId;
    private int $replyId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');

        $this->reports = GroupsTestHelper::reportService(
            $this->pdo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    /** @return AttentionPoint[] */
    private function points(): array
    {
        return (new GroupsAttentionProvider(new UnsettledReportRepository($this->pdo)))->collect(1);
    }

    public function testNothingReportedRaisesNothing(): void
    {
        $this->assertSame([], $this->points());
    }

    public function testAReportedPostIsAPointLeadingToTheGroupsReports(): void
    {
        $this->reports->reportPost($this->groupId, $this->postId, 3);

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('Un contenu signalé attend une décision dans « Louveteaux »', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/groups/' . $this->groupId . '/reports', $points[0]->actionUrl);
    }

    public function testPostsAndRepliesAreCountedTogetherOncePerItem(): void
    {
        $this->reports->reportPost($this->groupId, $this->postId, 3);
        $this->reports->reportPost($this->groupId, $this->postId, 4);
        $this->reports->reportReply($this->groupId, $this->replyId, 3);

        $points = $this->points();

        $this->assertCount(1, $points, 'one point per group, not per report');
        $this->assertStringStartsWith('2 contenus signalés attendent', $points[0]->title);
    }

    /** The threshold hides; it does not answer. */
    public function testAnItemTheThresholdHidStillWaits(): void
    {
        $this->reports->reportPost($this->groupId, $this->postId, 3);
        $this->reports->reportPost($this->groupId, $this->postId, 4);
        $this->assertTrue((new PostRepository($this->pdo))->findById($this->postId)?->isHidden());

        $this->assertCount(1, $this->points());
    }

    public function testRestoringAnswersTheReport(): void
    {
        $this->reports->reportPost($this->groupId, $this->postId, 3);
        $this->reports->restorePost($this->groupId, $this->postId, null);

        $this->assertSame([], $this->points());
    }

    public function testDeletingAnswersTheReport(): void
    {
        $this->reports->reportReply($this->groupId, $this->replyId, 3);
        (new ReplyRepository($this->pdo))->delete($this->replyId);

        $this->assertSame([], $this->points());
    }

    public function testOnePointPerGroup(): void
    {
        $otherGroup = (new GroupRepository($this->pdo))->create('Éclaireurs', null, null, 1);
        $otherPost = GroupsTestHelper::createPostAt($this->pdo, $otherGroup, 'bonjour', '2026-01-02 10:00:00');
        $this->reports->reportPost($this->groupId, $this->postId, 3);
        $this->reports->reportPost($otherGroup, $otherPost, 3);

        $this->assertCount(2, $this->points());
    }
}
