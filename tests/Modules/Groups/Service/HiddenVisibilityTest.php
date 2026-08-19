<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Service\ReportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * What "hidden" actually means on every read path — the property the
 * module spec insists on: the hidden state is enforced IN THE QUERY, not
 * by leaving the row out of the markup. A row a member must not see never
 * comes back from the database for them at all, so there is no rendered
 * copy to reveal by reading the page source, and none in the feed, in a
 * reply list, in a reply count, or in the group gallery.
 *
 * A moderator sees exactly the same rows plus the hidden ones.
 *
 * @group database
 */
#[Group('database')]
class HiddenVisibilityTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private ReportService $reportService;
    private GroupFeedService $feedService;
    private PostMediaService $postMediaService;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);

        $this->reportService = GroupsTestHelper::reportService(
            $this->pdo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );

        $memberService = $this->createStub(MemberService::class);
        $memberService->method('findDisplayNamesByMemberIds')->willReturn([3 => 'Akéla', 4 => 'Baloo']);
        $accountRepo = $this->createStub(UserAccountRepository::class);
        $accountRepo->method('findNamesByIds')->willReturn([7 => ['first_name' => 'Marie', 'last_name' => 'Dupont']]);
        $authorResolver = new PostAuthorResolver($memberService, $accountRepo);

        $activityService = new GroupActivityService($this->groupRepo, $this->postRepo);
        $this->postMediaService = new PostMediaService(
            $this->albumManager(),
            new PostMediaRepository($this->pdo),
            $this->groupRepo,
            $this->replyRepo
        );
        $stack = GroupsTestHelper::replyStack(
            $this->pdo,
            $activityService,
            $this->postMediaService,
            $authorResolver,
            $this->reportService
        );

        $this->feedService = new GroupFeedService(
            $this->postRepo,
            $authorResolver,
            new PostService($this->postRepo, $activityService, GroupsTestHelper::rateLimitService($this->pdo)),
            $this->postMediaService,
            new PostLinkRepository($this->pdo),
            $this->replyRepo,
            $stack['replyPresenter'],
            $stack['reactionService'],
            $this->reportService
        );
    }

    public function testAHiddenPostIsAbsentFromTheFeedForAMemberAndPresentForAModerator(): void
    {
        $visible = $this->post('visible', '2026-01-01 10:00:00');
        $hidden = $this->post('offensant', '2026-01-01 11:00:00');
        $this->hidePost($hidden);

        $this->assertSame(['visible'], $this->feedBodies(false));
        $this->assertSame(['offensant', 'visible'], $this->feedBodies(true));
        $this->assertSame($visible, $this->feedService->page($this->group(), $this->context(), false)->posts[0]['post']->id);
    }

    public function testAHiddenPinnedPostIsAbsentForAMemberToo(): void
    {
        // Pinned posts come from a second query — one an implementation
        // that only filtered the stream would quietly leave unfiltered,
        // and pinned posts sit at the very top of the page.
        $pinned = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'épinglé', '2026-01-01 09:00:00', 7, 3, true);
        $this->hidePost($pinned);

        $this->assertSame([], $this->feedService->page($this->group(), $this->context(), false)->pinned);
        $this->assertCount(1, $this->feedService->page($this->group(), $this->context(), true)->pinned);
    }

    public function testTheModeratorsFlagsCarryTheHiddenStateAndTheCount(): void
    {
        $id = $this->post('offensant', '2026-01-01 10:00:00');
        $this->reportService->reportPost($this->groupId, $id, 5);
        $this->reportService->reportPost($this->groupId, $id, 6);

        $row = $this->feedService->page($this->group(), $this->context(), true)->posts[0];
        $this->assertTrue($row['is_hidden']);
        $this->assertSame(2, $row['report_count']);
    }

    /**
     * Belt and braces: even if a hidden row somehow reached a
     * non-moderator's template, the flags it would render with say
     * nothing about the hidden state — that is what keeps "hidden" from
     * being a CSS class.
     */
    public function testAMemberNeverSeesTheHiddenFlagNorTheReportCount(): void
    {
        $id = $this->post('offensant', '2026-01-01 10:00:00');
        $this->reportService->reportPost($this->groupId, $id, 5);

        $row = $this->feedService->page($this->group(), $this->context(), false)->posts[0];
        $this->assertFalse($row['is_hidden']);
        $this->assertSame(0, $row['report_count']);
    }

    public function testAHiddenReplyIsAbsentFromThePostsRepliesForAMember(): void
    {
        $postId = $this->post('hello', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'ok', '2026-01-01 10:01:00', 7, 3);
        $hidden = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'offensant', '2026-01-01 10:02:00', 7, 4);
        $this->hideReply($hidden);

        $this->assertSame(['ok'], $this->replyBodies($postId, false));
        $this->assertSame(['ok', 'offensant'], $this->replyBodies($postId, true));
    }

    /**
     * The count is what the "N réponses" label and the "Voir plus"
     * button are computed from, so a hidden reply left in it would
     * advertise its own existence.
     */
    public function testAHiddenReplyIsAbsentFromTheReplyCountForAMember(): void
    {
        $postId = $this->post('hello', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'ok', '2026-01-01 10:01:00', 7, 3);
        $hidden = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'offensant', '2026-01-01 10:02:00', 7, 4);
        $this->hideReply($hidden);

        $this->assertSame(1, $this->replyRepo->countForPost($postId));
        $this->assertSame(2, $this->replyRepo->countForPost($postId, true));

        $memberRow = $this->feedService->page($this->group(), $this->context(), false)->posts[0];
        $this->assertSame(1, $memberRow['reply_count']);
        $this->assertCount(1, $memberRow['replies']);

        $moderatorRow = $this->feedService->page($this->group(), $this->context(), true)->posts[0];
        $this->assertSame(2, $moderatorRow['reply_count']);
    }

    public function testEveryReplyOfAHiddenPostGoesWithIt(): void
    {
        $postId = $this->post('offensant', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'réponse', '2026-01-01 10:01:00', 7, 3);
        $this->hidePost($postId);

        // The post is gone from the feed, so its replies are unreachable
        // with it — there is no page left that would render them.
        $this->assertSame([], $this->feedBodies(false));
    }

    public function testTheGroupGalleryDropsTheMediaOfAHiddenPost(): void
    {
        $visible = $this->post('visible', '2026-01-01 10:00:00');
        $hidden = $this->post('offensant', '2026-01-01 11:00:00');
        $this->attachMedia($visible, 11);
        $this->attachMedia($hidden, 12);
        $this->hidePost($hidden);
        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);

        $this->assertSame([11], $this->galleryIds(false));
        $this->assertSame([12, 11], $this->galleryIds(true));
    }

    public function testTheGroupGalleryDropsTheImageOfAHiddenReply(): void
    {
        $postId = $this->post('hello', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'ok', '2026-01-01 10:01:00', 7, 3, 11);
        $hidden = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'offensant', '2026-01-01 10:02:00', 7, 4, 12);
        $this->hideReply($hidden);
        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);

        $this->assertSame([11], $this->galleryIds(false));
    }

    public function testTheGroupGalleryDropsAReplysImageWhenItsPostIsHidden(): void
    {
        $postId = $this->post('offensant', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'réponse', '2026-01-01 10:01:00', 7, 3, 12);
        $this->hidePost($postId);
        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);

        // The reply itself is not hidden — its post is, which is enough:
        // nobody can reach the reply, so its picture (12) must not linger.
        // Media 11 belongs to nothing in this module and stays: the album
        // is gallery's, and this filter only ever subtracts.
        $this->assertSame([11], $this->galleryIds(false));
    }

    public function testRestoringAPostBringsItBackForEveryone(): void
    {
        $id = $this->post('offensant', '2026-01-01 10:00:00');
        $this->attachMedia($id, 12);
        $this->reportService->reportPost($this->groupId, $id, 5);
        $this->reportService->reportPost($this->groupId, $id, 6);
        $this->groupRepo->setGalleryAlbumId($this->groupId, 55);

        $this->assertSame([], $this->feedBodies(false));
        $this->assertSame([11], $this->galleryIds(false));

        $this->reportService->restorePost($this->groupId, $id, 99);

        $this->assertSame(['offensant'], $this->feedBodies(false));
        $this->assertSame([12, 11], $this->galleryIds(false));
    }

    private function post(string $body, string $at): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, $body, $at, 7, 3);
    }

    private function hidePost(int $id): void
    {
        $this->postRepo->setHiddenAt($id, '2026-02-01 00:00:00');
    }

    private function hideReply(int $id): void
    {
        $this->replyRepo->setHiddenAt($id, '2026-02-01 00:00:00');
    }

    private function attachMedia(int $postId, int $mediaId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_post_media (post_id, gallery_media_id, sort_order) VALUES (?, ?, 0)'
        );
        $stmt->execute([$postId, $mediaId]);
    }

    /**
     * @return string[]
     */
    private function feedBodies(bool $canModerate): array
    {
        return array_map(
            fn(array $row) => $row['post']->body,
            $this->feedService->page($this->group(), $this->context(), $canModerate)->posts
        );
    }

    /**
     * @return string[]
     */
    private function replyBodies(int $postId, bool $includeHidden): array
    {
        return array_map(
            fn($reply) => $reply->body,
            $this->replyRepo->findPage($postId, 50, null, $includeHidden)
        );
    }

    /**
     * @return int[]
     */
    private function galleryIds(bool $canModerate): array
    {
        return array_map(
            fn(DelegatedMedia $media) => $media->id,
            $this->postMediaService->groupGalleryMedia($this->group(), $canModerate)
        );
    }

    private function albumManager(): DelegatedAlbumManager
    {
        $manager = $this->createStub(DelegatedAlbumManager::class);
        $manager->method('listMedia')->willReturn([
            new DelegatedMedia(11, 'image', 'ready', 0, 'a.jpg', '2026-01-01 10:00:00'),
            new DelegatedMedia(12, 'image', 'ready', 1, 'b.jpg', '2026-01-01 11:00:00'),
        ]);

        return $manager;
    }

    private function group(): DiscussionGroup
    {
        return $this->groupRepo->findById($this->groupId);
    }

    private function context(): GroupSessionContext
    {
        return new GroupSessionContext(7, Role::IDENTIFIED, [3], 1, true);
    }
}
