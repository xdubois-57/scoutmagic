<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Gallery;

use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Gallery\GroupDelegatedAlbumAccessChecker;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Issue #1119: an image of a post or reply moderation hid leaves with it.
 * The group's "Galerie du groupe" already left it out, but
 * /gallery/media/{id}/… served it to any member who had the URL — the one
 * the hidden post's own markup carried before it was hidden.
 *
 * Asked through gallery's own registry, the way Controller\
 * GalleryController asks it, so the album-wide rule and the per-media one
 * are tested together.
 *
 * @group database
 */
#[Group('database')]
final class GroupDelegatedAlbumAccessCheckerTest extends TestCase
{
    private const MODERATOR_ACCOUNT = 70;

    private \PDO $pdo;
    private DelegatedAlbumAccessRegistry $registry;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private int $groupId;
    private int $member;
    private int $moderator;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $yearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));

        $creator = GroupsTestHelper::createMember($this->pdo, 'C1');
        $this->groupId = (new GroupService($groupRepo, $sectionRepo, $memberRepo))
            ->createSectionGroup('Louveteaux', $sectionId, $yearId, $creator);
        $this->member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $sectionId, $yearId);
        $this->moderator = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M2', $sectionId, $yearId);
        $memberRepo->setModerator($this->groupId, $this->moderator, true, null, self::MODERATOR_ACCOUNT);

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($yearId, '2025-2026', null));
        $resolver->method('getAuthorizationYear')->willReturn(new EffectiveScoutYear($yearId, '2025-2026', null));

        $this->registry = new DelegatedAlbumAccessRegistry([
            new GroupDelegatedAlbumAccessChecker(
                new GroupFileOwnershipChecker(
                    $groupRepo,
                    $access,
                    $resolver,
                    GroupsTestHelper::hiddenGroupFiles(
                        $this->pdo,
                        $this->createStub(\Modules\Gallery\Api\DelegatedAlbumManager::class)
                    )
                ),
                $groupRepo,
                $access,
                new PostMediaRepository($this->pdo)
            ),
        ]);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
    }

    public function testTheImageOfAVisiblePostIsServedToAMember(): void
    {
        $this->attach($this->post(), 11);

        $this->assertTrue($this->memberMay(11));
    }

    public function testTheImageOfAHiddenPostIsRefusedToAMemberAndServedToAModerator(): void
    {
        $post = $this->post();
        $this->attach($post, 12);
        $this->postRepo->setHiddenAt($post, '2026-02-01 00:00:00');

        $this->assertFalse($this->memberMay(12));
        $this->assertTrue($this->moderatorMay(12));
    }

    /**
     * Moderating is a grant to ONE login: the moderator's member reached
     * without that login (another address linked to it, or no login at
     * all) does not moderate.
     */
    public function testTheModeratorsMemberWithoutTheirLoginDoesNotSeeIt(): void
    {
        $post = $this->post();
        $this->attach($post, 13);
        $this->postRepo->setHiddenAt($post, '2026-02-01 00:00:00');

        $this->assertFalse($this->allowed(13, Role::IDENTIFIED, [$this->moderator], null));
        $this->assertFalse($this->allowed(13, Role::IDENTIFIED, [$this->moderator], self::MODERATOR_ACCOUNT + 1));
    }

    public function testASiteAdministratorSeesIt(): void
    {
        $post = $this->post();
        $this->attach($post, 14);
        $this->postRepo->setHiddenAt($post, '2026-02-01 00:00:00');

        $this->assertTrue($this->allowed(14, Role::ADMIN, [], null));
    }

    public function testTheImageOfAHiddenReplyIsRefusedToAMember(): void
    {
        $reply = GroupsTestHelper::createReplyAt($this->pdo, $this->post(), 'réponse', '2026-01-01 11:00:00', 1, 1, 15);
        $this->replyRepo->setHiddenAt($reply, '2026-02-01 00:00:00');

        $this->assertFalse($this->memberMay(15));
        $this->assertTrue($this->moderatorMay(15));
    }

    /** A reply goes with the post it answers. */
    public function testTheImageOfAReplyUnderAHiddenPostIsRefusedToAMember(): void
    {
        $post = $this->post();
        GroupsTestHelper::createReplyAt($this->pdo, $post, 'réponse', '2026-01-01 11:00:00', 1, 1, 16);
        $this->postRepo->setHiddenAt($post, '2026-02-01 00:00:00');

        $this->assertFalse($this->memberMay(16));
        $this->assertTrue($this->moderatorMay(16));
    }

    /** Restoring a post brings its images back. */
    public function testARestoredPostsImageIsServedAgain(): void
    {
        $post = $this->post();
        $this->attach($post, 17);
        $this->postRepo->setHiddenAt($post, '2026-02-01 00:00:00');
        $this->postRepo->setHiddenAt($post, null);

        $this->assertTrue($this->memberMay(17));
    }

    /** A hidden post of ANOTHER group says nothing about this group's media. */
    public function testOnlyThisGroupsHiddenContentCounts(): void
    {
        $elsewhere = GroupsTestHelper::createPostAt($this->pdo, $this->groupId + 1000, 'ailleurs', '2026-01-01 10:00:00');
        $this->attach($elsewhere, 18);
        $this->postRepo->setHiddenAt($elsewhere, '2026-02-01 00:00:00');
        $this->attach($this->post(), 18);

        $this->assertTrue($this->memberMay(18));
    }

    /** The album-wide rule still comes first: an outsider sees nothing. */
    public function testAnOutsiderIsRefusedEvenAVisibleImage(): void
    {
        $this->attach($this->post(), 19);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $this->assertFalse($this->allowed(19, Role::IDENTIFIED, [$outsider], 99));
    }

    private function memberMay(int $mediaId): bool
    {
        return $this->allowed($mediaId, Role::IDENTIFIED, [$this->member], 50);
    }

    private function moderatorMay(int $mediaId): bool
    {
        return $this->allowed($mediaId, Role::IDENTIFIED, [$this->moderator], self::MODERATOR_ACCOUNT);
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    private function allowed(int $mediaId, Role $role, array $linkedMemberIds, ?int $accountId): bool
    {
        return $this->registry->isMediaAllowed(
            GroupFileOwnershipChecker::OWNER_TYPE,
            $this->groupId,
            $mediaId,
            $role,
            $linkedMemberIds,
            $accountId
        );
    }

    private function post(): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'message', '2026-01-01 10:00:00');
    }

    private function attach(int $postId, int $mediaId): void
    {
        $this->pdo->prepare(
            'INSERT INTO discussion_group_post_media (post_id, gallery_media_id, sort_order) VALUES (?, ?, 0)'
        )->execute([$postId, $mediaId]);
    }
}
