<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Api;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\Groups\Api\HomeActivityService;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The homepage hook (Core\Module\HomeGroupActivityProvider): "which of
 * your groups have something you have not seen yet".
 *
 * The two things worth proving here are that it never leaks a group the
 * caller cannot read — the same rule every route in this module enforces —
 * and that an anonymous visitor gets nothing at all rather than an error.
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

        return new HomeActivityService(
            new GroupListService(
                $this->groupRepo,
                new GroupSectionRepository($this->pdo),
                new GroupMemberRepository($this->pdo),
                new SectionMembershipRepository($this->pdo),
                $this->readRepo
            ),
            new GroupSessionContextFactory($memberService, $accountRepo, $resolver)
        );
    }

    private function withActivity(int $groupId, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $groupId]);
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'H1');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertSame([], $this->service([$creator])->getUnreadGroupsForCurrentUser(3));
    }

    public function testAMemberSeesTheirOwnUnreadGroup(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H2');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $result = $this->service([$creator])->getUnreadGroupsForCurrentUser(3);

        $this->assertCount(1, $result);
        $this->assertSame('Groupe de travail', $result[0]['name']);
        $this->assertSame($groupId, $result[0]['id']);
    }

    public function testAGroupAlreadyOpenedIsNotListed(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H3');
        $groupId = $this->groupService->createInvitationGroup('Groupe de travail', null, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');
        $this->readRepo->markRead($groupId, $creator, '2030-01-01 12:00:01');

        $this->assertSame([], $this->service([$creator])->getUnreadGroupsForCurrentUser(3));
    }

    /**
     * The whole point of routing this through GroupListService: a group
     * the caller is not a member of can never reach the homepage, exactly
     * as it can never reach the group list.
     */
    public function testAGroupTheCallerIsNotAMemberOfIsNeverListed(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $stranger = GroupsTestHelper::createMember($this->pdo, 'H4');
        $someoneElse = GroupsTestHelper::createMember($this->pdo, 'H5');
        $groupId = $this->groupService->createInvitationGroup('Pas le vôtre', null, $someoneElse);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertSame([], $this->service([$stranger])->getUnreadGroupsForCurrentUser(3));
    }

    public function testTheMostRecentlyActiveGroupComesFirstAndTheLimitIsRespected(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $creator = GroupsTestHelper::createMember($this->pdo, 'H6');
        $oldest = $this->groupService->createInvitationGroup('Le plus ancien', null, $creator);
        $newest = $this->groupService->createInvitationGroup('Le plus récent', null, $creator);
        $middle = $this->groupService->createInvitationGroup('Au milieu', null, $creator);
        $this->withActivity($oldest, '2030-01-01 08:00:00');
        $this->withActivity($middle, '2030-01-01 10:00:00');
        $this->withActivity($newest, '2030-01-01 12:00:00');

        $result = $this->service([$creator])->getUnreadGroupsForCurrentUser(2);

        $this->assertSame(['Le plus récent', 'Au milieu'], array_column($result, 'name'));
    }

    /**
     * A past-year group is a read-only archive — surfacing it as "du
     * nouveau" would be a call to action with nowhere to go.
     */
    public function testAnArchivedGroupIsNeverListed(): void
    {
        AuthSession::login(1, 'parent@test.be', 'identified');
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $creator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'H7', $this->sectionId, $pastYearId);
        $groupId = $this->groupService->createSectionGroup('Louveteaux 24-25', $this->sectionId, $pastYearId, $creator);
        $this->withActivity($groupId, '2030-01-01 12:00:00');

        $this->assertSame([], $this->service([$creator])->getUnreadGroupsForCurrentUser(3));
    }
}
