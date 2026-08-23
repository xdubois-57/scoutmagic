<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Api;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\Notification\NotificationRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\Groups\Api\HomeActivityService;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The homepage hook (Core\Module\HomeGroupActivityProvider): one
 * banner-shaped summary — group count plus three counters (new posts,
 * replies-or-reactions, mentions of the caller).
 *
 * The things worth proving here: it never leaks a group the caller
 * cannot read (the same rule every route in this module enforces), an
 * anonymous visitor gets null rather than an error, the caller's own
 * words are never counted as news to them, and every counter goes back
 * to zero once the group has actually been opened — the mention counter
 * included, even though its raw source (the notification bell) does not
 * clear on a group visit.
 *
 * @group database
 */
#[Group('database')]
class HomeActivityServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupReadRepository $readRepo;
    private GroupService $groupService;
    private GroupRepository $groupRepo;
    private int $currentYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->readRepo = new GroupReadRepository($this->pdo);
        $this->groupService = new GroupService(
            $this->groupRepo,
            new GroupSectionRepository($this->pdo),
            new GroupMemberRepository($this->pdo)
        );

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    /**
     * @param int[] $linkedMemberIds
     */
    private function service(array $linkedMemberIds): HomeActivityService
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('getLinkedMembers')->willReturn(array_map(
            fn(int $id) => new MemberProfile(
                $id * 100, $id, 'DESK' . $id, 'Marie', 'Dupont', 'Akéla',
                null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
            ),
            $linkedMemberIds
        ));

        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findById')->willReturn(
            new UserAccount(1, 'parent@test.be', 'Marie', 'Dupont', null, false, null)
        );

        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(
            new EffectiveScoutYear($this->currentYearId, '2025-2026', null)
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new HomeActivityService(
            new GroupListService(
                $this->groupRepo,
                new GroupSectionRepository($this->pdo),
                new GroupMemberRepository($this->pdo),
                new SectionMembershipRepository($this->pdo),
                $this->readRepo
            ),
            new GroupSessionContextFactory($memberService, $accountRepo, $resolver),
            $this->readRepo,
            new PostRepository($this->pdo),
            new ReplyRepository($this->pdo),
            ReactionRepository::forPosts($this->pdo),
            ReactionRepository::forReplies($this->pdo),
            new NotificationRepository($this->pdo, $encryption)
        );
    }

    private function withActivity(int $groupId, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $groupId]);
    }

    private function reactionOnPost(int $postId, int $memberId, string $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_post_reactions (post_id, member_id, reaction_key, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$postId, $memberId, 'thumbs_up', $at]);
    }

    private function mentionNotification(int $userAccountId, int $groupId, int $postId, string $at, bool $read = false): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, member_id, type_id, title, body, url, created_at, read_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            GroupNotificationService::TYPE_MENTIONED,
            'x', 'x',
            '/groups/' . $groupId . '/posts/' . $postId,
            $at,
            $read ? $at : null,
        ]);
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'H1');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertNull($this->service([$creator])->getHomeActivitySummaryForCurrentUser());
    }

    public function testAMemberGetsCountsForTheirUnreadGroup(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H2');
        $other = GroupsTestHelper::createMember($this->pdo, 'H8');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 00:00:00');

        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Salut', '2030-01-01 12:00:00', 2, $other);
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Re', '2030-01-01 12:05:00', 2, $other);
        $this->reactionOnPost($postId, $other, '2030-01-01 12:10:00');
        $this->withActivity($groupId, '2030-01-01 12:10:00');

        $summary = $this->service([$creator])->getHomeActivitySummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertSame(1, $summary['group_count']);
        $this->assertSame(['id' => $groupId, 'name' => 'Groupe de travail'], $summary['single_group']);
        $this->assertSame(1, $summary['new_posts']);
        $this->assertSame(2, $summary['new_replies_or_reactions']);
        $this->assertSame(0, $summary['new_mentions']);
    }

    /**
     * "Something new in your groups" must never be the reader's own
     * words: posts, replies and reactions by the caller's own linked
     * members are excluded from every counter.
     */
    public function testTheCallersOwnActivityIsNeverCounted(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H3');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 00:00:00');

        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Mon message', '2030-01-01 12:00:00', 1, $creator);
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Ma réponse', '2030-01-01 12:05:00', 1, $creator);
        $this->reactionOnPost($postId, $creator, '2030-01-01 12:10:00');
        $this->withActivity($groupId, '2030-01-01 12:10:00');

        $summary = $this->service([$creator])->getHomeActivitySummaryForCurrentUser();

        // The group still shows as active (last_activity_at moved), but
        // every counter is zero — the banner then renders without its
        // counter line rather than announcing the caller's own words.
        $this->assertNotNull($summary);
        $this->assertSame(0, $summary['new_posts']);
        $this->assertSame(0, $summary['new_replies_or_reactions']);
    }

    public function testAGroupAlreadyOpenedProducesNothing(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H4');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:01');

        $this->assertNull($this->service([$creator])->getHomeActivitySummaryForCurrentUser());
    }

    /**
     * The whole point of routing this through GroupListService: a group
     * the caller is not a member of can never reach the homepage, exactly
     * as it can never reach the group list.
     */
    public function testAGroupTheCallerIsNotAMemberOfIsNeverCounted(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $stranger = GroupsTestHelper::createMember($this->pdo, 'H5');
        $someoneElse = GroupsTestHelper::createMember($this->pdo, 'H6');
        $groupId = $this->groupService->createInvitationGroup('Pas le vôtre', null, $someoneElse);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertNull($this->service([$stranger])->getHomeActivitySummaryForCurrentUser());
    }

    public function testSeveralUnreadGroupsAggregateAndDropTheSingleGroupShortcut(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H7');
        $other = GroupsTestHelper::createMember($this->pdo, 'H9');
        $a = $this->groupService->createInvitationGroup('Groupe A', null, $creator);
        $b = $this->groupService->createInvitationGroup('Groupe B', null, $creator);
        $this->readRepo->markRead($a, $creator, '2030-01-01 00:00:00');
        $this->readRepo->markRead($b, $creator, '2030-01-01 00:00:00');
        GroupsTestHelper::createPostAt($this->pdo, $a, 'Un', '2030-01-01 12:00:00', 2, $other);
        GroupsTestHelper::createPostAt($this->pdo, $b, 'Deux', '2030-01-01 13:00:00', 2, $other);
        $this->withActivity($a, '2030-01-01 12:00:00');
        $this->withActivity($b, '2030-01-01 13:00:00');

        $summary = $this->service([$creator])->getHomeActivitySummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary['group_count']);
        $this->assertNull($summary['single_group']);
        $this->assertSame(2, $summary['new_posts']);
    }

    /**
     * A past-year group is a read-only archive — counting it as "du
     * nouveau" would be a call to action with nowhere to go.
     */
    public function testAnArchivedGroupIsNeverCounted(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'H10', $this->sectionId, $pastYearId);
        $groupId = $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $pastYearId, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertNull($this->service([$creator])->getHomeActivitySummaryForCurrentUser());
    }

    /**
     * The mention counter clears the way the other two do: an unread
     * bell notification about a mention only counts while its group is
     * still unread and the mention is newer than the group's last-read
     * time. Opening the group zeroes it even though the bell row itself
     * stays unread.
     */
    public function testMentionsCountUnreadNotificationsButOnlyUntilTheGroupIsOpened(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H11');
        $other = GroupsTestHelper::createMember($this->pdo, 'H12');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 00:00:00');
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, '@Marie regarde', '2030-01-01 12:00:00', 2, $other);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        // One live mention, one already read in the bell, one predating
        // the member's last visit: only the first counts.
        $this->mentionNotification(1, $groupId, $postId, '2030-01-01 12:00:00');
        $this->mentionNotification(1, $groupId, $postId, '2030-01-01 12:01:00', read: true);
        $this->mentionNotification(1, $groupId, $postId, '2029-12-31 23:00:00');

        $summary = $this->service([$creator])->getHomeActivitySummaryForCurrentUser();
        $this->assertNotNull($summary);
        $this->assertSame(1, $summary['new_mentions']);

        // Open the group: the whole summary disappears, stale bell row
        // and all.
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:30:00');
        $this->assertNull($this->service([$creator])->getHomeActivitySummaryForCurrentUser());
    }
}
