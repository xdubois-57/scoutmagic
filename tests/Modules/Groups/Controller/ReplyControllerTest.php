<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Http\Request;
use Core\Security\AuthSession;
use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Api\GalleryException;
use Modules\Groups\Controller\ReplyController;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * CSRF, the authorisation boundary of every reply action, the 404-not-403
 * rule, the one-image ceiling, and the "Charger plus" pagination endpoint.
 *
 * @group database
 */
#[Group('database')]
class ReplyControllerTest extends GroupsControllerTestCase
{
    /**
     * @param int[] $linkedMemberIds
     */
    private function controller(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified',
        bool $completeProfile = true,
        ?DelegatedAlbumManager $delegatedAlbumManager = null
    ): ReplyController {
        AuthSession::login($accountId, 'parent@test.be', $role);

        $memberService = $this->memberServiceMock($linkedMemberIds);
        $accountRepo = $this->accountRepoMock($accountId, $completeProfile);
        $postMediaService = new PostMediaService(
            $delegatedAlbumManager ?? $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo),
            $this->groupRepo,
            $this->replyRepo
        );
        $stack = GroupsTestHelper::replyStack(
            $this->pdo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            $postMediaService,
            new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo))
        );

        return new ReplyController(
            $this->twig($role),
            $this->groupRepo,
            $this->postRepo,
            $this->replyRepo,
            $this->accessService(),
            $stack['replyService'],
            $stack['replyPresenter'],
            $postMediaService,
            new GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock()),
            $stack['reportService']
        );
    }

    private function request(string $method = 'POST'): Request
    {
        return new Request($method, '/groups/' . $this->groupId . '/posts/' . $this->postId . '/replies', [], $_POST, [], []);
    }

    /**
     * Same request a plain form POST sends, plus the header groups.js
     * attaches to its fetch() — the reply composer's own dynamic-posting
     * path (module spec: "no reload to add a reply").
     */
    private function ajaxRequest(): Request
    {
        return new Request(
            'POST',
            '/groups/' . $this->groupId . '/posts/' . $this->postId . '/replies',
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

    /**
     * Populates $_FILES['image'] in PHP's own multi-file shape, which is
     * what Core\Http\Request::getFiles() unpacks — $count above 1 is how
     * the one-image ceiling is exercised.
     */
    private function withImageFiles(int $count): void
    {
        $entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        for ($i = 0; $i < $count; $i++) {
            $path = tempnam(sys_get_temp_dir(), 'reply_img_');
            file_put_contents($path, 'not-really-an-image');
            $entry['name'][] = 'photo' . $i . '.jpg';
            $entry['type'][] = 'image/jpeg';
            $entry['tmp_name'][] = $path;
            $entry['error'][] = UPLOAD_ERR_OK;
            $entry['size'][] = 19;
        }
        $_FILES = ['image' => $entry];
    }

    public function testCreateRejectsAMissingCsrfToken(): void
    {
        $_POST = ['body' => 'Coucou'];

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testAMemberMayReply(): void
    {
        $this->withCsrf(['body' => 'Bien reçu']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $replies = $this->replyRepo->findPage($this->postId, 10);
        $this->assertCount(1, $replies);
        $this->assertSame('Bien reçu', $replies[0]->body);
        $this->assertSame(self::AUTHOR_ACCOUNT, $replies[0]->authorUserAccountId);
        $this->assertSame($this->memberId, $replies[0]->authorMemberId);
    }

    /**
     * The site-admin author fallback on the reply path. The reaction and
     * post paths have their own; a reply writes its own author_member_id
     * row, so it needs its own proof that the borrowed member is the one
     * actually recorded.
     */
    public function testASiteAdminRepliesAsALinkedMemberTheGroupDoesNotHold(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTSIDER');
        $this->withCsrf(['body' => 'Vu, merci']);

        $response = $this->controller([$outsider], self::OTHER_ACCOUNT, 'admin')
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $replies = $this->replyRepo->findPage($this->postId, 10);
        $this->assertCount(1, $replies);
        $this->assertSame($outsider, $replies[0]->authorMemberId);
    }

    /**
     * And the refusal, before anything is written. author_member_id is NOT
     * NULL, so a null reaching the insert is a fatal rather than a 403 —
     * and the media is attached BEFORE the reply row, so a refusal that
     * came too late would leave an orphaned upload behind.
     */
    public function testAnAccountWithNoMemberCannotReplyAndNothingIsWritten(): void
    {
        $this->withCsrf(['body' => 'Vu, merci']);

        $response = $this->controller([], self::OTHER_ACCOUNT, 'admin')
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            htmlspecialchars(GroupAccessService::NO_AUTHOR_MEMBER_MESSAGE, ENT_QUOTES, 'UTF-8'),
            $response->getBody()
        );
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    /**
     * The dynamic-reply path (groups.js): the same X-Requested-With
     * header a fetch() sends gets JSON back — a rendered reply-card
     * fragment, not the redirect a plain form POST gets — so replying
     * never has to reload the page.
     */
    public function testReplyingViaAjaxReturnsAJsonFragmentInsteadOfARedirect(): void
    {
        $this->withCsrf(['body' => 'Bien reçu']);

        $response = $this->controller([$this->memberId])->create($this->ajaxRequest(), $this->params($this->postId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Bien reçu', $body['html']);
        $this->assertCount(1, $this->replyRepo->findPage($this->postId, 10));
    }

    public function testAnEmptyReplyViaAjaxIs400WithNoFlashMessage(): void
    {
        $this->withCsrf(['body' => '   ']);

        $response = $this->controller([$this->memberId])->create($this->ajaxRequest(), $this->params($this->postId));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
        $this->assertArrayNotHasKey('_flash_message', $_SESSION);
    }

    public function testEditingAReplyViaAjaxReturnsTheReRenderedCard(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'Avant', Timestamps::at('-60 seconds'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'Après correction']);

        $response = $this->controller([$this->memberId])->edit($this->ajaxRequest(), $this->params(null, $replyId));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Après correction', $body['html']);
        $this->assertSame('Après correction', $this->replyRepo->findById($replyId)->body);
    }

    public function testDeletingAReplyViaAjaxAcknowledgesInsteadOfRedirecting(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'À supprimer', Timestamps::at('-60 seconds'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->delete($this->ajaxRequest(), $this->params(null, $replyId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['deleted' => true], json_decode($response->getBody(), true));
        $this->assertNull($this->replyRepo->findById($replyId));
    }

    /**
     * The AJAX path must not become a way around the 15-minute window —
     * the same 403 a plain form POST gets.
     */
    public function testEditingAReplyViaAjaxStillRespectsTheEditWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'Trop vieux', Timestamps::at('-30 minutes'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'Tentative']);

        $response = $this->controller([$this->memberId])->edit($this->ajaxRequest(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Trop vieux', $this->replyRepo->findById($replyId)->body);
    }

    public function testTooManyReplyImagesViaAjaxReturnsTheErrorAsJson(): void
    {
        $this->withImageFiles(2);
        $this->withCsrf(['body' => 'Coucou']);

        $response = $this->controller([$this->memberId])->create($this->ajaxRequest(), $this->params($this->postId));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('une seule image', $body['error']);
    }

    public function testCreateIs404ForANonMemberRatherThan403(): void
    {
        $this->withCsrf(['body' => 'Coucou']);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)->create($this->request(), $this->params($this->postId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testReplyingToAPostFromAnotherGroupIs404(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $this->withCsrf(['body' => 'Coucou']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params($foreignPostId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateIsRefusedOnAClosedGroupServerSide(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2026-02-01 00:00:00');
        $this->withCsrf(['body' => 'Coucou']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testCreateIsRefusedWithAnIncompleteProfile(): void
    {
        $this->withCsrf(['body' => 'Coucou']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', false)
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testAReplyWithNeitherTextNorImageSavesNothing(): void
    {
        $this->withCsrf(['body' => '   ']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testAnImageOnlyReplyIsValid(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        $album->method('ensureAlbum')->willReturn(new DelegatedAlbum(55, 'Louveteaux', '2026-01-01'));
        $album->method('addMedia')->willReturn(new DelegatedMedia(77, 'photo', 'pending', 0, 'photo0.jpg', '2026-01-01 10:00:00'));
        $this->withCsrf(['body' => '']);
        $this->withImageFiles(1);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $album)
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $replies = $this->replyRepo->findPage($this->postId, 10);
        $this->assertCount(1, $replies);
        $this->assertSame('', $replies[0]->body);
        $this->assertSame(77, $replies[0]->galleryMediaId);
    }

    /**
     * More than one image is rejected whole rather than silently keeping
     * the first — the same posture as a post's own media ceiling.
     */
    public function testASecondImageIsRejectedWithoutCreatingTheReply(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        $album->expects($this->never())->method('addMedia');
        $this->withCsrf(['body' => 'deux images']);
        $this->withImageFiles(2);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $album)
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testARefusedUploadLeavesNoReplyBehind(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        $album->method('ensureAlbum')->willReturn(new DelegatedAlbum(55, 'Louveteaux', '2026-01-01'));
        $album->method('addMedia')->willThrowException(new GalleryException('Type de fichier non autorisé.'));
        $this->withCsrf(['body' => 'avec image']);
        $this->withImageFiles(1);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $album)
            ->create($this->request(), $this->params($this->postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->replyRepo->countForPost($this->postId));
    }

    public function testTheAuthorMayEditInsideTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', Timestamps::at('-60 seconds'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'après']);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('après', $this->replyRepo->findById($replyId)->body);
    }

    public function testTheAuthorMayNotEditOutsideTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', Timestamps::at('-16 minutes'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'après']);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    /**
     * The window is recomputed server-side from the stored created_at, so
     * a client-supplied "created_at" in the request body is simply never
     * read — an expired reply stays expired however the request is dressed
     * up.
     */
    public function testAForgedClientSuppliedTimestampCannotReopenTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', Timestamps::at('-60 minutes'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([
            'body' => 'après',
            'created_at' => Timestamps::now(),
            'edited_at' => Timestamps::now(),
        ]);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testSomeoneElseMayNotEditEvenInsideTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', Timestamps::at('-60 seconds'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'après']);

        $response = $this->controller([$this->memberId], self::OTHER_ACCOUNT)->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testAnEditMayNotEmptyATextOnlyReply(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', Timestamps::at('-60 seconds'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => '  ']);

        $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testTheAuthorMayDeleteTheirOwnReplyEvenAfterTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'à supprimer', Timestamps::at('-60 minutes'), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->delete($this->request(), $this->params(null, $replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->replyRepo->findById($replyId));
    }

    public function testAModeratorMayDeleteAnyReply(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'à supprimer', '2026-01-01 10:01:00', self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([]);

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->delete($this->request(), $this->params(null, $replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->replyRepo->findById($replyId));
    }

    /**
     * Same rule as a post's: a moderator removing someone else's reply is
     * recorded, with ids only and never the reply's text.
     */
    public function testAModeratorsReplyDeletionIsJournaledWithIdsOnly(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'à supprimer', '2026-01-01 10:01:00', self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([]);

        $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->delete($this->request(), $this->params(null, $replyId));

        $rows = $this->pdo
            ->query("SELECT event_type, description, context FROM event_log WHERE category = 'groups'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(['group_reply_moderator_deleted'], array_column($rows, 'event_type'));
        $this->assertStringNotContainsString('à supprimer', (string) $rows[0]['context']);
        $this->assertSame(
            ['group_id' => $this->groupId, 'reply_id' => $replyId],
            json_decode((string) $rows[0]['context'], true)
        );
    }

    public function testAnOrdinaryMemberMayNotDeleteSomeoneElsesReply(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'pas la vôtre', '2026-01-01 10:01:00', self::AUTHOR_ACCOUNT, $this->memberId
        );
        $other = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'OTHER', $this->sectionId, $this->currentYearId);
        $this->withCsrf([]);

        $response = $this->controller([$other], self::OTHER_ACCOUNT)->delete($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->replyRepo->findById($replyId));
    }

    public function testAReplyFromAnotherGroupIs404EvenForAMemberOfBoth(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $foreignReplyId = GroupsTestHelper::createReplyAt($this->pdo, $foreignPostId, 'Ailleurs aussi', '2026-01-01 10:01:00');
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->delete($this->request(), $this->params(null, $foreignReplyId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($this->replyRepo->findById($foreignReplyId));
    }

    public function testDeletingAReplyRemovesItsReactionsByCascade(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avec réactions', '2026-01-01 10:01:00', self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->pdo->prepare(
            'INSERT INTO discussion_group_reply_reactions (reply_id, member_id, reaction_key, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$replyId, $this->memberId, 'heart', '2026-01-01 10:02:00']);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->withCsrf([]);

        $this->controller([$this->memberId])->delete($this->request(), $this->params(null, $replyId));

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_reply_reactions')->fetchColumn());
    }

    /**
     * A cursor is not optional here: this endpoint only ever fetches what
     * came BEFORE something already on screen, and the feed renders the
     * end of the thread itself. A request with no cursor is a malformed
     * request, not a request for the first page.
     */
    public function testThePaginationEndpointRefusesARequestWithNoCursor(): void
    {
        $response = $this->controller([$this->memberId])->page(
            new Request('GET', '/groups/1/posts/1/replies', [], [], [], []),
            $this->params($this->postId)
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testThePaginationEndpointReturnsThePageBeforeTheCursor(): void
    {
        for ($i = 1; $i <= ReplyService::PAGE_SIZE + 2; $i++) {
            GroupsTestHelper::createReplyAt(
                $this->pdo,
                $this->postId,
                'réponse ' . $i,
                (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
                self::AUTHOR_ACCOUNT,
                $this->memberId
            );
        }

        // The thread already shows the last PAGE_SIZE; this asks for what
        // sits immediately before them.
        $ids = $this->pdo->query('SELECT id FROM discussion_group_replies ORDER BY id ASC')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $oldestOnScreen = (int) $ids[2];

        $response = $this->controller([$this->memberId])->page(
            new Request('GET', '/groups/1/posts/1/replies', ['before' => (string) $oldestOnScreen], [], [], []),
            $this->params($this->postId)
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('réponse 1', $body);
        $this->assertStringContainsString('réponse 2', $body);
        // Nothing older than these two, so no button to go further back.
        $this->assertStringNotContainsString('Voir les commentaires précédents', $body);
    }

    public function testThePaginationEndpointCapsThePageAndOffersToGoFurtherBack(): void
    {
        $ids = [];
        for ($i = 1; $i <= ReplyService::PAGE_SIZE + 2; $i++) {
            $ids[] = GroupsTestHelper::createReplyAt(
                $this->pdo,
                $this->postId,
                'réponse ' . $i,
                (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
                self::AUTHOR_ACCOUNT,
                $this->memberId
            );
        }

        // Ask from the very end, so a full page comes back with more
        // still behind it.
        $response = $this->controller([$this->memberId])->page(
            new Request('GET', '/groups/1/posts/1/replies', ['before' => (string) $ids[count($ids) - 1]], [], [], []),
            $this->params($this->postId)
        );

        $body = $response->getBody();
        // The page holds the PAGE_SIZE replies immediately before the
        // cursor — and never reaches the very first one, which is what
        // the button is for.
        $this->assertStringContainsString('réponse ' . (ReplyService::PAGE_SIZE + 1), $body);
        $this->assertStringContainsString('réponse 2', $body);
        $this->assertStringNotContainsString('réponse 1<', $body);
        $this->assertStringContainsString('Voir les commentaires précédents', $body);
    }

    public function testThePaginationEndpointIs404ForANonMember(): void
    {
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger], self::OTHER_ACCOUNT)->page(
            new Request('GET', '/groups/1/posts/1/replies', [], [], [], []),
            $this->params($this->postId)
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
