<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The per-reply rules: the edit window, who may delete, what counts as a
 * valid reply, and which writes bump activity.
 *
 * @group database
 */
#[Group('database')]
class ReplyServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private ReplyService $service;
    private int $groupId;
    private int $postId;

    private const AUTHOR_ACCOUNT = 7;
    private const OTHER_ACCOUNT = 8;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);

        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');

        $this->service = new ReplyService(
            $this->replyRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService(
                $this->createMock(DelegatedAlbumManager::class),
                new PostMediaRepository($this->pdo),
                $this->groupRepo,
                $this->replyRepo
            ),
            GroupsTestHelper::rateLimitService($this->pdo)
        );
    }

    private function group(): DiscussionGroup
    {
        return $this->groupRepo->findById($this->groupId);
    }

    private function context(int $accountId = self::AUTHOR_ACCOUNT): GroupSessionContext
    {
        return new GroupSessionContext($accountId, Role::IDENTIFIED, [3], 1, true);
    }

    private function replyCreatedAt(string $at): Reply
    {
        $id = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'coucou', $at, self::AUTHOR_ACCOUNT, 3);

        return $this->replyRepo->findById($id);
    }

    public function testCreatingAReplyBumpsThePostAndTheGroupActivity(): void
    {
        $before = $this->postRepo->findById($this->postId)->lastActivityAt;

        $this->service->create($this->group(), $this->postId, self::AUTHOR_ACCOUNT, 3, 'Bonjour');

        $this->assertGreaterThan($before, $this->postRepo->findById($this->postId)->lastActivityAt);
        $this->assertGreaterThan($before, $this->group()->lastActivityAt);
    }

    public function testCreatingAReplyStoresBothAuthorIdentities(): void
    {
        $id = $this->service->create($this->group(), $this->postId, self::AUTHOR_ACCOUNT, 3, 'Bonjour');

        $reply = $this->replyRepo->findById($id);
        $this->assertSame(self::AUTHOR_ACCOUNT, $reply->authorUserAccountId);
        $this->assertSame(3, $reply->authorMemberId);
    }

    /**
     * The counterpart of the post rule, and for the same reason:
     * correcting a typo must not resurrect an old conversation to the top
     * of the feed, nor postpone its later purge.
     */
    public function testEditingAReplyDoesNotBumpActivity(): void
    {
        $this->service->create($this->group(), $this->postId, self::AUTHOR_ACCOUNT, 3, 'avant');
        $activityAfterCreate = $this->postRepo->findById($this->postId)->lastActivityAt;
        $groupActivityAfterCreate = $this->group()->lastActivityAt;

        $reply = $this->replyRepo->findPage($this->postId, 1)[0];
        $this->service->edit($reply, 'après');

        $this->assertSame($activityAfterCreate, $this->postRepo->findById($this->postId)->lastActivityAt);
        $this->assertSame($groupActivityAfterCreate, $this->group()->lastActivityAt);
        $this->assertSame('après', $this->replyRepo->findById($reply->id)->body);
    }

    public function testTheAuthorMayEditInsideTheWindow(): void
    {
        $reply = $this->replyCreatedAt(Timestamps::at('-60 seconds'));

        $this->assertTrue($this->service->canEdit($reply, $this->context()));
    }

    public function testTheAuthorMayNotEditOutsideTheWindow(): void
    {
        $reply = $this->replyCreatedAt(Timestamps::at('-16 minutes'));

        $this->assertFalse($this->service->canEdit($reply, $this->context()));
    }

    public function testTheWindowIsMeasuredFromTheStoredCreatedAtNotAnythingTheClientSends(): void
    {
        // A forged "created 10 seconds ago" changes nothing, because no
        // such field is ever read: the comparison uses only the stored
        // created_at and the server's own clock.
        $reply = $this->replyCreatedAt('2020-01-01 00:00:00');
        $forgedNow = Timestamps::parse('2020-01-01 00:00:05');

        $this->assertFalse($this->service->canEdit($reply, $this->context()));
        // Passing an explicit "now" is how the test controls the clock —
        // it is not something a request can supply.
        $this->assertTrue($this->service->canEdit($reply, $this->context(), $forgedNow));
    }

    public function testTheWindowBoundaryIsInclusive(): void
    {
        $reply = $this->replyCreatedAt('2026-01-01 10:00:00');
        $exactly = Timestamps::parse('2026-01-01 10:15:00');
        $justAfter = Timestamps::parse('2026-01-01 10:15:01');

        $this->assertTrue($this->service->isWithinEditWindow($reply, $exactly));
        $this->assertFalse($this->service->isWithinEditWindow($reply, $justAfter));
    }

    public function testSomeoneElseMayNeverEditEvenInsideTheWindow(): void
    {
        $reply = $this->replyCreatedAt(Timestamps::at('-60 seconds'));

        $this->assertFalse($this->service->canEdit($reply, $this->context(self::OTHER_ACCOUNT)));
    }

    public function testTheAuthorMayDeleteEvenAfterTheEditWindow(): void
    {
        $reply = $this->replyCreatedAt(Timestamps::at('-60 minutes'));

        $this->assertTrue($this->service->canDelete($reply, $this->context(), false));
    }

    public function testAModeratorMayDeleteAnyReply(): void
    {
        $reply = $this->replyCreatedAt('2026-01-01 10:01:00');

        $this->assertTrue($this->service->canDelete($reply, $this->context(self::OTHER_ACCOUNT), true));
    }

    public function testAnOrdinaryMemberMayNotDeleteSomeoneElsesReply(): void
    {
        $reply = $this->replyCreatedAt('2026-01-01 10:01:00');

        $this->assertFalse($this->service->canDelete($reply, $this->context(self::OTHER_ACCOUNT), false));
    }

    public function testTextAloneAndImageAloneAreBothValidButNeitherIsNot(): void
    {
        $this->assertTrue($this->service->isReplyable('Bonjour'));
        $this->assertTrue($this->service->isReplyable('', true));
        $this->assertFalse($this->service->isReplyable(''));
        $this->assertFalse($this->service->isReplyable('   '));
        $this->assertFalse($this->service->isReplyable("\n\t "));
    }

    public function testNormalizeStripsControlCharactersAndBoundsTheLength(): void
    {
        $this->assertSame("a\nb", $this->service->normalize("a\r\nb"));
        $this->assertSame('ab', $this->service->normalize("a\x00b"));
        $this->assertSame(
            ReplyService::MAX_BODY_LENGTH,
            mb_strlen($this->service->normalize(str_repeat('x', ReplyService::MAX_BODY_LENGTH + 100)))
        );
    }

    public function testDeletingAReplyRemovesItsImageThroughTheDelegatedAlbum(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        // The gallery media is another module's row — no CASCADE from this
        // module can reach it, so the service must delete it explicitly.
        $album->expects($this->once())->method('deleteMedia')->with(55, 42);

        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);
        $service = new ReplyService(
            $this->replyRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService($album, new PostMediaRepository($this->pdo), $this->groupRepo, $this->replyRepo),
            GroupsTestHelper::rateLimitService($this->pdo)
        );

        $id = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'with image', '2026-01-01 10:01:00', 7, 3, 42);
        $service->delete($this->replyRepo->findById($id), $this->group());

        $this->assertNull($this->replyRepo->findById($id));
    }

    public function testDeletingATextOnlyReplyTouchesNoMedia(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        $album->expects($this->never())->method('deleteMedia');

        $service = new ReplyService(
            $this->replyRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService($album, new PostMediaRepository($this->pdo), $this->groupRepo, $this->replyRepo),
            GroupsTestHelper::rateLimitService($this->pdo)
        );

        $id = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'text only', '2026-01-01 10:01:00', 7, 3);
        $service->delete($this->replyRepo->findById($id), $this->group());

        $this->assertNull($this->replyRepo->findById($id));
    }

    public function testDeleteAllMediaForPostSweepsEveryReplyImage(): void
    {
        $album = $this->createMock(DelegatedAlbumManager::class);
        $deleted = [];
        $album->method('deleteMedia')->willReturnCallback(function (int $albumId, int $mediaId) use (&$deleted): void {
            $deleted[] = $mediaId;
        });

        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);
        $service = new ReplyService(
            $this->replyRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService($album, new PostMediaRepository($this->pdo), $this->groupRepo, $this->replyRepo),
            GroupsTestHelper::rateLimitService($this->pdo)
        );

        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'a', '2026-01-01 10:01:00', 7, 3, 11);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'b', '2026-01-01 10:02:00', 7, 3);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'c', '2026-01-01 10:03:00', 7, 3, 12);

        $service->deleteAllMediaForPost($this->group(), $this->postId);

        $this->assertSame([11, 12], $deleted);
    }
}
