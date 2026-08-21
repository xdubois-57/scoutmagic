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
use Modules\Groups\Controller\GroupMemberController;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Authorisation for the four membership actions, in both directions, plus
 * CSRF on each. The two denials are deliberately different: 404 for a
 * non-member (the group must not be confirmed to exist), 403 for a member
 * who is not a moderator.
 *
 * @group database
 */
#[Group('database')]
class GroupMemberControllerTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private GroupMemberRepository $memberRepo;
    private GroupSectionRepository $sectionRepo;
    private GroupService $groupService;
    private int $currentYearId;
    private int $sectionId;
    private int $groupId;
    private int $moderatorId;
    private int $plainMemberId;
    private int $outsiderId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->sectionRepo = new GroupSectionRepository($this->pdo);
        $this->memberRepo = new GroupMemberRepository($this->pdo);
        $this->groupService = new GroupService($this->groupRepo, $this->sectionRepo, $this->memberRepo);

        $this->moderatorId = GroupsTestHelper::createMember($this->pdo, 'MOD');
        $this->groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $this->moderatorId);
        $this->plainMemberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'PLAIN', $this->sectionId, $this->currentYearId);
        $this->outsiderId = GroupsTestHelper::createMember($this->pdo, 'OUT');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
     * @param array<string, mixed>|null $routeBreadcrumb see
     *        GroupControllerTest::controller()'s own doc — same optional,
     *        opt-in Twig global.
     * @param MemberProfile[] $candidateProfiles who search()/
     *        inviteCandidates() see as invitable — left empty by default,
     *        preserving every other test's original "no sections
     *        configured" world exactly as it always has been.
     */
    private function controller(
        array $linkedMemberIds,
        string $role = 'identified',
        ?array $routeBreadcrumb = null,
        array $candidateProfiles = []
    ): GroupMemberController {
        AuthSession::login(1, 'parent@test.be', $role);

        $access = new GroupAccessService($this->memberRepo, $this->sectionRepo, new SectionMembershipRepository($this->pdo));

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('getLinkedMembers')->willReturn(array_map(fn(int $id) => $this->profile($id), $linkedMemberIds));
        $memberService->method('findProfileByMemberAndYear')->willReturn($this->profile(1));

        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findById')->willReturn(new UserAccount(1, 'parent@test.be', 'Marie', 'Dupont', null, false, null));

        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('getAllWithBranches')->willReturn(
            $candidateProfiles === [] ? [] : [['id' => $this->sectionId, 'name' => 'Louveteaux', 'desk_code' => 'LOU']]
        );
        $sectionService->method('getSection')->willReturn(['id' => $this->sectionId, 'name' => 'Louveteaux', 'desk_code' => 'LOU']);
        $sectionService->method('getSectionStaff')->willReturn($candidateProfiles);
        $sectionService->method('getSectionAnimes')->willReturn([]);

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
        foreach (['site_name' => 'Test', 'is_authenticated' => true, 'current_user_email' => 'p@t.be',
                  'current_user_role' => $role, 'config_mode' => false, 'cookie_consent_given' => true,
                  'menus' => null, 'csp_nonce' => 'test'] as $key => $value) {
            $twig->addGlobal($key, $value);
        }
        if ($routeBreadcrumb !== null) {
            $twig->addGlobal('route_breadcrumb', $routeBreadcrumb);
        }
        $twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        return new GroupMemberController(
            $twig,
            $this->groupRepo,
            $this->memberRepo,
            $this->sectionRepo,
            $access,
            $this->groupService,
            new GroupSessionContextFactory($memberService, $accountRepo, $resolver),
            $sectionService,
            null,
            GroupsTestHelper::identityService($this->pdo)
        );
    }

    private function profile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100, $memberId, 'DESK' . $memberId, 'Marie', 'Dupont', 'Akéla',
            null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
        );
    }

    private function postRequest(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/invite-member', [], $_POST, [], []);
    }

    private function withCsrf(array $body): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST = $body + ['_csrf_token' => $token];
    }

    /**
     * @return array<string, string>
     */
    private function params(): array
    {
        return ['id' => (string) $this->groupId];
    }

    public function testMembersPageIs404ForANonMember(): void
    {
        $response = $this->controller([$this->outsiderId])->index(new Request('GET', '/g', [], [], [], []), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMembersPageRendersForAnOrdinaryMember(): void
    {
        $response = $this->controller([$this->plainMemberId])->index(new Request('GET', '/g', [], [], [], []), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Membres invités', $response->getBody());
    }

    public function testMembersPageExplainsThatTheSectionListFollowsTheDeskImport(): void
    {
        $body = $this->controller([$this->plainMemberId])->index(new Request('GET', '/g', [], [], [], []), $this->params())->getBody();

        $this->assertStringContainsString('import du Desk', $body);
    }

    /**
     * partials/breadcrumb_bar.html.twig: "Groupes" and the group's own
     * name both link back, same trail as GroupController's own pages.
     */
    public function testMembersPageBreadcrumbLinksBackToTheGroupListAndTheGroupItself(): void
    {
        $body = $this->controller([$this->plainMemberId], 'identified', ['label' => 'Membres', 'parents' => ['Espace animés']])
            ->index(new Request('GET', '/g', [], [], [], []), $this->params())->getBody();

        $this->assertMatchesRegularExpression('/<a href="\/groups" class="text-decoration-none">Groupes<\/a>/', $body);
        $this->assertMatchesRegularExpression('/<a href="\/groups\/' . $this->groupId . '" class="text-decoration-none">Louveteaux<\/a>/', $body);
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Membres\s*<\/li>/', $body);
    }

    // ---- search ----

    private function candidateProfile(int $memberId, string $firstName, string $lastName, ?string $totem): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100, $memberId, 'DESK' . $memberId, $firstName, $lastName, $totem,
            null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
        );
    }

    private function searchRequest(string $query): Request
    {
        return new Request('GET', '/groups/' . $this->groupId . '/member-search', ['q' => $query], [], [], []);
    }

    public function testSearchMatchesByFirstNameLastNameOrTotem(): void
    {
        $profiles = [
            $this->candidateProfile(11, 'Baptiste', 'Renard', 'Akéla'),
            $this->candidateProfile(12, 'Céline', 'Baptiste', null),
            $this->candidateProfile(13, 'Marc', 'Dupont', 'Baloo'),
        ];

        $controller = $this->controller([$this->moderatorId], 'identified', null, $profiles);

        $byFirstName = json_decode($controller->search($this->searchRequest('bapt'), $this->params())->getBody(), true);
        $this->assertCount(2, $byFirstName);

        // The human first, the membership in parentheses — the shape every
        // other name in this module now takes.
        $byTotem = json_decode($controller->search($this->searchRequest('baloo'), $this->params())->getBody(), true);
        $this->assertSame([['id' => 13, 'label' => 'Marc Dupont (Baloo)']], $byTotem);

        $byLastName = json_decode($controller->search($this->searchRequest('renard'), $this->params())->getBody(), true);
        $this->assertSame([['id' => 11, 'label' => 'Baptiste Renard (Akéla)']], $byLastName);
    }

    public function testSearchLabelHasNoTotemParenthesisWhenThereIsNoTotem(): void
    {
        $profiles = [$this->candidateProfile(12, 'Céline', 'Baptiste', null)];

        $body = json_decode(
            $this->controller([$this->moderatorId], 'identified', null, $profiles)
                ->search($this->searchRequest('céline'), $this->params())->getBody(),
            true
        );

        $this->assertSame([['id' => 12, 'label' => 'Céline Baptiste']], $body);
    }

    public function testSearchExcludesSomeoneAlreadyInTheGroup(): void
    {
        $profiles = [$this->candidateProfile($this->moderatorId, 'Le', 'Modérateur', 'Chef')];

        // The moderator is already an explicit member of $this->groupId
        // (createSectionGroup's own creator) — must never appear as an
        // invite candidate for a group they are already in.
        $body = json_decode(
            $this->controller([$this->moderatorId], 'identified', null, $profiles)
                ->search($this->searchRequest('chef'), $this->params())->getBody(),
            true
        );

        $this->assertSame([], $body);
    }

    public function testSearchCapsResultsAtTen(): void
    {
        $profiles = array_map(
            fn(int $i) => $this->candidateProfile(20 + $i, 'Scout' . $i, 'Renard', null),
            range(1, 15)
        );

        $body = json_decode(
            $this->controller([$this->moderatorId], 'identified', null, $profiles)
                ->search($this->searchRequest('scout'), $this->params())->getBody(),
            true
        );

        $this->assertCount(10, $body);
    }

    public function testSearchWithAnEmptyQueryReturnsNothingWithoutFiltering(): void
    {
        $profiles = [$this->candidateProfile(11, 'Baptiste', 'Renard', 'Akéla')];

        $body = json_decode(
            $this->controller([$this->moderatorId], 'identified', null, $profiles)
                ->search($this->searchRequest(''), $this->params())->getBody(),
            true
        );

        $this->assertSame([], $body);
    }

    public function testSearchIsRefusedToAnOrdinaryMember(): void
    {
        $response = $this->controller([$this->plainMemberId])->search($this->searchRequest('bapt'), $this->params());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSearchIs404ForANonMemberRatherThan403(): void
    {
        $response = $this->controller([$this->outsiderId])->search($this->searchRequest('bapt'), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInviteMemberRejectsAMissingCsrfToken(): void
    {
        $_POST = ['member_id' => (string) $this->outsiderId];

        $response = $this->controller([$this->moderatorId])->inviteMember($this->postRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }

    public function testInviteMemberIsRefusedToAnOrdinaryMember(): void
    {
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $response = $this->controller([$this->plainMemberId])->inviteMember($this->postRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }

    public function testInviteMemberIs404ForANonMember(): void
    {
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $response = $this->controller([GroupsTestHelper::createMember($this->pdo, 'STRANGER')])->inviteMember($this->postRequest(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInviteMemberSucceedsForAModerator(): void
    {
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $response = $this->controller([$this->moderatorId])->inviteMember($this->postRequest(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }

    public function testInviteSectionAddsADerivedMembershipSourceForAModerator(): void
    {
        $newSection = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
        $this->withCsrf(['section_id' => (string) $newSection]);

        $this->controller([$this->moderatorId])->inviteSection($this->postRequest(), $this->params());

        $this->assertContains($newSection, $this->sectionRepo->findSectionIds($this->groupId));
    }

    public function testInviteSectionIsRefusedToAnOrdinaryMember(): void
    {
        $newSection = GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs');
        $this->withCsrf(['section_id' => (string) $newSection]);

        $response = $this->controller([$this->plainMemberId])->inviteSection($this->postRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotContains($newSection, $this->sectionRepo->findSectionIds($this->groupId));
    }

    public function testSetModeratorGrantsAndRevokes(): void
    {
        $this->withCsrf(['member_id' => (string) $this->plainMemberId, 'is_moderator' => '1']);
        $this->controller([$this->moderatorId])->setModerator($this->postRequest(), $this->params());
        $this->assertTrue($this->memberRepo->find($this->groupId, $this->plainMemberId)->isModerator);

        $this->withCsrf(['member_id' => (string) $this->plainMemberId, 'is_moderator' => '0']);
        $this->controller([$this->moderatorId])->setModerator($this->postRequest(), $this->params());
        $this->assertFalse($this->memberRepo->find($this->groupId, $this->plainMemberId)->isModerator);
    }

    public function testSetModeratorIsRefusedToAnOrdinaryMember(): void
    {
        $this->withCsrf(['member_id' => (string) $this->plainMemberId, 'is_moderator' => '1']);

        $response = $this->controller([$this->plainMemberId])->setModerator($this->postRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->memberRepo->find($this->groupId, $this->plainMemberId));
    }

    public function testSetModeratorRejectsAMissingCsrfToken(): void
    {
        $_POST = ['member_id' => (string) $this->plainMemberId, 'is_moderator' => '1'];

        $this->assertSame(403, $this->controller([$this->moderatorId])->setModerator($this->postRequest(), $this->params())->getStatusCode());
    }

    public function testRemoveMemberDropsTheExplicitRowForAModerator(): void
    {
        $this->groupService->inviteMember($this->groupRepo->findById($this->groupId), $this->outsiderId, $this->moderatorId);
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $this->controller([$this->moderatorId])->removeMember($this->postRequest(), $this->params());

        $this->assertNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }

    public function testRemoveMemberIsRefusedToAnOrdinaryMember(): void
    {
        $this->groupService->inviteMember($this->groupRepo->findById($this->groupId), $this->outsiderId, $this->moderatorId);
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $response = $this->controller([$this->plainMemberId])->removeMember($this->postRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }

    public function testASiteAdminMayModerateWithoutBeingAMember(): void
    {
        $this->withCsrf(['member_id' => (string) $this->outsiderId]);

        $response = $this->controller([GroupsTestHelper::createMember($this->pdo, 'ADM')], 'admin')->inviteMember($this->postRequest(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->memberRepo->find($this->groupId, $this->outsiderId));
    }
}
