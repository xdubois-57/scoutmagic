<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Http\Request;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Core\View\TwigFactory;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Groups\Controller\GroupController;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Support\SearchTerm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class GroupControllerTest extends TestCase
{
    private \PDO $pdo;
    private GroupService $groupService;
    private GroupRepository $groupRepo;
    private MemberService $memberService;
    private int $currentYearId;
    private int $sectionId;
    /**
     * The real service, never null and never a stub: passing null here is
     * what let a mistyped hint on GroupController's own constructor
     * (Service\GroupReadStateService read as Controller\GroupReadStateService,
     * for want of a `use`) reach production, where index.php passes the
     * real object and every route 500s on the resulting TypeError. Tests
     * that build the controller the way production does cannot miss that
     * again — see also Tests\Core\System\TypeHintResolutionTest.
     */
    private \Modules\Groups\Service\GroupReadStateService $readStateService;
    /** @var MemberProfile[] */
    private array $linkedProfiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->groupService = new GroupService(
            $this->groupRepo,
            new GroupSectionRepository($this->pdo),
            new GroupMemberRepository($this->pdo)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'parent@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
    }

    /**
     * @param int[] $linkedMemberIds
     */
    /**
     * @param array<string, mixed>|null $routeBreadcrumb the route's own
     *        static breadcrumb declaration (module.json's `routes[].
     *        breadcrumb`) — FrontController::handle() sets this as a Twig
     *        global on every real request; left unset by default here
     *        exactly as it always has been, since no other test in this
     *        file asserts on the rendered breadcrumb bar.
     */
    private function controller(
        array $linkedMemberIds,
        string $role = 'identified',
        bool $completeProfile = true,
        ?DelegatedAlbumManager $delegatedAlbumManager = null,
        ?array $routeBreadcrumb = null,
        ?\Modules\Calendar\Api\CalendarEventLookupInterface $eventLookup = null
    ): GroupController {
        AuthSession::login(1, 'parent@test.be', $role);

        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $readRepo = new \Modules\Groups\Repository\GroupReadRepository($this->pdo);
        $this->readStateService = new \Modules\Groups\Service\GroupReadStateService($readRepo, $access);
        $listService = new GroupListService(
            $this->groupRepo, $sectionRepo, $memberRepo, new SectionMembershipRepository($this->pdo), $readRepo
        );

        $this->memberService = $this->createMock(MemberService::class);
        $this->memberService->method('getLinkedMembers')->willReturn(
            array_map(fn(int $id) => $this->profile($id), $linkedMemberIds)
        );
        $this->memberService->method('findDisplayNamesByMemberIds')->willReturn(
            array_combine($linkedMemberIds, array_map(fn(int $id) => 'Akéla ' . $id, $linkedMemberIds))
        );

        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findById')->willReturn(new UserAccount(
            1,
            'parent@test.be',
            $completeProfile ? 'Marie' : null,
            $completeProfile ? 'Dupont' : null,
            null,
            false,
            null
        ));
        $accountRepo->method('findNamesByIds')->willReturn([1 => ['first_name' => 'Marie', 'last_name' => 'Dupont']]);

        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('getAllWithBranches')->willReturn([]);
        $sectionService->method('getSection')->willReturn(['id' => $this->sectionId, 'name' => 'Louveteaux', 'desk_code' => 'LOU']);

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            true,
            [
                'groups' => dirname(__DIR__, 4) . '/modules/groups/views',
                // show.html.twig includes @gallery/partials/lightbox.html.twig —
                // groups hard-requires gallery, and production registers every
                // enabled module's namespace (public/index.php), so the test
                // environment has to as well or the page cannot render.
                'gallery' => dirname(__DIR__, 4) . '/modules/gallery/views',
            ]
        );
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'parent@test.be');
        $twig->addGlobal('current_user_role', $role);
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test');
        if ($routeBreadcrumb !== null) {
            $twig->addGlobal('route_breadcrumb', $routeBreadcrumb);
        }
        $twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        $postRepo = new PostRepository($this->pdo);
        $activityService = new GroupActivityService($this->groupRepo, $postRepo);
        $postService = new PostService($postRepo, $activityService, GroupsTestHelper::rateLimitService($this->pdo));
        $postMediaService = new PostMediaService(
            $delegatedAlbumManager ?? $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo), $this->groupRepo,
            new \Modules\Groups\Repository\ReplyRepository($this->pdo)
        );
        $authorResolver = new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo));
        $stack = GroupsTestHelper::replyStack($this->pdo, $activityService, $postMediaService, $authorResolver);
        $feedService = new GroupFeedService(
            $postRepo, $authorResolver, $postService, $postMediaService,
            new PostLinkRepository($this->pdo),
            $stack['replyRepository'], $stack['replyPresenter'], $stack['reactionService'], $stack['reportService'],
            $this->readStateService,
            // Null lookup unless a test supplies one — production's own
            // "calendar disabled" wiring, so every other test here
            // exercises the degraded path for free.
            $groupsEventService = new \Modules\Groups\Service\PostEventService($eventLookup)
        );

        return new GroupController(
            $twig,
            $this->groupRepo,
            $listService,
            $access,
            $this->groupService,
            new GroupSessionContextFactory($this->memberService, $accountRepo, $resolver),
            $sectionService,
            $feedService,
            $postMediaService,
            $postRepo,
            // A REAL SectionService here, not the mock above: the sync's
            // whole job is reading which sections exist, and a mock
            // returning [] would make these tests pass by doing nothing.
            new \Modules\Groups\Service\SectionGroupSyncService(
                new SectionService(
                    \Core\Database\Connection::withPdo($this->pdo),
                    new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                    new \Core\Badge\MemberBadgeRepository($this->pdo)
                ),
                $this->groupRepo,
                new GroupSectionRepository($this->pdo)
            ),
            // No moderator-binding self-heal here: it needs a recipient
            // resolver (member → accounts) these tests have no use for,
            // and the controller treats it as optional exactly so — its
            // own behaviour is covered by
            // Tests\Modules\Groups\Service\ModeratorBindingServiceTest.
            null,
            new \Modules\Groups\Service\GroupMembershipService(
                $this->groupRepo,
                new GroupMemberRepository($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
            ),
            null,
            // A real read-state service rather than null: production
            // always passes one, and passing null here is how a
            // mistyped hint on this very parameter once went unnoticed
            // by the whole suite. The same instance the list and the feed
            // above got, so a mark written by one is seen by the others.
            $this->readStateService,
            $groupsEventService
        );
    }

    /**
     * Where a notification's deep link lands. A real server route rather
     * than a `#post-123` fragment precisely so this check happens at all:
     * a fragment never reaches the server, so a forwarded link would
     * render the group page to somebody outside the group before failing
     * to scroll.
     */
    public function testTheDeepLinkRedirectsAMemberToTheAnchoredPost(): void
    {
        $groupId = $this->deepLinkGroup('D1');
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Le message', '2026-01-01 10:00:00');
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'DM1', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->post(
            new Request('GET', '/groups/' . $groupId . '/posts/' . $postId, [], [], [], []),
            ['id' => (string) $groupId, 'postId' => (string) $postId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/groups/' . $groupId . '#post-' . $postId, $response->getHeaders()['Location'] ?? null);
    }

    public function testTheDeepLinkIs404ForANonMemberRatherThan403(): void
    {
        $groupId = $this->deepLinkGroup('D2');
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Le message', '2026-01-01 10:00:00');
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->controller([$stranger])->post(
            new Request('GET', '/groups/' . $groupId . '/posts/' . $postId, [], [], [], []),
            ['id' => (string) $groupId, 'postId' => (string) $postId]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheDeepLinkIs404ForAnUnknownGroupToo(): void
    {
        // Same status as the case above, which is the point: the two are
        // indistinguishable from outside.
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER3');

        $response = $this->controller([$stranger])->post(
            new Request('GET', '/groups/9999/posts/1', [], [], [], []),
            ['id' => '9999', 'postId' => '1']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheDeepLinkIs404ForAPostFromAnotherGroup(): void
    {
        $groupId = $this->deepLinkGroup('D4');
        $otherSection = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
        $otherGroupId = $this->groupService->createSectionGroup('Ailleurs', $otherSection, $this->currentYearId, GroupsTestHelper::createMember($this->pdo, 'C-OTHER'));
        $foreignPost = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00');
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'DM4', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->post(
            new Request('GET', '/groups/' . $groupId . '/posts/' . $foreignPost, [], [], [], []),
            ['id' => (string) $groupId, 'postId' => (string) $foreignPost]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Following an old notification must not become the way around the
     * hiding it predates.
     */
    public function testTheDeepLinkIs404ForAHiddenPostSeenByAnOrdinaryMember(): void
    {
        $groupId = $this->deepLinkGroup('D5');
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Le message', '2026-01-01 10:00:00');
        (new PostRepository($this->pdo))->setHiddenAt($postId, '2026-02-01 00:00:00');
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'DM5', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->post(
            new Request('GET', '/groups/' . $groupId . '/posts/' . $postId, [], [], [], []),
            ['id' => (string) $groupId, 'postId' => (string) $postId]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAModeratorStillReachesAHiddenPostThroughTheDeepLink(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'D6-MOD');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Le message', '2026-01-01 10:00:00');
        (new PostRepository($this->pdo))->setHiddenAt($postId, '2026-02-01 00:00:00');

        // createSectionGroup() makes its creator a moderator of the group.
        $response = $this->controller([$moderator])->post(
            new Request('GET', '/groups/' . $groupId . '/posts/' . $postId, [], [], [], []),
            ['id' => (string) $groupId, 'postId' => (string) $postId]
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * The quota is about live clutter: five open invitation groups is the
     * default ceiling, and the sixth is refused with the limit named.
     */
    public function testCreatingBeyondTheQuotaIsRefused(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'QUOTA', $this->sectionId, $this->currentYearId);
        for ($i = 0; $i < 5; $i++) {
            $this->groupRepo->create("Groupe {$i}", null, null, $creator);
        }
        $_POST = ['name' => 'Un de trop', '_csrf_token' => $this->csrfToken()];

        $this->controller([$creator])->create($this->postRequest(), []);

        $this->assertSame(5, $this->countInvitationGroupsBy($creator));
    }

    public function testCreatingAtTheQuotaMinusOneStillWorks(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'QUOTA2', $this->sectionId, $this->currentYearId);
        for ($i = 0; $i < 4; $i++) {
            $this->groupRepo->create("Groupe {$i}", null, null, $creator);
        }
        $_POST = ['name' => 'Le cinquième', '_csrf_token' => $this->csrfToken()];

        $this->controller([$creator])->create($this->postRequest(), []);

        $this->assertSame(5, $this->countInvitationGroupsBy($creator));
    }

    /**
     * Section groups are created by the scheduled task, never by a
     * person, so they cannot use up somebody's quota.
     */
    public function testSectionGroupsDoNotConsumeTheQuota(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'QUOTA3', $this->sectionId, $this->currentYearId);
        for ($i = 0; $i < 8; $i++) {
            $this->groupRepo->create("Section {$i}", $this->currentYearId, $this->sectionId, $creator);
        }
        $_POST = ['name' => 'Sur invitation', '_csrf_token' => $this->csrfToken()];

        $this->controller([$creator])->create($this->postRequest(), []);

        $this->assertSame(1, $this->countInvitationGroupsBy($creator));
    }

    /**
     * The cap is about clutter, not privilege — an admin's clutter is
     * exactly as cluttering as anyone else's.
     */
    public function testASiteAdminIsNotExemptFromTheQuota(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'QUOTA4', $this->sectionId, $this->currentYearId);
        for ($i = 0; $i < 5; $i++) {
            $this->groupRepo->create("Groupe {$i}", null, null, $creator);
        }
        $_POST = ['name' => 'Un de trop', '_csrf_token' => $this->csrfToken()];

        $this->controller([$creator], 'admin')->create($this->postRequest(), []);

        $this->assertSame(5, $this->countInvitationGroupsBy($creator));
    }

    /**
     * A closed group is read-only and on its way to the purge, so it
     * stops counting.
     */
    public function testAClosedGroupFreesUpQuota(): void
    {
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'QUOTA5', $this->sectionId, $this->currentYearId);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->groupRepo->create("Groupe {$i}", null, null, $creator);
        }
        $this->groupRepo->setClosed($ids[0], '2026-01-01 00:00:00');
        $_POST = ['name' => 'Le remplaçant', '_csrf_token' => $this->csrfToken()];

        $this->controller([$creator])->create($this->postRequest(), []);

        // Six rows in total, but only five of them OPEN — which is what
        // the quota counts, and why the sixth creation was allowed.
        $this->assertSame(6, $this->countInvitationGroupsBy($creator));
        $this->assertSame(5, $this->groupRepo->countOpenCreatedBy($creator));
    }

    private function countInvitationGroupsBy(int $memberId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_groups WHERE created_by_member_id = ? AND section_id IS NULL'
        );
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The self-healing half of Service\SectionGroupSyncService: the group
     * list is where a missing section group would be noticed, so that is
     * where it gets created — without waiting for tonight's task, and
     * without a core hook into the Desk import.
     */
    public function testOpeningTheGroupListCreatesAMissingSectionGroup(): void
    {
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SYNCM', $this->sectionId, $this->currentYearId);

        $this->controller([$member])->index(new Request('GET', '/groups', [], [], [], []), []);

        $this->assertNotNull(
            (new GroupRepository($this->pdo))->findSectionGroup($this->sectionId, $this->currentYearId)
        );
    }

    public function testOpeningTheGroupListTwiceStillLeavesOneGroup(): void
    {
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'SYNCM2', $this->sectionId, $this->currentYearId);

        $this->controller([$member])->index(new Request('GET', '/groups', [], [], [], []), []);
        $this->controller([$member])->index(new Request('GET', '/groups', [], [], [], []), []);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_groups')->fetchColumn());
    }

    /** A section group whose creator is nobody we assert anything about. */
    private function deepLinkGroup(string $creatorDeskId): int
    {
        return $this->groupService->createSectionGroup(
            'Louveteaux',
            $this->sectionId,
            $this->currentYearId,
            GroupsTestHelper::createMember($this->pdo, $creatorDeskId)
        );
    }

    private function profile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100,
            $memberId,
            'DESK' . $memberId,
            'Marie',
            'Dupont',
            'Akéla',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            false,
            [],
            [],
            '2025-2026'
        );
    }

    /**
     * The front controller builds a Request from $_POST, and CsrfGuard
     * reads $_POST directly — so a test that sets one without the other
     * would exercise a state the app can never be in.
     */
    private function postRequest(): Request
    {
        return new Request('POST', '/groups', [], $_POST, [], []);
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    public function testIndexListsOnlyTheCallersGroups(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C1');
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupService->createSectionGroup('Éclaireurs', GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs'), $this->currentYearId, $creator);

        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member])->index(new Request('GET', '/groups', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Louveteaux', $body);
        $this->assertStringNotContainsString('Éclaireurs', $body);
    }

    public function testTheListCardCarriesTheGroupsHeadcount(): void
    {
        // « Louveteaux » next to « Louveteaux (2025-2026) » says nothing
        // about which one is the live group. The number does, and it was
        // two clicks away on the members page.
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'C20', $this->sectionId, $this->currentYearId);
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M20', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$creator])->index(new Request('GET', '/groups', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('2 membres', $body);
    }

    public function testAGroupOfOneIsWrittenInTheSingular(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C21');
        $this->groupService->createInvitationGroup('Coordination', null, $creator, 1);

        $body = $this->controller([$creator])->index(new Request('GET', '/groups', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('1 membre', $body);
        $this->assertStringNotContainsString('1 membres', $body);
    }

    public function testShowReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $response = $this->controller([$outsider])->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId]);

        // 404, never 403: a 403 would confirm the group exists.
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowMarksTheGroupReadForTheMemberWhoOpenedIt(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3R');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M2R', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->show(
            new Request('GET', '/groups/' . $groupId, [], [], [], []),
            ['id' => (string) $groupId]
        );

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_reads WHERE group_id = ? AND member_id = ?'
        );
        $stmt->execute([$groupId, $member]);
        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'opening a group must record the visit that clears its unread badge'
        );
    }

    public function testShowMarksNothingForAGroupTheReaderIsNotAMemberOf(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3N');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTR');

        $this->controller([$outsider])->show(
            new Request('GET', '/groups/' . $groupId, [], [], [], []),
            ['id' => (string) $groupId]
        );

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM discussion_group_reads');
        $this->assertSame(0, (int) ($stmt === false ? 1 : $stmt->fetchColumn()));
    }

    public function testShowRendersForAMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M2', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Louveteaux', $response->getBody());
    }

    /**
     * partials/breadcrumb_bar.html.twig: the group's own name replaces the
     * route's static "Groupe" label, and a real link back to "Groupes"
     * (the module's list page) appears ahead of it.
     */
    public function testShowBreadcrumbNamesTheGroupAndLinksBackToTheGroupList(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CBC1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MBC1', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member], 'identified', true, null, ['label' => 'Groupe', 'parents' => ['Espace membres']])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertMatchesRegularExpression('/<a href="\/groups" class="text-decoration-none">Groupes<\/a>/', $body);
        // Named with its scout year, like every other place this module
        // writes the name of a group tied to the year in effect
        // (Support\GroupLabel).
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Louveteaux \(2025-2026\)\s*<\/li>/', $body);
    }

    public function testShowOffersTheEditFormToAModeratorWithTheGroupsCurrentName(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MODEDIT');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('Modifier le groupe', $body);
        $this->assertStringContainsString('value="Louveteaux"', $body);
        // A section group's year is derived from its section — no
        // tie-to-year checkbox is offered for one (partials/
        // group_edit_form.html.twig).
        $this->assertStringNotContainsString('group-edit-tie-year', $body);
    }

    /**
     * The one-liner is shown to every member, not only to the moderator
     * who wrote it — it is on the header partial, which the members page
     * shares, rather than inside the moderator-only edit form.
     */
    public function testShowDisplaysTheGroupsDescriptionToAnOrdinaryMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CDESC');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setDescription($groupId, 'Coordination du camp');
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MDESC', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('Coordination du camp', $body);
    }

    /**
     * A description is member-supplied plain text: it goes through Twig's
     * escaping like every other one in this module, never |raw.
     */
    public function testAGroupsDescriptionIsEscapedNotRendered(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MODESC');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $this->groupRepo->setDescription($groupId, '<script>alert(1)</script>');

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testShowDoesNotOfferTheEditFormToAnOrdinaryMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CEDIT');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MEDIT', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringNotContainsString('Modifier le groupe', $body);
    }

    public function testShowOffersTheTieToYearCheckboxForAnInvitationGroup(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MODEDIT2');
        $groupId = $this->groupService->createInvitationGroup('Projet', null, $moderator, 1);

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('group-edit-tie-year', $body);
    }

    // ---- search ----

    /**
     * @param int[] $linkedMemberIds
     */
    private function searchBody(array $linkedMemberIds, int $groupId, string $q): string
    {
        return $this->controller($linkedMemberIds)
            ->search(
                new Request('GET', '/groups/' . $groupId . '/search', ['q' => $q], [], [], []),
                ['id' => (string) $groupId]
            )
            ->getBody();
    }

    public function testSearchFindsAPostByItsOwnBody(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MSEARCH');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Rendez-vous au local samedi', '2026-01-10 10:00:00');
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Bonne année à tous', '2026-01-11 10:00:00');

        $body = $this->searchBody([$moderator], $groupId, 'local');

        $this->assertStringContainsString('Rendez-vous au local samedi', $body);
        $this->assertStringNotContainsString('Bonne année à tous', $body);
    }

    /**
     * A hit inside a thread is a hit on the conversation: the POST comes
     * back, not the reply on its own, because a reply shown without what
     * it answers tells the reader nothing.
     */
    public function testSearchFindsAPostThroughAMatchingReply(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MSEARCH2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Programme du week-end', '2026-01-10 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Je ramène les tentes', '2026-01-10 11:00:00');

        $body = $this->searchBody([$moderator], $groupId, 'tentes');

        $this->assertStringContainsString('Programme du week-end', $body);
    }

    /**
     * A post matching both ways is one result, not two: the reply search
     * returns ids, and the ones the body search already produced are
     * dropped before they are fetched again.
     */
    public function testAPostMatchingBothInItsBodyAndInAReplyAppearsOnce(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MSEARCH3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Le camp approche', '2026-01-10 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Vivement le camp', '2026-01-10 11:00:00');

        $body = $this->searchBody([$moderator], $groupId, 'camp');

        $this->assertSame(1, substr_count($body, 'id="post-' . $postId . '"'));
    }

    /**
     * The module's rule everywhere: a route naming a group answers 404 to
     * somebody who is not in it. A search box that answered 403 would
     * confirm the group exists.
     */
    public function testSearchReturns404ForANonMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CSEARCH');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'XSEARCH');

        $response = $this->controller([$stranger])->search(
            new Request('GET', '/groups/' . $groupId . '/search', ['q' => 'local'], [], [], []),
            ['id' => (string) $groupId]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * An auto-hidden post is excluded in the SQL for everyone but a
     * moderator — the search box must not become the way back to a
     * message the feed already stopped showing.
     */
    public function testAnAutoHiddenPostIsNotReturnedToAnOrdinaryMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CHID');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MHID', $this->sectionId, $this->currentYearId);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Message signalé au local', '2026-01-10 10:00:00');
        (new PostRepository($this->pdo))->setHiddenAt($postId, '2026-01-10 12:00:00');

        $this->assertStringNotContainsString('Message signalé au local', $this->searchBody([$member], $groupId, 'local'));
        $this->assertStringContainsString('Message signalé au local', $this->searchBody([$creator], $groupId, 'local'));
    }

    /**
     * The same rule one level down: a hidden reply must not surface the
     * post it hangs under either.
     */
    public function testAHiddenReplyDoesNotSurfaceItsPost(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CHID2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MHID2', $this->sectionId, $this->currentYearId);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Programme du week-end', '2026-01-10 10:00:00');
        $replyId = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Insulte tentaculaire', '2026-01-10 11:00:00');
        (new \Modules\Groups\Repository\ReplyRepository($this->pdo))->setHiddenAt($replyId, '2026-01-10 12:00:00');

        $this->assertStringNotContainsString(
            'Programme du week-end',
            $this->searchBody([$member], $groupId, 'tentaculaire')
        );
    }

    /**
     * Scoping, stated as a test rather than trusted to the query: the
     * group in the URL is the only one searched, even when the member is
     * a member of both.
     */
    public function testSearchNeverReachesAnotherGroupsMessages(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MSCOPE');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $otherId = $this->groupService->createInvitationGroup('Projet', null, $moderator, 1);
        GroupsTestHelper::createPostAt($this->pdo, $otherId, 'Secret de l\'autre groupe', '2026-01-10 10:00:00');

        $this->assertStringNotContainsString(
            'Secret de l\'autre groupe',
            $this->searchBody([$moderator], $groupId, 'Secret')
        );
    }

    /**
     * Support\SearchTerm's escaping, seen from the outside: a member
     * typing a lone wildcard gets no results, not every message in the
     * group.
     */
    public function testALoneWildcardMatchesNothingRatherThanEverything(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MWILD');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Rendez-vous au local', '2026-01-10 10:00:00');

        $body = $this->searchBody([$moderator], $groupId, '%%%');

        $this->assertStringNotContainsString('Rendez-vous au local', $body);
        $this->assertStringContainsString('Aucun message', $body);
    }

    public function testATermShorterThanTheMinimumIsRefusedRatherThanRun(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MSHORT');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Rendez-vous au local', '2026-01-10 10:00:00');

        $body = $this->searchBody([$moderator], $groupId, 'lo');

        $this->assertStringContainsString('au moins ' . SearchTerm::MIN_LENGTH . ' caractères', $body);
        $this->assertStringNotContainsString('Rendez-vous au local', $body);
    }

    /**
     * Emptying the box is how a reader asks for everything back, so it
     * lands on the group itself — the whole feed — rather than on a
     * results page with nothing on it, which reads exactly like a group
     * that lost its messages.
     */
    public function testEmptyingTheSearchBoxGoesBackToTheWholeGroup(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MEMPTY');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $response = $this->controller([$moderator])->search(
            new Request('GET', '/groups/' . $groupId . '/search', ['q' => '  '], [], [], []),
            ['id' => (string) $groupId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/groups/' . $groupId, $response->getHeaders()['Location']);
    }

    /**
     * The term is echoed back into the box and into the "aucun résultat"
     * line — both go through Twig's escaping like any other untrusted
     * string.
     */
    public function testTheSearchTermIsEscapedWhereItIsEchoedBack(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MXSS');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->searchBody([$moderator], $groupId, '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * A result is something to read and then open where it was said — no
     * reply composer is rendered on this page, so a member cannot half-
     * answer a message out of its context.
     */
    public function testResultsCarryNoReplyComposerButLinkBackToTheConversation(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MNOCOMP');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Rendez-vous au local', '2026-01-10 10:00:00');

        $body = $this->searchBody([$moderator], $groupId, 'local');

        $this->assertStringNotContainsString('groups-reply-form', $body);
        $this->assertStringContainsString('/groups/' . $groupId . '#post-' . $postId, $body);
    }

    public function testTheGroupPageOffersTheSearchBox(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MBOX');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('/groups/' . $groupId . '/search', $body);
    }

    public function testTheComposerOffersThePollBoxes(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MPOLL');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('Sondage', $body);
        $this->assertStringContainsString('name="poll_question"', $body);
        // More than the minimum, so a three- or four-choice poll needs no
        // second trip through the form.
        $this->assertGreaterThan(
            \Modules\Groups\Service\PollService::MIN_OPTIONS,
            substr_count($body, 'name="poll_options[]"')
        );
    }

    // ---- linked calendar event ----

    private function eventLookup(?\Modules\Calendar\Api\EventSummary $event): \Modules\Calendar\Api\CalendarEventLookupInterface
    {
        $lookup = $this->createStub(\Modules\Calendar\Api\CalendarEventLookupInterface::class);
        $lookup->method('findEventById')->willReturn($event);
        $lookup->method('findEventsInWindow')->willReturn($event !== null ? [$event] : []);

        return $lookup;
    }

    public function testTheComposerOffersTheCalendarPickerWhenTheCalendarHasSomethingToOffer(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MEVT');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);
        $event = new \Modules\Calendar\Api\EventSummary(9, 'Réunion de section', 'Louveteaux', '2026-03-14', '2026-03-14');

        $body = $this->controller([$moderator], 'identified', true, null, null, $this->eventLookup($event))
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('name="calendar_event_id"', $body);
        $this->assertStringContainsString('Réunion de section', $body);
    }

    /**
     * With the calendar module disabled the composer never mentions the
     * feature at all — an empty picker would advertise something this
     * install does not have.
     */
    public function testTheComposerHidesThePickerWhenTheCalendarIsDisabled(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MEVT2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->controller([$moderator])
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringNotContainsString('name="calendar_event_id"', $body);
    }

    public function testTheComposerHidesThePickerWhenTheCalendarHasNoEventInTheWindow(): void
    {
        $moderator = GroupsTestHelper::createMember($this->pdo, 'MEVT3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator, 1);

        $body = $this->controller([$moderator], 'identified', true, null, null, $this->eventLookup(null))
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringNotContainsString('name="calendar_event_id"', $body);
    }

    public function testShowReturns404ForAnUnknownGroup(): void
    {
        $member = GroupsTestHelper::createMember($this->pdo, 'M3');

        $response = $this->controller([$member])->show(new Request('GET', '/groups/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowExplainsWhyPostingIsRefusedOnAClosedGroup(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setClosed($groupId, '2026-02-01 00:00:00');
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M4', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member])->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])->getBody();

        $this->assertStringContainsString('clôturé', $body);
    }

    public function testCreateRejectsAMissingCsrfToken(): void
    {
        $chiefMember = GroupsTestHelper::createMember($this->pdo, 'CHIEF');
        $_POST = ['name' => 'Nouveau groupe'];

        $response = $this->controller([$chiefMember], 'chief')->create($this->postRequest(), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame([], $this->groupRepo->findAll());
    }

    public function testCreateMakesASectionGroupAndItsCreatorAModerator(): void
    {
        $chiefMember = GroupsTestHelper::createMember($this->pdo, 'CHIEF2');
        $token = $this->csrfToken();
        $_POST = ['name' => 'Louveteaux 2025-2026', 'section_id' => (string) $this->sectionId, '_csrf_token' => $token];

        $response = $this->controller([$chiefMember], 'chief')->create($this->postRequest(), []);

        $this->assertSame(302, $response->getStatusCode());
        $groups = $this->groupRepo->findAll();
        $this->assertCount(1, $groups);
        $this->assertSame($this->sectionId, $groups[0]->sectionId);
        $this->assertSame($this->currentYearId, $groups[0]->scoutYearId);

        $row = (new GroupMemberRepository($this->pdo))->find($groups[0]->id, $chiefMember);
        $this->assertNotNull($row);
        $this->assertTrue($row->isModerator);
    }

    public function testCreateMakesAYearLessInvitationGroupWhenNoSectionAndNoYearTie(): void
    {
        $chiefMember = GroupsTestHelper::createMember($this->pdo, 'CHIEF3');
        $_POST = ['name' => 'Groupe de travail', 'section_id' => '0', '_csrf_token' => $this->csrfToken()];

        $this->controller([$chiefMember], 'chief')->create($this->postRequest(), []);

        $groups = $this->groupRepo->findAll();
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]->sectionId);
        $this->assertNull($groups[0]->scoutYearId);
    }

    public function testCreateTiesAnInvitationGroupToTheYearWhenAsked(): void
    {
        $chiefMember = GroupsTestHelper::createMember($this->pdo, 'CHIEF4');
        $_POST = ['name' => 'Groupe annuel', 'section_id' => '0', 'tie_to_year' => '1', '_csrf_token' => $this->csrfToken()];

        $this->controller([$chiefMember], 'chief')->create($this->postRequest(), []);

        $this->assertSame($this->currentYearId, $this->groupRepo->findAll()[0]->scoutYearId);
    }

    public function testArchivesShowsOnlyPastYearGroups(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $creator = GroupsTestHelper::createMember($this->pdo, 'C5');
        $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $pastYearId, $creator);
        $this->groupService->createSectionGroup('Louveteaux 25-26', $this->sectionId, $this->currentYearId, $creator, 1);

        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M5', $this->sectionId, $pastYearId);
        $body = $this->controller([$member])->archives(new Request('GET', '/groups/archives', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Louveteaux 24-25', $body);
        $this->assertStringNotContainsString('Louveteaux 25-26', $body);
    }

    // --- media -----------------------------------------------------------

    public function testGalleryReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTG1');

        $response = $this->controller([$outsider])
            ->gallery(new Request('GET', '/groups/' . $groupId . '/gallery', [], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testGalleryRendersEveryMediaForAMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MG2', $this->sectionId, $this->currentYearId);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('listMedia')->with(42)->willReturn([
            new DelegatedMedia(1, 'photo', 'done', 0, 'a.jpg', '2026-01-01 10:00:00'),
            new DelegatedMedia(2, 'video', 'done', 1, 'b.mp4', '2026-01-02 10:00:00'),
        ]);

        $body = $this->controller([$member], 'identified', true, $manager)
            ->gallery(new Request('GET', '/groups/' . $groupId . '/gallery', [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('/gallery/media/1/thumb', $body);
        $this->assertStringContainsString('/gallery/media/2/thumb', $body);
    }

    public function testGalleryBreadcrumbLinksBackToTheGroupListAndTheGroupItself(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CBC2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MBC2', $this->sectionId, $this->currentYearId);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('listMedia')->willReturn([]);

        $body = $this->controller([$member], 'identified', true, $manager, ['label' => 'Galerie du groupe', 'parents' => ['Espace membres']])
            ->gallery(new Request('GET', '/groups/' . $groupId . '/gallery', [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertMatchesRegularExpression('/<a href="\/groups" class="text-decoration-none">Groupes<\/a>/', $body);
        $this->assertMatchesRegularExpression('/<a href="\/groups\/' . $groupId . '" class="text-decoration-none">Louveteaux<\/a>/', $body);
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Galerie du groupe\s*<\/li>/', $body);
    }

    /**
     * DoD: "'pending' and 'failed' media render without breaking the
     * feed, tested." — a media in either state must never produce a
     * broken <img> or crash the page.
     */
    public function testShowRendersPendingAndFailedMediaWithoutBreakingThePage(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MG3', $this->sectionId, $this->currentYearId);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Photos en cours', '2026-01-01 10:00:00', 1, $creator);
        (new \Modules\Groups\Repository\PostMediaRepository($this->pdo))->attach($postId, 1, 0);
        (new \Modules\Groups\Repository\PostMediaRepository($this->pdo))->attach($postId, 2, 1);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('listMedia')->willReturn([
            new DelegatedMedia(1, 'photo', 'pending', 0, 'a.jpg', '2026-01-01 10:00:00'),
            new DelegatedMedia(2, 'photo', 'failed', 1, 'b.jpg', '2026-01-01 10:00:00'),
        ]);

        $response = $this->controller([$member], 'identified', true, $manager)
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('/gallery/media/1/thumb', $response->getBody(), 'a pending media never renders an <img>');
        $this->assertStringContainsString('spinner-border', $response->getBody());
        $this->assertStringContainsString('Échec du traitement', $response->getBody());
    }

    /**
     * The photo viewer is gallery's own, not a second one written here:
     * the page must carry gallery's lightbox markup AND give each
     * finished media cell the trigger attributes gallery.js reads.
     */
    public function testShowWiresTheGalleryLightboxOntoFinishedMediaOnly(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CLB');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MLB', $this->sectionId, $this->currentYearId);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Photos', '2026-01-01 10:00:00', 1, $creator);
        (new \Modules\Groups\Repository\PostMediaRepository($this->pdo))->attach($postId, 1, 0);
        (new \Modules\Groups\Repository\PostMediaRepository($this->pdo))->attach($postId, 2, 1);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('listMedia')->willReturn([
            new DelegatedMedia(1, 'photo', 'done', 0, 'a.jpg', '2026-01-01 10:00:00'),
            new DelegatedMedia(2, 'photo', 'pending', 1, 'b.jpg', '2026-01-01 10:00:00'),
        ]);

        $body = $this->controller([$member], 'identified', true, $manager)
            ->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId])
            ->getBody();

        $this->assertStringContainsString('id="gallery-lightbox"', $body, 'the viewer markup must be on the page');
        $this->assertStringContainsString('gallery-lightbox-trigger', $body);
        $this->assertStringContainsString('data-medium-url="/gallery/media/1/medium"', $body);
        // The still-processing one gets no rendition URL, so gallery.js
        // skips it and its plain <a> keeps working.
        $this->assertStringNotContainsString('/gallery/media/2/medium', $body);
    }

    /**
     * groups.js's own poll (public/assets/js/groups.js): a still-pending
     * cell asks again until the background resize finishes.
     */
    public function testMediaStatusReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTGM1');

        $response = $this->controller([$outsider])
            ->mediaStatus(new Request('GET', '/groups/' . $groupId . '/media-status', ['ids' => '1'], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMediaStatusReportsPendingMediaAsData(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MGM2', $this->sectionId, $this->currentYearId);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('listMedia')->with(42)->willReturn([
            new DelegatedMedia(1, 'photo', 'processing', 0, 'a.jpg', '2026-01-01 10:00:00'),
        ]);

        $response = $this->controller([$member], 'identified', true, $manager)->mediaStatus(
            new Request('GET', '/groups/' . $groupId . '/media-status', ['ids' => '1'], [], [], []),
            ['id' => (string) $groupId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame([['id' => 1, 'status' => 'processing', 'media_type' => 'photo']], $body);
    }

    /**
     * Data, never markup. The endpoint used to return the rendered
     * `media_thumb.html.twig` partial, which groups.js dropped into
     * `innerHTML` — a DOM-XSS sink whatever flows through it, rated a
     * blocker by SonarQube and the reason main's quality gate was red.
     * Nothing user-supplied was travelling through it, and that was
     * exactly the fragile part: the day a caption joined that template,
     * it would have become a real one.
     */
    public function testMediaStatusReturnsDataAndNeverMarkup(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MGM3', $this->sectionId, $this->currentYearId);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('listMedia')->with(42)->willReturn([
            new DelegatedMedia(1, 'photo', 'done', 0, 'a.jpg', '2026-01-01 10:00:00'),
            new DelegatedMedia(2, 'photo', 'failed', 1, 'b.jpg', '2026-01-01 10:00:00'),
        ]);

        $response = $this->controller([$member], 'identified', true, $manager)->mediaStatus(
            new Request('GET', '/groups/' . $groupId . '/media-status', ['ids' => '1,2'], [], [], []),
            ['id' => (string) $groupId]
        );

        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            [
                ['id' => 1, 'status' => 'done', 'media_type' => 'photo'],
                ['id' => 2, 'status' => 'failed', 'media_type' => 'photo'],
            ],
            $body
        );

        // No key carries markup, on any row — asserted over the whole
        // response rather than over the keys this test happens to know,
        // so a field added later cannot quietly reopen the sink.
        foreach ($body as $row) {
            foreach ($row as $key => $value) {
                $this->assertArrayNotHasKey('html', $row);
                $this->assertStringNotContainsString('<', (string) $value, "'{$key}' must not carry markup");
            }
        }
    }

    public function testMediaStatusSilentlyOmitsAnIdNotInTheGroupsAlbum(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM4');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator, 1);
        $this->groupRepo->setGalleryAlbumId($groupId, 42);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MGM4', $this->sectionId, $this->currentYearId);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('listMedia')->with(42)->willReturn([
            new DelegatedMedia(1, 'photo', 'done', 0, 'a.jpg', '2026-01-01 10:00:00'),
        ]);

        // id 999 belongs to no album this group can see — never a
        // distinguishable error, just absent from the response, same as
        // every other id lookup this module scopes to an authorised group.
        $response = $this->controller([$member], 'identified', true, $manager)->mediaStatus(
            new Request('GET', '/groups/' . $groupId . '/media-status', ['ids' => '1,999'], [], [], []),
            ['id' => (string) $groupId]
        );

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body);
        $this->assertSame(1, $body[0]['id']);
    }
}
