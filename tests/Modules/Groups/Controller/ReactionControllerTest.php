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
use Modules\Groups\Service\GroupAccessService;
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
                $this->groupRepo,
                new \Modules\Groups\Repository\ReplyRepository($this->pdo)
            ),
            new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo))
        )['reactionService'];

        return new ReactionController(
            $this->twig($role),
            $this->groupRepo,
            $this->postRepo,
            $this->replyRepo,
            $access,
            $reactionService,
            new GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock()),
            null,
            new \Modules\Groups\Service\ReactorListService(
                ReactionRepository::forPosts($this->pdo),
                ReactionRepository::forReplies($this->pdo),
                GroupsTestHelper::identityService($this->pdo)
            )
        );
    }

    private function request(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/posts/' . $this->postId . '/react', [], $_POST, [], []);
    }

    /**
     * Same request a plain form POST sends, plus the header groups.js
     * attaches to its fetch() — this is what tells the controller to
     * answer with the JSON fragment instead of its usual redirect.
     */
    private function ajaxRequest(): Request
    {
        return new Request(
            'POST',
            '/groups/' . $this->groupId . '/posts/' . $this->postId . '/react',
            [],
            $_POST,
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
        );
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

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
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
     * The dynamic-reactions path (groups.js): the same X-Requested-With
     * header a fetch() sends gets JSON back — a re-rendered reactions
     * fragment, not the redirect a plain form POST gets — so the page
     * never has to reload for a reaction.
     */
    public function testReactingViaAjaxReturnsAJsonFragmentInsteadOfARedirect(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->ajaxRequest(), $this->params($this->postId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $body = json_decode($response->getBody(), true);
        $this->assertSame('added', $body['outcome']);
        $this->assertStringContainsString('heart', $body['html']);
        $this->assertSame('heart', $this->postReactionKey($this->memberId));
    }

    /**
     * The fragment this endpoint returns REPLACES the one the page was
     * served with, tally included — so it has to carry the tally's own
     * "qui a réagi ?" URL too. It did not: Twig runs with strict_variables
     * off, so the missing variable rendered data-reactors-url="" and
     * groups.js then fetched the empty string (the current page) and hung
     * the dialog on its spinner. The path is only reachable AFTER a
     * reaction, which is exactly what this endpoint renders.
     */
    public function testTheAjaxFragmentCarriesTheReactorsUrlSoTheDialogStillOpens(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->ajaxRequest(), $this->params($this->postId));

        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString(
            'data-reactors-url="/groups/' . $this->groupId . '/posts/' . $this->postId . '/reactions"',
            $body['html']
        );
    }

    public function testTheAjaxReplyFragmentCarriesTheReplysOwnReactorsUrl(): void
    {
        $this->withCsrf(['reaction' => 'clap']);

        $response = $this->controller([$this->memberId])->reactToReply($this->ajaxRequest(), $this->params(null, $this->replyId));

        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString(
            'data-reactors-url="/groups/' . $this->groupId . '/replies/' . $this->replyId . '/reactions"',
            $body['html']
        );
    }

    public function testRemovingAReactionViaAjaxReportsRemoved(): void
    {
        $this->withCsrf(['reaction' => 'heart']);
        $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->withCsrf(['reaction' => 'heart']);
        $response = $this->controller([$this->memberId])->react($this->ajaxRequest(), $this->params($this->postId));

        $body = json_decode($response->getBody(), true);
        $this->assertSame('removed', $body['outcome']);
        $this->assertNull($this->postReactionKey($this->memberId));
    }

    public function testReactingOnAReplyViaAjaxReturnsTheCompactFragment(): void
    {
        $this->withCsrf(['reaction' => 'clap']);

        $response = $this->controller([$this->memberId])->reactToReply($this->ajaxRequest(), $this->params(null, $this->replyId));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('added', $body['outcome']);
        $this->assertStringContainsString('groups-reactions-compact', $body['html']);
    }

    public function testAnUnknownReactionKeyViaAjaxIsStill400WithNoBody(): void
    {
        $this->withCsrf(['reaction' => 'rocket']);

        $response = $this->controller([$this->memberId])->react($this->ajaxRequest(), $this->params($this->postId));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId));
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
     * The site-admin author fallback, at the route rather than in the
     * service. GroupAccessServiceTest proves authorMemberIdFor() picks a
     * linked member when the group holds none of them; what this proves is
     * that the reaction is actually RECORDED under that member — the
     * controller used to resolve its own identifier, and a reaction with
     * the wrong one lands on the wrong row of the UNIQUE (item, member)
     * index or nowhere at all.
     */
    public function testASiteAdminReactsAsALinkedMemberTheGroupDoesNotHold(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTSIDER');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$outsider], self::OTHER_ACCOUNT, 'admin')
            ->react($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('heart', $this->postReactionKey($outsider));
    }

    /**
     * The other half: nothing to sign with means the refusal, and it must
     * happen BEFORE the service is reached — a null member id written to
     * discussion_group_post_reactions is a fatal, not a 403.
     */
    public function testAnAccountWithNoMemberAtAllIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([], self::OTHER_ACCOUNT, 'admin')
            ->react($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        // The 403 page is Twig-rendered, so the apostrophe in the message
        // arrives escaped — assert what actually reaches the reader.
        $this->assertStringContainsString(
            htmlspecialchars(GroupAccessService::NO_AUTHOR_MEMBER_MESSAGE, ENT_QUOTES, 'UTF-8'),
            $response->getBody()
        );
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM discussion_group_post_reactions'
        )->fetchColumn());
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
    /**
     * Auto-hidden content is invisible to non-moderators on every read path
     * (the feed's SQL and GroupController::post()'s deep link), so it must
     * not be reactable either. Before this, a member could react to a post
     * they cannot see — notifying its author and bumping last_activity_at,
     * which postpones the retention purge of the very content moderation
     * hid, and confirming the post still exists.
     */
    public function testReactingOnAHiddenPostIs404ForAnOrdinaryMember(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-01-02 10:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->postReactionKey($this->memberId), 'no reaction may be written');
    }

    public function testAModeratorMayStillReactOnAHiddenPost(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-01-02 10:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->react($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('heart', $this->postReactionKey($this->moderatorMemberId));
    }

    public function testReactingOnAHiddenReplyIs404ForAnOrdinaryMember(): void
    {
        $this->replyRepo->setHiddenAt($this->replyId, '2026-01-02 10:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A reply is only as visible as the post it hangs off: hiding the post
     * must take its replies out of reach too.
     */
    public function testReactingOnAReplyWhoseParentPostIsHiddenIs404(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-01-02 10:00:00');
        $this->withCsrf(['reaction' => 'heart']);

        $response = $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, $this->replyId));

        $this->assertSame(404, $response->getStatusCode());
    }

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

    /**
     * "Who reacted, and with what" — the dialog behind a reaction tally's
     * own click (groups.js).
     */
    private function reactorsRequest(): Request
    {
        return new Request('GET', '/groups/' . $this->groupId . '/posts/' . $this->postId . '/reactions', [], [], [], []);
    }

    public function testPostReactorsRendersTheReactorsFragment(): void
    {
        $this->withCsrf(['reaction' => 'heart']);
        $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));

        $response = $this->controller([$this->memberId])->postReactors($this->reactorsRequest(), $this->params($this->postId));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Akéla', $body['html']);
    }

    public function testPostReactorsSaysNobodyHasReactedYet(): void
    {
        $response = $this->controller([$this->memberId])->postReactors($this->reactorsRequest(), $this->params($this->postId));

        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Personne n\'a encore réagi', $body['html']);
    }

    public function testPostReactorsIs404ForANonMemberRatherThan403(): void
    {
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER2');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)
            ->postReactors($this->reactorsRequest(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPostReactorsIs404ForAPostFromAnotherGroup(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');

        $response = $this->controller([$this->memberId])->postReactors($this->reactorsRequest(), $this->params($foreignPostId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPostReactorsIs404ForAHiddenPostSeenByAnOrdinaryMember(): void
    {
        $this->postRepo->setHiddenAt($this->postId, '2026-01-01 12:00:00');

        $response = $this->controller([$this->memberId])->postReactors($this->reactorsRequest(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAModeratorStillSeesReactorsOnAHiddenPost(): void
    {
        $this->withCsrf(['reaction' => 'heart']);
        $this->controller([$this->memberId])->react($this->request(), $this->params($this->postId));
        $this->postRepo->setHiddenAt($this->postId, '2026-01-01 12:00:00');

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->postReactors($this->reactorsRequest(), $this->params($this->postId));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Akéla', $body['html']);
    }

    public function testReplyReactorsRendersTheReactorsFragment(): void
    {
        $this->withCsrf(['reaction' => 'clap']);
        $this->controller([$this->memberId])->reactToReply($this->request(), $this->params(null, $this->replyId));

        $response = $this->controller([$this->memberId])->replyReactors(
            new Request('GET', '/groups/' . $this->groupId . '/replies/' . $this->replyId . '/reactions', [], [], [], []),
            $this->params(null, $this->replyId)
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Akéla', $body['html']);
    }

    public function testReplyReactorsIs404ForAHiddenReplySeenByAnOrdinaryMember(): void
    {
        $this->replyRepo->setHiddenAt($this->replyId, '2026-01-01 12:00:00');

        $response = $this->controller([$this->memberId])->replyReactors(
            new Request('GET', '/groups/' . $this->groupId . '/replies/' . $this->replyId . '/reactions', [], [], [], []),
            $this->params(null, $this->replyId)
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
