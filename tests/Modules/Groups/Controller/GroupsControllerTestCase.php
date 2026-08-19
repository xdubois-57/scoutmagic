<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Core\View\TwigFactory;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;
use Twig\Environment;

/**
 * The setup every groups Controller test needs: a group with a section, a
 * member and a moderator, a session, and the mocked core services the
 * module resolves identities through.
 *
 * A base class rather than a copy in each file — Controller\ReplyController
 * and Controller\ReactionController are tested separately (they answer
 * different questions) but stand on exactly the same fixture, and two
 * copies of it would be two chances for the two to disagree about who is a
 * member of what.
 */
abstract class GroupsControllerTestCase extends TestCase
{
    protected \PDO $pdo;
    protected GroupRepository $groupRepo;
    protected PostRepository $postRepo;
    protected ReplyRepository $replyRepo;
    protected GroupService $groupService;
    protected int $currentYearId;
    protected int $sectionId;
    protected int $groupId;
    protected int $postId;
    protected int $moderatorMemberId;
    protected int $memberId;

    protected const AUTHOR_ACCOUNT = 7;
    protected const OTHER_ACCOUNT = 8;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->groupService = new GroupService(
            $this->groupRepo,
            new GroupSectionRepository($this->pdo),
            new GroupMemberRepository($this->pdo)
        );

        $this->moderatorMemberId = GroupsTestHelper::createMember($this->pdo, 'MOD');
        $this->groupId = $this->groupService->createSectionGroup(
            'Louveteaux',
            $this->sectionId,
            $this->currentYearId,
            $this->moderatorMemberId
        );
        $this->memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MEMBER', $this->sectionId, $this->currentYearId);
        $this->postId = GroupsTestHelper::createPostAt(
            $this->pdo,
            $this->groupId,
            'Le message',
            '2026-01-01 10:00:00',
            self::AUTHOR_ACCOUNT,
            $this->memberId
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
        $_FILES = [];
    }

    protected function accessService(): GroupAccessService
    {
        return new GroupAccessService(
            new GroupMemberRepository($this->pdo),
            new GroupSectionRepository($this->pdo),
            new SectionMembershipRepository($this->pdo)
        );
    }

    /**
     * @param int[] $linkedMemberIds
     */
    protected function memberServiceMock(array $linkedMemberIds): MemberService
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('getLinkedMembers')->willReturn(
            array_map(fn(int $id) => $this->profile($id), $linkedMemberIds)
        );
        $memberService->method('findDisplayNamesByMemberIds')->willReturn([$this->memberId => 'Akéla']);

        return $memberService;
    }

    protected function accountRepoMock(int $accountId, bool $completeProfile = true): UserAccountRepository
    {
        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findById')->willReturn(new UserAccount(
            $accountId,
            'parent@test.be',
            $completeProfile ? 'Marie' : null,
            $completeProfile ? 'Dupont' : null,
            null,
            false,
            null
        ));
        $accountRepo->method('findNamesByIds')->willReturn([
            $accountId => ['first_name' => 'Marie', 'last_name' => 'Dupont'],
        ]);

        return $accountRepo;
    }

    protected function scoutYearResolverMock(): ScoutYearResolver
    {
        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(
            new EffectiveScoutYear($this->currentYearId, '2025-2026', null)
        );

        return $resolver;
    }

    protected function twig(string $role = 'identified'): Environment
    {
        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            true,
            ['groups' => dirname(__DIR__, 4) . '/modules/groups/views']
        );
        foreach (['site_name' => 'Test', 'is_authenticated' => true, 'current_user_email' => 'p@t.be',
                  'current_user_role' => $role, 'config_mode' => false, 'cookie_consent_given' => true,
                  'menus' => null, 'csp_nonce' => 'test'] as $key => $value) {
            $twig->addGlobal($key, $value);
        }
        $twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        return $twig;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function withCsrf(array $body): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST = $body + ['_csrf_token' => $token];
    }

    protected function profile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100, $memberId, 'DESK' . $memberId, 'Marie', 'Dupont', 'Akéla',
            null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
        );
    }
}
