<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Http\Request;
use Core\Security\AuthSession;
use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Service\GalleryException;
use Modules\Groups\Controller\ReplyController;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\ReplyService;
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
            $this->groupRepo
        );
        $stack = GroupsTestHelper::replyStack(
            $this->pdo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            $postMediaService,
            new PostAuthorResolver($memberService, $accountRepo)
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

        $this->assertSame(403, $response->getStatusCode());
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
            $this->pdo, $this->postId, 'avant', gmdate('Y-m-d H:i:s', time() - 60), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'après']);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('après', $this->replyRepo->findById($replyId)->body);
    }

    public function testTheAuthorMayNotEditOutsideTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', gmdate('Y-m-d H:i:s', time() - 16 * 60), self::AUTHOR_ACCOUNT, $this->memberId
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
            $this->pdo, $this->postId, 'avant', gmdate('Y-m-d H:i:s', time() - 60 * 60), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf([
            'body' => 'après',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'edited_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testSomeoneElseMayNotEditEvenInsideTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', gmdate('Y-m-d H:i:s', time() - 60), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => 'après']);

        $response = $this->controller([$this->memberId], self::OTHER_ACCOUNT)->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testAnEditMayNotEmptyATextOnlyReply(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'avant', gmdate('Y-m-d H:i:s', time() - 60), self::AUTHOR_ACCOUNT, $this->memberId
        );
        $this->withCsrf(['body' => '  ']);

        $this->controller([$this->memberId])->edit($this->request(), $this->params(null, $replyId));

        $this->assertSame('avant', $this->replyRepo->findById($replyId)->body);
    }

    public function testTheAuthorMayDeleteTheirOwnReplyEvenAfterTheWindow(): void
    {
        $replyId = GroupsTestHelper::createReplyAt(
            $this->pdo, $this->postId, 'à supprimer', gmdate('Y-m-d H:i:s', time() - 60 * 60), self::AUTHOR_ACCOUNT, $this->memberId
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

    public function testThePaginationEndpointReturnsOneOldestFirstPageAtATime(): void
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

        $response = $this->controller([$this->memberId])->page(
            new Request('GET', '/groups/' . $this->groupId . '/posts/' . $this->postId . '/replies', [], [], [], []),
            $this->params($this->postId)
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('réponse 1', $body);
        $this->assertStringContainsString('réponse ' . ReplyService::PAGE_SIZE, $body);
        // The page is capped, and the button to continue is present.
        $this->assertStringNotContainsString('réponse ' . (ReplyService::PAGE_SIZE + 1), $body);
        $this->assertStringContainsString('Voir plus de réponses', $body);
    }

    public function testThePaginationEndpointWalksToTheNextPageWithTheAfterCursor(): void
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

        $response = $this->controller([$this->memberId])->page(
            new Request('GET', '/groups/1/posts/1/replies', ['after' => (string) $ids[ReplyService::PAGE_SIZE - 1]], [], [], []),
            $this->params($this->postId)
        );

        $body = $response->getBody();
        $this->assertStringContainsString('réponse ' . (ReplyService::PAGE_SIZE + 1), $body);
        $this->assertStringContainsString('réponse ' . (ReplyService::PAGE_SIZE + 2), $body);
        $this->assertStringNotContainsString('réponse 1<', $body);
        // Nothing left after this page, so no button.
        $this->assertStringNotContainsString('Voir plus de réponses', $body);
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
