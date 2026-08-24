<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\MemberService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Feed ordering and keyset pagination.
 *
 * @group database
 */
#[Group('database')]
class GroupFeedServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupFeedService $feedService;
    private PostRepository $postRepo;
    private GroupRepository $groupRepo;
    private int $groupId;
    /** The author every seeded post is signed by: one account, one member. */
    private int $authorAccountId;
    private int $authorMemberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);

        // A real account with a real membership behind it: every name this
        // module shows now resolves through the blind-index join between
        // the two (Service\MemberIdentityService), so a mocked name would
        // no longer be exercising anything.
        $author = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Marie', 'totem' => 'Akéla']]
        );
        $this->authorAccountId = $author['account_id'];
        $this->authorMemberId = $author['member_ids'][0];

        $this->feedService = $this->buildFeedService(new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo)));

        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);
    }

    private function postMediaService(): PostMediaService
    {
        return new PostMediaService(
            $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo),
            $this->groupRepo,
            new \Modules\Groups\Repository\ReplyRepository($this->pdo)
        );
    }

    private function buildFeedService(
        PostAuthorResolver $authorResolver,
        ?\Modules\Groups\Service\GroupReadStateService $readStateService = null
    ): GroupFeedService {
        $activityService = new GroupActivityService($this->groupRepo, $this->postRepo);
        $postMediaService = $this->postMediaService();
        $stack = GroupsTestHelper::replyStack($this->pdo, $activityService, $postMediaService, $authorResolver);

        return new GroupFeedService(
            $this->postRepo,
            $authorResolver,
            new PostService($this->postRepo, $activityService, GroupsTestHelper::rateLimitService($this->pdo)),
            $postMediaService,
            new PostLinkRepository($this->pdo),
            $stack['replyRepository'],
            $stack['replyPresenter'],
            $stack['reactionService'],
            $stack['reportService'],
            $readStateService
        );
    }

    /**
     * A feed that knows where this reader left off — what a collapsed
     * conversation's "nouveaux" badge is computed from. The member is
     * added to the group explicitly so GroupAccessService resolves the
     * same identity markRead() and the badge both key on.
     */
    private function feedServiceWithReadState(?int $memberId = null): GroupFeedService
    {
        $memberRepo = new \Modules\Groups\Repository\GroupMemberRepository($this->pdo);
        $memberRepo->add($this->groupId, $memberId ?? $this->authorMemberId, false, null);

        $access = new \Modules\Groups\Service\GroupAccessService(
            $memberRepo,
            new \Modules\Groups\Repository\GroupSectionRepository($this->pdo),
            new \Core\Member\SectionMembershipRepository($this->pdo)
        );

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('findDisplayNamesByMemberIds')->willReturn([3 => 'Akéla']);
        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findNamesByIds')->willReturn([7 => ['first_name' => 'Marie', 'last_name' => 'Dupont']]);

        return $this->buildFeedService(
            new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo)),
            new \Modules\Groups\Service\GroupReadStateService(
                new \Modules\Groups\Repository\GroupReadRepository($this->pdo),
                $access
            )
        );
    }

    private function context(): GroupSessionContext
    {
        return new GroupSessionContext($this->authorAccountId, Role::IDENTIFIED, [$this->authorMemberId], 1, true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return string[]
     */
    private function bodies(array $rows): array
    {
        return array_map(fn(array $row) => $row['post']->body, $rows);
    }

    /**
     * $n posts, oldest first, one minute apart — so "post 5" is always
     * newer than "post 4".
     *
     * @return int[] post ids, in creation order
     */
    private function seed(int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = GroupsTestHelper::createPostAt(
                $this->pdo,
                $this->groupId,
                'post ' . $i,
                Timestamps::parse('2026-01-01 10:00:00')
                    ->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
                $this->authorAccountId,
                $this->authorMemberId
            );
        }

        return $ids;
    }

    public function testTheStreamIsOrderedByLastActivityDescending(): void
    {
        $this->seed(3);

        $page = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);

        $this->assertSame(['post 3', 'post 2', 'post 1'], $this->bodies($page->posts));
    }

    public function testPinnedPostsComeFirstAndAreExcludedFromTheStream(): void
    {
        $ids = $this->seed(3);
        $this->postRepo->setPinned($ids[0], true); // the OLDEST post

        $page = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);

        $this->assertSame(['post 1'], $this->bodies($page->pinned));
        // Not duplicated into the stream — that is what would show it
        // twice, and what would break the cursor.
        $this->assertSame(['post 3', 'post 2'], $this->bodies($page->posts));
    }

    public function testPinningAPostMidFeedMovesItWithoutDuplicatingItAcrossPages(): void
    {
        $ids = $this->seed(GroupFeedService::PAGE_SIZE + 5);

        // Pin one that sits on page 2 of the unpinned stream.
        $this->postRepo->setPinned($ids[2], true);

        $first = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);
        $second = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false, $first->nextCursor);

        $pinnedBody = $this->postRepo->findById($ids[2])->body;
        $this->assertSame([$pinnedBody], $this->bodies($first->pinned));
        $this->assertNotContains($pinnedBody, $this->bodies($first->posts));
        $this->assertNotContains($pinnedBody, $this->bodies($second->posts));
        // And pinned posts are not repeated on page 2 either.
        $this->assertSame([], $second->pinned);
    }

    public function testKeysetPaginationWalksThreePagesWithoutGapOrRepeat(): void
    {
        $total = GroupFeedService::PAGE_SIZE * 2 + 5;
        $this->seed($total);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false, $cursor);
            $seen = array_merge($seen, $this->bodies($page->posts));
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount($total, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'no post appears on two pages');
    }

    public function testPaginationIsStableWhenANewPostArrivesMidScroll(): void
    {
        // The reason for keyset rather than OFFSET: a post landing at the
        // top between two page loads shifts every OFFSET by one, which
        // silently skips a post. The cursor is anchored to a row, so it
        // does not move.
        $this->seed(GroupFeedService::PAGE_SIZE + 3);

        $first = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);

        GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'brand new', '2026-06-01 12:00:00', 7, 3);

        $second = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false, $first->nextCursor);

        $this->assertSame([], array_intersect($this->bodies($first->posts), $this->bodies($second->posts)));
        $this->assertNotContains('brand new', $this->bodies($second->posts));
        // Nothing was skipped: page 1 + page 2 still cover every original post.
        $this->assertCount(GroupFeedService::PAGE_SIZE + 3, array_merge($this->bodies($first->posts), $this->bodies($second->posts)));
    }

    public function testTiedActivityTimestampsStillPaginateWithoutRepeating(): void
    {
        // last_activity_at alone is not unique — the cursor carries the id
        // too, or a whole batch written in the same second would loop.
        for ($i = 0; $i < GroupFeedService::PAGE_SIZE + 4; $i++) {
            GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'same second ' . $i, '2026-03-01 08:00:00', 7, 3);
        }

        $first = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);
        $second = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false, $first->nextCursor);

        $all = array_merge($this->bodies($first->posts), $this->bodies($second->posts));
        $this->assertCount(GroupFeedService::PAGE_SIZE + 4, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function testAForgedOrCorruptCursorFallsBackToTheFirstPage(): void
    {
        $this->seed(3);

        foreach (['nonsense', base64_encode('not-a-date|1'), base64_encode('2026-01-01 10:01:00|abc'), ''] as $cursor) {
            $page = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false, $cursor);
            $this->assertSame(['post 3', 'post 2', 'post 1'], $this->bodies($page->posts), 'cursor: ' . $cursor);
        }
    }

    /**
     * The account first — the human who actually wrote it — then the
     * memberships that account carries. It used to read the other way
     * round, which put a child's totem in front of a message their parent
     * had written.
     */
    public function testAuthorLabelsNameTheAccountThenItsMemberships(): void
    {
        $this->seed(1);

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame('Marie Dupont', $row['identity']['account_name']);
        $this->assertSame(['Akéla'], $row['identity']['member_names']);
    }

    /**
     * ALL of them, not merely the one the message was signed as: a parent
     * posting for one child is the same human with the same memberships,
     * and which one a given message was attributed to is stored
     * (author_member_id) rather than displayed.
     */
    public function testAnAccountWithSeveralMembersListsThemAll(): void
    {
        $parent = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'claire@example.test',
            'Claire',
            'Martin',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );
        GroupsTestHelper::createPostAt(
            $this->pdo,
            $this->groupId,
            'de la part de Léa',
            '2026-01-01 11:00:00',
            $parent['account_id'],
            $parent['member_ids'][0]
        );

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame('Claire Martin', $row['identity']['account_name']);
        $this->assertSame(['Akéla', 'Baloo'], $row['identity']['member_names']);
    }

    public function testTheModeratorFlagDecidesWhetherPinningIsOffered(): void
    {
        $this->seed(1);
        $group = $this->groupRepo->findById($this->groupId);

        $this->assertFalse($this->feedService->page($group, $this->context(), false)->posts[0]['can_pin']);
        $this->assertTrue($this->feedService->page($group, $this->context(), true)->posts[0]['can_pin']);
    }

    /**
     * The N+1 guard, now one level deeper: names resolve through
     * Service\MemberIdentityService, which batches the whole set and
     * memoises what it resolved — so a page of twenty posts by one author
     * asks for that author's account names ONCE, not twenty times.
     */
    public function testAuthorNamesAreResolvedInAFixedNumberOfQueriesWhateverThePageSize(): void
    {
        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->expects($this->once())->method('findNamesByIds')
            ->willReturn([$this->authorAccountId => ['first_name' => 'Marie', 'last_name' => 'Dupont']]);
        $accountRepo->expects($this->once())->method('findEmailBlindIndexesByIds')->willReturn([]);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('findDisplayNamesByMemberIds')->willReturn([]);

        $identityService = new \Modules\Groups\Service\MemberIdentityService(
            $accountRepo,
            new \Core\Import\MemberYearRepository($this->pdo),
            $memberService
        );
        $feedService = $this->buildFeedService(new PostAuthorResolver($identityService));

        $this->seed(GroupFeedService::PAGE_SIZE);
        $page = $feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false);

        $this->assertCount(GroupFeedService::PAGE_SIZE, $page->posts);
    }

    /**
     * A thread opens on the END of the conversation. Showing its five
     * oldest comments meant a busy post opened three weeks in the past,
     * with seven clicks between the reader and what somebody wrote this
     * morning — the one thing they came for.
     */
    public function testAThreadShowsTheMostRecentCommentsAndNotTheOldest(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        for ($i = 1; $i <= \Modules\Groups\Service\ReplyService::PAGE_SIZE + 3; $i++) {
            GroupsTestHelper::createReplyAt(
                $this->pdo,
                $postId,
                'commentaire ' . $i,
                (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
                1,
                9
            );
        }

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $bodies = array_map(static fn(array $r): string => $r['reply']->body, $row['replies']);

        // The tail of the thread, still in reading order.
        $this->assertSame('commentaire 4', $bodies[0]);
        $this->assertSame('commentaire ' . (\Modules\Groups\Service\ReplyService::PAGE_SIZE + 3), end($bodies));
        $this->assertCount(\Modules\Groups\Service\ReplyService::PAGE_SIZE, $bodies);
        // …and the count is still the real one.
        $this->assertSame(\Modules\Groups\Service\ReplyService::PAGE_SIZE + 3, $row['reply_count']);
    }

    /**
     * The cursor points backwards: "Voir les commentaires précédents"
     * resumes from the OLDEST comment on screen.
     */
    public function testTheThreadCursorResumesFromTheOldestCommentOnScreen(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        for ($i = 1; $i <= \Modules\Groups\Service\ReplyService::PAGE_SIZE + 1; $i++) {
            GroupsTestHelper::createReplyAt(
                $this->pdo,
                $postId,
                'commentaire ' . $i,
                (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
                1,
                9
            );
        }

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame($row['replies'][0]['reply']->id, $row['replies_prev_before_id']);
    }

    /**
     * Everything already fits, so there is nothing before it and no
     * button to offer.
     */
    public function testAShortThreadOffersNoCursorAtAll(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'seul commentaire', '2026-01-01 10:00:00', 1, 9);

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertNull($row['replies_prev_before_id']);
    }

    public function testAThreadCountsWhatArrivedSinceTheReaderLastOpenedTheGroup(): void
    {
        $feed = $this->feedServiceWithReadState();
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        (new \Modules\Groups\Repository\GroupReadRepository($this->pdo))
            ->markRead($this->groupId, $this->authorMemberId, '2026-01-01 11:00:00');

        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'avant', '2026-01-01 10:00:00', 1, 9);
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'après', '2026-01-01 12:00:00', 1, 9);

        $row = $feed->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame(2, $row['reply_count']);
        $this->assertSame(1, $row['new_reply_count']);
    }

    /**
     * A comment the reader wrote themselves is never new to them —
     * otherwise every thread they had just taken part in would wear a
     * badge on the next visit.
     */
    public function testAThreadNeverCountsTheReadersOwnCommentAsNew(): void
    {
        $feed = $this->feedServiceWithReadState();
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        (new \Modules\Groups\Repository\GroupReadRepository($this->pdo))
            ->markRead($this->groupId, $this->authorMemberId, '2026-01-01 11:00:00');

        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'la mienne', '2026-01-01 12:00:00', $this->authorAccountId, $this->authorMemberId);

        $row = $feed->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame(1, $row['reply_count']);
        $this->assertSame(0, $row['new_reply_count']);
    }

    /**
     * A first visit has nothing to compare against. Treating it as
     * "everything is new" would put a badge on every thread of a group
     * somebody has just joined, which says nothing and trains the reader
     * to ignore the badge.
     */
    public function testAFirstVisitMarksNothingNew(): void
    {
        $feed = $this->feedServiceWithReadState();
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'une réponse', '2026-01-01 12:00:00', 1, 9);

        $row = $feed->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame(1, $row['reply_count']);
        $this->assertSame(0, $row['new_reply_count']);
    }

    /**
     * A feed built with no read-state service at all (the collaborator is
     * optional, like every other one here) still renders — it simply
     * never says anything is new.
     */
    public function testAFeedWithNoReadStateServiceReportsNothingNew(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'sujet', '2026-01-01 09:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'une réponse', '2026-01-01 12:00:00', 1, 9);

        $row = $this->feedService->page($this->groupRepo->findById($this->groupId), $this->context(), false)->posts[0];

        $this->assertSame(0, $row['new_reply_count']);
    }

    public function testABrandNewPostCarriesNoNewCommentsAtAll(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'tout neuf', '2026-01-01 09:00:00');

        $row = $this->feedService->rowForNewPost(
            $this->groupRepo->findById($this->groupId),
            $this->postRepo->findById($postId),
            $this->context(),
            false
        );

        $this->assertSame(0, $row['new_reply_count']);
    }
}
