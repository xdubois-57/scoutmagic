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
use Modules\Groups\Service\AuthorOptionsService;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
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
    private function controller(
        array $linkedMemberIds,
        string $role = 'identified',
        bool $completeProfile = true,
        ?DelegatedAlbumManager $delegatedAlbumManager = null
    ): GroupController {
        AuthSession::login(1, 'parent@test.be', $role);

        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $listService = new GroupListService($this->groupRepo, $sectionRepo, $memberRepo, new SectionMembershipRepository($this->pdo));

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
            ['groups' => dirname(__DIR__, 4) . '/modules/groups/views']
        );
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'parent@test.be');
        $twig->addGlobal('current_user_role', $role);
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test');
        $twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        $postRepo = new PostRepository($this->pdo);
        $activityService = new GroupActivityService($this->groupRepo, $postRepo);
        $postService = new PostService($postRepo, $activityService, GroupsTestHelper::rateLimitService($this->pdo));
        $postMediaService = new PostMediaService(
            $delegatedAlbumManager ?? $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo), $this->groupRepo,
            new \Modules\Groups\Repository\ReplyRepository($this->pdo)
        );
        $authorResolver = new PostAuthorResolver($this->memberService, $accountRepo);
        $stack = GroupsTestHelper::replyStack($this->pdo, $activityService, $postMediaService, $authorResolver);
        $feedService = new GroupFeedService(
            $postRepo, $authorResolver, $postService, $postMediaService,
            new PostLinkRepository($this->pdo),
            $stack['replyRepository'], $stack['replyPresenter'], $stack['reactionService'], $stack['reportService']
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
            new AuthorOptionsService($access, $this->memberService),
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
            new \Modules\Groups\Service\GroupMembershipService(
                $this->groupRepo,
                new GroupMemberRepository($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
            )
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
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $moderator);
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
        $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $this->groupService->createSectionGroup('Éclaireurs', GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs'), $this->currentYearId, $creator);

        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->sectionId, $this->currentYearId);

        $body = $this->controller([$member])->index(new Request('GET', '/groups', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Louveteaux', $body);
        $this->assertStringNotContainsString('Éclaireurs', $body);
    }

    public function testShowReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $response = $this->controller([$outsider])->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId]);

        // 404, never 403: a 403 would confirm the group exists.
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowRendersForAMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M2', $this->sectionId, $this->currentYearId);

        $response = $this->controller([$member])->show(new Request('GET', '/groups/' . $groupId, [], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Louveteaux', $response->getBody());
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
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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

        $this->assertSame(403, $response->getStatusCode());
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
        $this->groupService->createSectionGroup('Louveteaux 25-26', $this->sectionId, $this->currentYearId, $creator);

        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M5', $this->sectionId, $pastYearId);
        $body = $this->controller([$member])->archives(new Request('GET', '/groups/archives', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Louveteaux 24-25', $body);
        $this->assertStringNotContainsString('Louveteaux 25-26', $body);
    }

    // --- media -----------------------------------------------------------

    public function testGalleryReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTG1');

        $response = $this->controller([$outsider])
            ->gallery(new Request('GET', '/groups/' . $groupId . '/gallery', [], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testGalleryRendersEveryMediaForAMember(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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

    /**
     * DoD: "'pending' and 'failed' media render without breaking the
     * feed, tested." — a media in either state must never produce a
     * broken <img> or crash the page.
     */
    public function testShowRendersPendingAndFailedMediaWithoutBreakingThePage(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CG3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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
     * groups.js's own poll (public/assets/js/groups.js): a still-pending
     * cell asks again until the background resize finishes.
     */
    public function testMediaStatusReturns404ForANonMemberRatherThan403(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTGM1');

        $response = $this->controller([$outsider])
            ->mediaStatus(new Request('GET', '/groups/' . $groupId . '/media-status', ['ids' => '1'], [], [], []), ['id' => (string) $groupId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMediaStatusReportsPendingMediaWithNoHtml(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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
        $this->assertSame([['id' => 1, 'status' => 'processing', 'html' => null]], $body);
    }

    public function testMediaStatusRendersTheThumbnailFragmentOnceDone(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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
        $this->assertSame('done', $body[0]['status']);
        $this->assertStringContainsString('/gallery/media/1/thumb', $body[0]['html']);
        $this->assertSame('failed', $body[1]['status']);
        $this->assertStringContainsString('Échec du traitement', $body[1]['html']);
    }

    public function testMediaStatusSilentlyOmitsAnIdNotInTheGroupsAlbum(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CGM4');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
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
