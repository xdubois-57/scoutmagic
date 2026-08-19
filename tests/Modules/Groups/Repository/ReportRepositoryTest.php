<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\ReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Both report tables through the one class, and the property the whole
 * design rests on: one report per member per item, enforced by the
 * database rather than by a read-then-write in PHP.
 *
 * @group database
 */
#[Group('database')]
class ReportRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReportRepository $postReports;
    private ReportRepository $replyReports;
    private int $postId;
    private int $replyId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');

        $this->postReports = ReportRepository::forPosts($this->pdo);
        $this->replyReports = ReportRepository::forReplies($this->pdo);
    }

    public function testAFirstReportIsRecorded(): void
    {
        $this->assertTrue($this->postReports->record($this->postId, 3));
        $this->assertSame(1, $this->postReports->countFor($this->postId));
    }

    /**
     * The duplicate is refused by the UNIQUE index, not by a prior SELECT:
     * record() catches SQLSTATE 23000 and reports "already reported"
     * rather than raising. That is what makes a double submit — two tabs,
     * a double tap, a retried request — safe under concurrency.
     */
    public function testASecondReportBySameMemberIsRefusedAndDoesNotInflateTheCount(): void
    {
        $this->assertTrue($this->postReports->record($this->postId, 3));
        $this->assertFalse($this->postReports->record($this->postId, 3));

        $this->assertSame(1, $this->postReports->countFor($this->postId));
    }

    public function testDifferentMembersEachCount(): void
    {
        $this->postReports->record($this->postId, 3);
        $this->postReports->record($this->postId, 4);
        $this->postReports->record($this->postId, 5);

        $this->assertSame(3, $this->postReports->countFor($this->postId));
    }

    public function testTheTwoTablesAreIndependent(): void
    {
        // A post id and a reply id can collide numerically — two tables
        // exist precisely so they never mean the same row.
        $this->postReports->record($this->postId, 3);

        $this->assertSame(0, $this->replyReports->countFor($this->postId));
    }

    public function testAReplyIsReportedThroughItsOwnTable(): void
    {
        $this->assertTrue($this->replyReports->record($this->replyId, 3));
        $this->assertFalse($this->replyReports->record($this->replyId, 3));

        $this->assertSame(1, $this->replyReports->countFor($this->replyId));
    }

    public function testHasReportedIsTrueForAnyOfTheAccountsLinkedMembers(): void
    {
        $this->postReports->record($this->postId, 4);

        // An account linked to several members has reported the item as
        // soon as ONE of them did — otherwise a parent of three children
        // would be offered the action twice.
        $this->assertTrue($this->postReports->hasReported($this->postId, [3, 4]));
        $this->assertFalse($this->postReports->hasReported($this->postId, [3, 5]));
    }

    public function testHasReportedIsFalseWithNoLinkedMembers(): void
    {
        $this->postReports->record($this->postId, 4);

        $this->assertFalse($this->postReports->hasReported($this->postId, []));
    }

    public function testReportedByForAndCountsForAnswerForAWholePageAtOnce(): void
    {
        $groupId = (new GroupRepository($this->pdo))->create('Autre', null, null, 1);
        $other = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'other', '2026-01-01 11:00:00');

        $this->postReports->record($this->postId, 3);
        $this->postReports->record($this->postId, 4);
        $this->postReports->record($other, 4);

        $reported = $this->postReports->reportedByFor([$this->postId, $other], [3]);
        $counts = $this->postReports->countsFor([$this->postId, $other]);

        // Keyed by item id, and holding only the items this member
        // actually reported — an absent key is "not reported".
        $this->assertSame([$this->postId => true], $reported);
        $this->assertSame(2, $counts[$this->postId]);
        $this->assertSame(1, $counts[$other]);
    }

    public function testTheBatchedReadsTolerateAnEmptyPage(): void
    {
        $this->assertSame([], $this->postReports->reportedByFor([], [3]));
        $this->assertSame([], $this->postReports->countsFor([]));
    }
}
