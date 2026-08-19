<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\ReportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The threshold, the moderator's two answers to it, and the two rules
 * that are easy to get wrong: a restored item never auto-hides again, and
 * nothing about a report ever reaches the journal but ids.
 *
 * @group database
 */
#[Group('database')]
class ReportServiceTest extends TestCase
{
    private \PDO $pdo;
    private ReportService $service;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private int $groupId;
    private int $postId;
    private int $replyId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');

        $this->service = GroupsTestHelper::reportService(
            $this->pdo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    public function testTheDefaultThresholdIsTwo(): void
    {
        $this->assertSame(2, $this->service->threshold());
    }

    /**
     * A 0 setting would hide every item on its first report — far more
     * likely a typo than an intent, and the kind of misconfiguration that
     * silently empties a group.
     */
    public function testTheThresholdIsFlooredAtOneWhateverTheSettingSays(): void
    {
        $this->setThreshold('0');
        $this->assertSame(1, $this->service->threshold());

        $this->setThreshold('-5');
        $this->assertSame(1, $this->service->threshold());
    }

    public function testOneReportBelowTheThresholdLeavesThePostVisible(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3);

        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testTheThresholdthReportHidesThePost(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3);
        $this->service->reportPost($this->groupId, $this->postId, 4);

        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testAFurtherReportAboveTheThresholdChangesNothing(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3);
        $this->service->reportPost($this->groupId, $this->postId, 4);
        $hiddenAt = $this->postRepo->findById($this->postId)->hiddenAt;

        $this->service->reportPost($this->groupId, $this->postId, 5);

        // Still hidden, and NOT re-stamped: the hide happened once.
        $this->assertSame($hiddenAt, $this->postRepo->findById($this->postId)->hiddenAt);
    }

    public function testARepeatReportFromTheSameMemberNeverReachesTheThreshold(): void
    {
        $this->assertTrue($this->service->reportPost($this->groupId, $this->postId, 3));
        $this->assertFalse($this->service->reportPost($this->groupId, $this->postId, 3));
        $this->assertFalse($this->service->reportPost($this->groupId, $this->postId, 3));

        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());
    }

    /**
     * The heart of the design: the report count only ever grows, so
     * recomputing visibility from it would let the very next report undo
     * a moderator's decision. moderation_cleared is persisted for exactly
     * that reason.
     */
    public function testARestoredPostStaysVisibleHoweverManyFurtherReportsArrive(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3);
        $this->service->reportPost($this->groupId, $this->postId, 4);
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());

        $this->service->restorePost($this->groupId, $this->postId, 99);
        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());

        $this->service->reportPost($this->groupId, $this->postId, 5);
        $this->service->reportPost($this->groupId, $this->postId, 6);
        $this->service->reportPost($this->groupId, $this->postId, 7);

        $post = $this->postRepo->findById($this->postId);
        $this->assertFalse($post->isHidden());
        $this->assertTrue($post->moderationCleared);
    }

    public function testARestoredReplyIsEquallyImmune(): void
    {
        $this->service->reportReply($this->groupId, $this->replyId, 3);
        $this->service->reportReply($this->groupId, $this->replyId, 4);
        $this->assertTrue($this->replyRepo->findById($this->replyId)->isHidden());

        $this->service->restoreReply($this->groupId, $this->replyId, 99);
        $this->service->reportReply($this->groupId, $this->replyId, 5);
        $this->service->reportReply($this->groupId, $this->replyId, 6);

        $this->assertFalse($this->replyRepo->findById($this->replyId)->isHidden());
    }

    public function testAHigherThresholdDelaysTheHide(): void
    {
        $this->setThreshold('4');

        foreach ([3, 4, 5] as $memberId) {
            $this->service->reportPost($this->groupId, $this->postId, $memberId);
            $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());
        }

        $this->service->reportPost($this->groupId, $this->postId, 6);
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testReportingAReplyHidesOnlyTheReply(): void
    {
        $this->service->reportReply($this->groupId, $this->replyId, 3);
        $this->service->reportReply($this->groupId, $this->replyId, 4);

        $this->assertTrue($this->replyRepo->findById($this->replyId)->isHidden());
        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());
    }

    /**
     * The journal is the one place a report becomes durable, so it is the
     * one place where leaking the message's text, the reporter's name or
     * the author's would matter. It carries ids and counts, nothing else.
     */
    public function testTheJournalCarriesIdsOnlyAndNeverTheItemsText(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3, 77);
        $this->service->reportPost($this->groupId, $this->postId, 4, 78);
        $this->service->restorePost($this->groupId, $this->postId, 79);

        $rows = $this->journalRows();
        $types = array_column($rows, 'event_type');
        $this->assertSame(
            ['group_post_reported', 'group_post_reported', 'group_post_auto_hidden', 'group_post_restored'],
            $types
        );

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('hello', (string) $row['context']);
            $this->assertStringNotContainsString('hello', $row['description']);
        }

        // The auto-hide is nobody's action, so it is journaled with no
        // actor rather than with the last reporter's account.
        $autoHidden = $rows[2];
        $this->assertNull($autoHidden['user_account_id']);
        $this->assertSame(
            ['group_id' => $this->groupId, 'post_id' => $this->postId, 'report_count' => 2],
            json_decode((string) $autoHidden['context'], true)
        );
    }

    public function testTheReporterIsNeverNamedInTheJournal(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 4242, 77);

        $context = (string) $this->journalRows()[0]['context'];
        $this->assertStringNotContainsString('4242', $context);
        $this->assertStringNotContainsString('reporter', $context);
    }

    public function testADuplicateReportIsNotJournaledTwice(): void
    {
        $this->service->reportPost($this->groupId, $this->postId, 3, 77);
        $this->service->reportPost($this->groupId, $this->postId, 3, 77);

        $this->assertCount(1, $this->journalRows());
    }

    public function testAModeratorDeletionIsJournaledWithIdsOnly(): void
    {
        $this->service->journalModeratorDeletion('post', $this->groupId, $this->postId, 77);
        $this->service->journalModeratorDeletion('reply', $this->groupId, $this->replyId, 77);

        $rows = $this->journalRows();
        $this->assertSame(
            ['group_post_moderator_deleted', 'group_reply_moderator_deleted'],
            array_column($rows, 'event_type')
        );
        $this->assertSame(
            ['group_id' => $this->groupId, 'post_id' => $this->postId],
            json_decode((string) $rows[0]['context'], true)
        );
        $this->assertSame(
            ['group_id' => $this->groupId, 'reply_id' => $this->replyId],
            json_decode((string) $rows[1]['context'], true)
        );
    }

    public function testForPostsAnswersTheWholePageInOneGo(): void
    {
        $other = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'other', '2026-01-01 11:00:00');
        $this->service->reportPost($this->groupId, $this->postId, 3);
        $this->service->reportPost($this->groupId, $this->postId, 4);
        $this->service->reportPost($this->groupId, $other, 4);

        $data = $this->service->forPosts([$this->postId, $other], [3]);

        $this->assertSame([$this->postId => true], $data['reported']);
        $this->assertSame(2, $data['counts'][$this->postId]);
        $this->assertSame(1, $data['counts'][$other]);
    }

    public function testForRepliesAnswersTheWholePageInOneGo(): void
    {
        $other = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'other', '2026-01-01 10:06:00');
        $this->service->reportReply($this->groupId, $other, 4);

        $data = $this->service->forReplies([$this->replyId, $other], [4]);

        $this->assertSame([$other => true], $data['reported']);
        $this->assertSame(1, $data['counts'][$other]);
    }

    private function setThreshold(string $value): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?');
        $stmt->execute(['groups', ReportService::SETTING_THRESHOLD]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', ReportService::SETTING_THRESHOLD, $value, 'number', 'Seuil', 'Seuil']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalRows(): array
    {
        return $this->pdo
            ->query('SELECT * FROM event_log WHERE category = \'groups\' ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
