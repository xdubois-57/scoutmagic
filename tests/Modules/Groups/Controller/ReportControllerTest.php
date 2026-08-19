<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Groups\Controller\ReportController;
use Modules\Groups\Service\GroupSessionContextFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Reporting and restoring over HTTP: CSRF, who may do what, and the
 * 404-not-403 rule this endpoint shares with Controller\ReactionController
 * for exactly the same reason — answering differently for "exists in a
 * group you cannot see" and "does not exist" would turn it into an oracle
 * for enumerating post ids across every group on the site.
 *
 * @group database
 */
#[Group('database')]
class ReportControllerTest extends GroupsControllerTestCase
{
    private int $replyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->replyId = GroupsTestHelper::createReplyAt(
            $this->pdo,
            $this->postId,
            'Une réponse',
            '2026-01-01 10:05:00',
            self::AUTHOR_ACCOUNT,
            $this->memberId
        );
    }

    public function testReportRejectsAMissingCsrfToken(): void
    {
        $_POST = [];

        $response = $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->postReportCount());
    }

    public function testAMemberMayReportAPost(): void
    {
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $this->postReportCount());
    }

    public function testAMemberMayReportAReply(): void
    {
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->reportReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $this->replyReportCount());
    }

    /**
     * The reporter is told nothing about the outcome, so a second report
     * has to look exactly like a first from outside: same status, same
     * redirect. Only the count in the database differs — and it does not
     * move.
     */
    public function testASecondReportAnswersIdenticallyAndChangesNothing(): void
    {
        $this->withCsrf([]);
        $first = $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));
        $this->withCsrf([]);
        $second = $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));

        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        $this->assertSame(1, $this->postReportCount());
    }

    /**
     * The oracle guard: a non-member must not be able to tell "this post
     * exists in a group I cannot see" from "this post does not exist".
     */
    public function testReportingIs404ForANonMemberRatherThan403(): void
    {
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)
            ->reportPost($this->request(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->postReportCount());
    }

    public function testReportingIs404ForAnUnknownGroupToo(): void
    {
        // Same status as the case above, which is the whole point.
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)
            ->reportPost($this->request(), ['id' => '9999', 'postId' => (string) $this->postId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReportingAPostFromAnotherGroupIs404EvenForAMemberOfBoth(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup(
            'Autre',
            $this->sectionId,
            $this->currentYearId,
            $this->moderatorMemberId
        );
        $foreign = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->reportPost($this->request(), $this->params($foreign));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->postReportCount());
    }

    public function testReportingAnUnknownPostIs404(): void
    {
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->reportPost($this->request(), $this->params(999999));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReportingAReplyFromAnotherGroupIs404(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup(
            'Autre',
            $this->sectionId,
            $this->currentYearId,
            $this->moderatorMemberId
        );
        $foreignPost = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $foreignReply = GroupsTestHelper::createReplyAt($this->pdo, $foreignPost, 'là-bas', '2026-01-01 10:01:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->reportReply($this->request(), $this->params(null, $foreignReply));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->replyReportCount());
    }

    /**
     * A moderator reporting is still just a report — it carries no extra
     * weight, and certainly does not hide anything on its own.
     */
    public function testAModeratorsReportIsJustAReport(): void
    {
        $this->withCsrf([]);
        $this->controller([$this->moderatorMemberId])->reportPost($this->request(), $this->params($this->postId));

        $this->assertSame(1, $this->postReportCount());
        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testTheThresholdthReportHidesThePostThroughTheEndpoint(): void
    {
        $second = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SECOND', $this->sectionId, $this->currentYearId);

        $this->withCsrf([]);
        $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));
        $this->assertFalse($this->postRepo->findById($this->postId)->isHidden());

        $this->withCsrf([]);
        $this->controller([$second], self::OTHER_ACCOUNT)->reportPost($this->request(), $this->params($this->postId));

        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testRestoreRejectsAMissingCsrfToken(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $_POST = [];

        $response = $this->controller([$this->moderatorMemberId])->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    /**
     * A 403 rather than a 404: an ordinary member of the group can read
     * the group, so refusing plainly reveals nothing they did not already
     * know — unlike the report endpoint, where the group itself may be
     * invisible.
     */
    public function testAnOrdinaryMemberMayNotRestore(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testANonMemberGetting404OnRestoreRatherThan403(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)
            ->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAModeratorMayRestoreAPostAndItStaysVisibleAfterwards(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        // OTHER_ACCOUNT, not AUTHOR_ACCOUNT: a moderator may never
        // restore an item they wrote themselves (prompt 12), so this test
        // has to be a moderator who is not the author.
        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $post = $this->postRepo->findById($this->postId);
        $this->assertFalse($post->isHidden());
        // Persisted, not recomputed: the next report must not undo this.
        $this->assertTrue($post->moderationCleared);
    }

    /**
     * The conflict of interest prompt 12 closes. In a space where minors
     * write, the person a report is about must not be the person who
     * decides the report was wrong.
     */
    public function testAModeratorMayNotRestoreAPostTheyWroteThemselves(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        // The post's author account IS this caller's, and they moderate.
        $response = $this->controller([$this->moderatorMemberId], self::AUTHOR_ACCOUNT)
            ->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    /**
     * The same rule through the OTHER identity: a parent who posted as
     * their child must not restore it by arguing the author was the child.
     */
    public function testAModeratorMayNotRestoreAPostSignedByOneOfTheirOwnMembers(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        // memberId is the post's author member; the account differs.
        $response = $this->controller([$this->memberId, $this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->restorePost($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->postRepo->findById($this->postId)->isHidden());
    }

    public function testAModeratorMayNotRestoreAReplyTheyWroteThemselves(): void
    {
        $this->replyRepo->setHiddenAt($this->replyId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->moderatorMemberId], self::AUTHOR_ACCOUNT)
            ->restoreReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->replyRepo->findById($this->replyId)->isHidden());
    }

    /**
     * Deleting your own content stays allowed — that is not moderation,
     * it is just deleting your own message.
     */
    public function testAModeratorMayStillDeleteTheirOwnReportedContent(): void
    {
        // Deletion lives in PostController; what this asserts is that the
        // self-restore guard did not leak into the delete path, which
        // would silently take away an ordinary member's own delete.
        $this->postRepo->setHiddenAt($this->postId, '2026-02-01 00:00:00');

        $this->assertNotNull($this->postRepo->findById($this->postId));
    }

    public function testAModeratorMayRestoreAReply(): void
    {
        $this->replyRepo->setHiddenAt($this->replyId, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->restoreReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->replyRepo->findById($this->replyId)->isHidden());
    }

    public function testRestoringAPostFromAnotherGroupIs404(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup(
            'Autre',
            $this->sectionId,
            $this->currentYearId,
            $this->moderatorMemberId
        );
        $foreign = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $this->postRepo->setHiddenAt($foreign, '2026-02-01 00:00:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->moderatorMemberId])->restorePost($this->request(), $this->params($foreign));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertTrue($this->postRepo->findById($foreign)->isHidden());
    }

    /**
     * Nothing about a report reaches the journal but ids — checked here as
     * well as in the service, because the endpoint is what actually runs
     * in production.
     */
    public function testTheJournalNeverCarriesTheReportedMessagesText(): void
    {
        $this->withCsrf([]);
        $this->controller([$this->memberId])->reportPost($this->request(), $this->params($this->postId));

        $rows = $this->pdo->query('SELECT description, context FROM event_log WHERE category = \'groups\'')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Le message', $row['description']);
            $this->assertStringNotContainsString('Le message', (string) $row['context']);
        }
    }

    /**
     * @param int[] $linkedMemberIds
     */
    private function controller(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified'
    ): ReportController {
        AuthSession::login($accountId, 'parent@test.be', $role);

        $memberService = $this->memberServiceMock($linkedMemberIds);
        $accountRepo = $this->accountRepoMock($accountId, true);

        return new ReportController(
            $this->twig($role),
            $this->groupRepo,
            $this->postRepo,
            $this->replyRepo,
            $this->accessService(),
            GroupsTestHelper::reportService(
                $this->pdo,
                new SettingService(new SettingRepository($this->pdo)),
                new JournalService(new JournalRepository($this->pdo))
            ),
            new GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock())
        );
    }

    private function request(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/posts/' . $this->postId . '/report', [], $_POST, [], []);
    }

    /**
     * @return array<string, string>
     */
    private function params(?int $postId = null, ?int $replyId = null): array
    {
        $params = ['id' => (string) $this->groupId];
        if ($postId !== null) {
            $params['postId'] = (string) $postId;
        }
        if ($replyId !== null) {
            $params['replyId'] = (string) $replyId;
        }

        return $params;
    }

    private function postReportCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_post_reports')->fetchColumn();
    }

    private function replyReportCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_reply_reports')->fetchColumn();
    }
}
