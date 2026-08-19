<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Http\Request;
use Core\Security\AuthSession;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\Controller\ReactionController;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * CSRF, the write-permission boundary, and — the reason this endpoint gets
 * its own file — the 404-not-403 rule that stops it becoming an oracle for
 * enumerating post ids across groups.
 *
 * @group database
 */
#[Group('database')]
class ReactionControllerTest extends GroupsControllerTestCase
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

    /**
     * @param int[] $linkedMemberIds
     */
    private function controller(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified',
        bool $completeProfile = true
    ): ReactionController {
        AuthSession::login($accountId, 'parent@test.be', $role);

        $memberService = $this->memberServiceMock($linkedMemberIds);
        $accountRepo = $this->accountRepoMock($accountId, $completeProfile);
        $access = $this->accessService();

        $reactionService = GroupsTestHelper::replyStack(
            $this->pdo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService(
                $this->createMock(DelegatedAlbumManager::class),
                new PostMediaRepository($this->pdo),
                $this->groupRepo
            ),
            new PostAuthorResolver($memberService, $accountRepo)
        )['reactionService'];

        return new ReactionController(
            $this->twig($role),
            $this->groupRepo,
            $this->postRepo,
            $this->replyRepo,
            $access,
            $reactionService,
            new GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock())
        );
    }

    private function request(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/posts/' . $this->postId . '/react', [], $_POST, [], []);
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

    private function postReactionKey(int $memberId): ?string
    {
        return ReactionRepository::forPosts($this->pdo)->findKeyFor($this->postId, [$memberId]);
    }

    public function testReactRejectsAMissingCsrfToken(): void
    {
        $_POST = ['reaction' => 'heart'];

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId));
    }

    public function testAMemberMayReactOnAPost(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('heart', $this->postReactionKey($this->memberId));
    }

    public function testReactingTwiceWithTheSameKeyRemovesIt(): void
    {
        $this->withCsrf(['reaction' => 'heart']);
        $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));
        $this->withCsrf(['reaction' => 'heart']);
        $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertNull($this->postReactionKey($this->memberId));
    }

    public function testAMemberMayReactOnAReply(): void
    {
        $this->withCsrf(['reaction' => 'clap']);

        $response = $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('clap', ReactionRepository::forReplies($this->pdo)->findKeyFor($this->replyId, [$this->memberId]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'unknown key' => ['rocket'];
        yield 'empty' => [''];
        yield 'raw emoji' => ['👍'];
        yield 'case mismatch' => ['HEART'];
    }

    #[DataProvider('invalidKeys')]
    public function testAnUnknownReactionKeyIsRejectedWithoutWriting(string $key): void
    {
        $this->withCsrf(['reaction' => $key]);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId));
    }

    public function testAMissingReactionFieldIsRejected(): void
    {
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * The oracle guard. A non-member must not be able to tell "this post
     * id exists in a group I cannot see" from "this post id does not
     * exist" — both are 404, never 403.
     */
    public function testReactIs404ForANonMemberRatherThan403(): void
    {
        $this->withCsrf(['reaction' => 'heart']);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)->react($this->request(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($stranger));
    }

    public function testReactIs404ForAnUnknownGroupToo(): void
    {
        // Same status as the case above, which is the entire point: the
        // two are indistinguishable from outside.
        $this->withCsrf(['reaction' => 'heart']);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)
            ->react($this->request(), ['id' => '9999', 'postId' => (string) $this->postId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The same enumeration risk one level in: a post that exists but
     * belongs to a DIFFERENT group than the one in the URL must be a 404,
     * because the group in the URL is what was authorised.
     */
    public function testReactingOnAPostFromAnotherGroupIs404EvenForAMemberOfBoth(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($foreignPostId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_post_reactions')->fetchColumn());
    }

    public function testReactingOnAReplyFromAnotherGroupIs404(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $foreignReplyId = GroupsTestHelper::createReplyAt($this->pdo, $foreignPostId, 'Ailleurs aussi', '2026-01-01 10:01:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, $foreignReplyId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReactingOnAnUnknownPostIs404(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params(99999));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReactingOnAnUnknownReplyIs404(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, 99999));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A closed group refuses a reaction exactly as it refuses a post —
     * 403 here rather than 404, because the caller IS a member and already
     * knows the group exists.
     */
    public function testReactIsRefusedOnAClosedGroup(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2026-02-01 00:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId));
    }

    public function testReactIsRefusedWithAnIncompleteProfile(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', false)
            ->react($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId));
    }
}
