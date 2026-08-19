<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupMembershipService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Closing, reopening and leaving over HTTP: CSRF and the authorisation
 * boundary in both directions for each.
 *
 * @group database
 */
#[Group('database')]
class GroupLifecycleActionsTest extends GroupsControllerTestCase
{
    private GroupMemberRepository $memberRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberRepo = new GroupMemberRepository($this->pdo);
    }

    // ---- close ----

    public function testCloseRejectsAMissingCsrfToken(): void
    {
        $_POST = [];

        $response = $this->groupController([$this->moderatorMemberId])->close($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->group()->isClosed());
    }

    public function testAModeratorMayCloseTheGroup(): void
    {
        $this->withCsrf([]);

        $response = $this->groupController([$this->moderatorMemberId])->close($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->group()->isClosed());
    }

    public function testAnOrdinaryMemberMayNotCloseTheGroup(): void
    {
        $this->withCsrf([]);

        $response = $this->groupController([$this->memberId])->close($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->group()->isClosed());
    }

    public function testANonMemberGets404OnCloseRatherThan403(): void
    {
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->groupController([$stranger], self::OTHER_ACCOUNT)->close($this->request(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---- reopen ----

    public function testReopenRejectsAMissingCsrfToken(): void
    {
        $this->closeGroup();
        $_POST = [];

        $response = $this->groupController([$this->moderatorMemberId])->reopen($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($this->group()->isClosed());
    }

    public function testAModeratorMayReopenAClosedGroup(): void
    {
        $this->closeGroup();
        $this->withCsrf([]);

        $response = $this->groupController([$this->moderatorMemberId])->reopen($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->group()->isClosed());
    }

    /**
     * A site admin moderates every group without holding a row in it.
     */
    public function testASiteAdminMayReopenAClosedGroup(): void
    {
        $this->closeGroup();
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'ADMIN');

        $response = $this->groupController([$stranger], self::OTHER_ACCOUNT, 'admin')
            ->reopen($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->group()->isClosed());
    }

    public function testAnOrdinaryMemberMayNotReopen(): void
    {
        $this->closeGroup();
        $this->withCsrf([]);

        $response = $this->groupController([$this->memberId])->reopen($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($this->group()->isClosed());
    }

    /**
     * Prompt 3's rule is not relaxed: an archived group stays a read-only
     * archive.
     */
    public function testAPastYearGroupIsRefusedRatherThanReopened(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupRepo = new GroupRepository($this->pdo);
        $groupId = $groupRepo->create('Ancien', $pastYearId, null, $this->moderatorMemberId);
        $this->memberRepo->add($groupId, $this->moderatorMemberId, true);
        $groupRepo->setClosed($groupId, '2025-01-01 00:00:00');
        $this->withCsrf([]);

        $response = $this->groupController([$this->moderatorMemberId])
            ->reopen($this->request(), ['id' => (string) $groupId]);

        // A refusal, not an error page: the caller is a moderator of a
        // group they can read, so they get the reason in French.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($groupRepo->findById($groupId)->isClosed());
    }

    /**
     * The reset that stops the inactivity task closing it straight back
     * on its next nightly run.
     */
    public function testReopeningResetsLastActivity(): void
    {
        (new GroupRepository($this->pdo))->touchActivity($this->groupId, '2020-01-01 00:00:00');
        $this->closeGroup();
        $this->withCsrf([]);

        $this->groupController([$this->moderatorMemberId])->reopen($this->request(), $this->params());

        $this->assertGreaterThan(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s'),
            $this->group()->lastActivityAt
        );
    }

    // ---- edit ----

    public function testEditRejectsAMissingCsrfToken(): void
    {
        $_POST = ['name' => 'Renommé'];

        $response = $this->groupController([$this->moderatorMemberId])->edit($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Louveteaux', $this->group()->name);
    }

    public function testAModeratorMayRenameTheGroup(): void
    {
        $this->withCsrf(['name' => 'Nouveau nom']);

        $response = $this->groupController([$this->moderatorMemberId])->edit($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Nouveau nom', $this->group()->name);
    }

    public function testAnOrdinaryMemberMayNotEdit(): void
    {
        $this->withCsrf(['name' => 'Nouveau nom']);

        $response = $this->groupController([$this->memberId])->edit($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Louveteaux', $this->group()->name);
    }

    public function testANonMemberGets404OnEditRatherThan403(): void
    {
        $this->withCsrf(['name' => 'Nouveau nom']);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER3');

        $response = $this->groupController([$stranger], self::OTHER_ACCOUNT)->edit($this->request(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnEmptyNameIsRefusedWithoutWriting(): void
    {
        $this->withCsrf(['name' => '   ']);

        $response = $this->groupController([$this->moderatorMemberId])->edit($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Louveteaux', $this->group()->name);
    }

    public function testANameOver150CharactersIsTruncated(): void
    {
        $this->withCsrf(['name' => str_repeat('a', 200)]);

        $this->groupController([$this->moderatorMemberId])->edit($this->request(), $this->params());

        $this->assertSame(150, mb_strlen($this->group()->name));
    }

    public function testEditingASectionGroupNeverChangesItsScoutYearEvenIfTieToYearIsSubmitted(): void
    {
        // $this->groupId is a section group (setUp/GroupsControllerTestCase)
        // — its scout year is derived from its section and must stay
        // exactly what createSectionGroup() set, regardless of the field.
        $before = $this->group()->scoutYearId;
        $this->withCsrf(['name' => 'Louveteaux', 'tie_to_year' => '1']);

        $this->groupController([$this->moderatorMemberId])->edit($this->request(), $this->params());

        $this->assertSame($before, $this->group()->scoutYearId);
    }

    public function testLinkingAnInvitationGroupToTheCurrentYear(): void
    {
        $groupRepo = new GroupRepository($this->pdo);
        $groupId = $groupRepo->create('Projet', null, null, $this->moderatorMemberId);
        $this->memberRepo->add($groupId, $this->moderatorMemberId, true);
        $this->withCsrf(['name' => 'Projet', 'tie_to_year' => '1']);

        $this->groupController([$this->moderatorMemberId])->edit($this->request(), ['id' => (string) $groupId]);

        $this->assertSame($this->currentYearId, $groupRepo->findById($groupId)->scoutYearId);
    }

    public function testUncheckingTieToYearUnlinksAnInvitationGroup(): void
    {
        $groupRepo = new GroupRepository($this->pdo);
        $groupId = $groupRepo->create('Projet', $this->currentYearId, null, $this->moderatorMemberId);
        $this->memberRepo->add($groupId, $this->moderatorMemberId, true);
        // No tie_to_year field at all — the checkbox was unchecked, and an
        // unchecked HTML checkbox submits nothing.
        $this->withCsrf(['name' => 'Projet']);

        $this->groupController([$this->moderatorMemberId])->edit($this->request(), ['id' => (string) $groupId]);

        $this->assertNull($groupRepo->findById($groupId)->scoutYearId);
    }

    // ---- leave ----

    public function testLeaveRejectsAMissingCsrfToken(): void
    {
        $this->memberRepo->add($this->groupId, $this->memberId, false);
        $_POST = [];

        $response = $this->memberController([$this->memberId])->leave($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->memberRepo->find($this->groupId, $this->memberId));
    }

    public function testAnInvitedMemberMayLeaveAndLosesAccess(): void
    {
        $invited = GroupsTestHelper::createMember($this->pdo, 'INVITED');
        $this->memberRepo->add($this->groupId, $invited, false);
        $this->withCsrf([]);

        $response = $this->memberController([$invited], self::OTHER_ACCOUNT)->leave($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->memberRepo->find($this->groupId, $invited));

        // Access is gone immediately: the group page now 404s for them.
        $show = $this->memberController([$invited], self::OTHER_ACCOUNT)
            ->index(new Request('GET', '/groups/' . $this->groupId . '/members', [], [], [], []), $this->params());
        $this->assertSame(404, $show->getStatusCode());
    }

    /**
     * Derived membership follows the Desk import — refused server-side,
     * not merely hidden in the UI.
     */
    public function testADerivedSectionMemberIsRefused(): void
    {
        // $this->memberId is a member through the section, with no row.
        $this->assertNull($this->memberRepo->find($this->groupId, $this->memberId));
        $this->withCsrf([]);

        $response = $this->memberController([$this->memberId])->leave($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        // Still a member, and still readable.
        $show = $this->memberController([$this->memberId])
            ->index(new Request('GET', '/groups/' . $this->groupId . '/members', [], [], [], []), $this->params());
        $this->assertSame(200, $show->getStatusCode());
    }

    public function testTheLastModeratorIsRefused(): void
    {
        // createSectionGroup() made the creator the sole moderator.
        $this->assertSame(1, $this->memberRepo->countModerators($this->groupId));
        $this->withCsrf([]);

        $this->memberController([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->leave($this->request(), $this->params());

        $this->assertNotNull($this->memberRepo->find($this->groupId, $this->moderatorMemberId));
    }

    public function testASecondModeratorMakesLeavingSucceed(): void
    {
        $second = GroupsTestHelper::createMember($this->pdo, 'MOD2');
        $this->memberRepo->add($this->groupId, $second, true);
        $this->withCsrf([]);

        $this->memberController([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->leave($this->request(), $this->params());

        $this->assertNull($this->memberRepo->find($this->groupId, $this->moderatorMemberId));
    }

    public function testANonMemberGets404OnLeave(): void
    {
        $this->withCsrf([]);
        $stranger = GroupsTestHelper::createMember($this->pdo, 'STRANGER');

        $response = $this->memberController([$stranger], self::OTHER_ACCOUNT)->leave($this->request(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---- fixtures ----

    /**
     * @param int[] $linkedMemberIds
     */
    private function groupController(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified'
    ): \Modules\Groups\Controller\GroupController {
        AuthSession::login($accountId, 'parent@test.be', $role);
        $memberService = $this->memberServiceMock($linkedMemberIds);
        $accountRepo = $this->accountRepoMock($accountId);

        // Only the collaborators close()/reopen() actually reach are real
        // here — the feed, media and author-options services are never
        // touched by either action, so building them would be noise.
        return new \Modules\Groups\Controller\GroupController(
            $this->twig($role),
            new GroupRepository($this->pdo),
            $this->createStub(\Modules\Groups\Service\GroupListService::class),
            $this->accessService(),
            $this->groupService,
            new \Modules\Groups\Service\GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock()),
            $this->createStub(\Core\Member\SectionService::class),
            $this->createStub(\Modules\Groups\Service\GroupFeedService::class),
            $this->createStub(\Modules\Groups\Service\PostMediaService::class),
            $this->createStub(\Modules\Groups\Service\AuthorOptionsService::class),
            $this->postRepo,
            null,
            $this->membershipService()
        );
    }

    /**
     * @param int[] $linkedMemberIds
     */
    private function memberController(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified'
    ): \Modules\Groups\Controller\GroupMemberController {
        AuthSession::login($accountId, 'parent@test.be', $role);
        $memberService = $this->memberServiceMock($linkedMemberIds);
        $accountRepo = $this->accountRepoMock($accountId);

        $sectionService = $this->createStub(\Core\Member\SectionService::class);
        $sectionService->method('getAllWithBranches')->willReturn([]);

        return new \Modules\Groups\Controller\GroupMemberController(
            $this->twig($role),
            new GroupRepository($this->pdo),
            $this->memberRepo,
            new \Modules\Groups\Repository\GroupSectionRepository($this->pdo),
            $this->accessService(),
            $this->groupService,
            new \Modules\Groups\Service\GroupSessionContextFactory($memberService, $accountRepo, $this->scoutYearResolverMock()),
            $memberService,
            $sectionService,
            $this->membershipService()
        );
    }

    private function membershipService(): GroupMembershipService
    {
        return new GroupMembershipService(
            new GroupRepository($this->pdo),
            $this->memberRepo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function group(): \Modules\Groups\Repository\DiscussionGroup
    {
        $group = (new GroupRepository($this->pdo))->findById($this->groupId);
        \assert($group !== null);

        return $group;
    }

    private function closeGroup(): void
    {
        (new GroupRepository($this->pdo))->setClosed($this->groupId, '2025-01-01 00:00:00');
    }

    private function request(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId, [], $_POST, [], []);
    }

    /**
     * @return array<string, string>
     */
    private function params(): array
    {
        return ['id' => (string) $this->groupId];
    }
}
