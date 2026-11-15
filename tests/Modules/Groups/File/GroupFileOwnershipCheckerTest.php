<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\File;

use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class GroupFileOwnershipCheckerTest extends TestCase
{
    private const MODERATOR_ACCOUNT = 70;

    private \PDO $pdo;
    private GroupFileOwnershipChecker $checker;
    private GroupService $groupService;
    private GroupRepository $groupRepo;
    private int $currentYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $this->groupService = new GroupService($this->groupRepo, $sectionRepo, $memberRepo);

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));
        $resolver->method('getAuthorizationYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        $this->checker = new GroupFileOwnershipChecker(
            $this->groupRepo,
            $access,
            $resolver,
            GroupsTestHelper::hiddenGroupFiles(
                $this->pdo,
                $this->createStub(\Modules\Gallery\Api\DelegatedAlbumManager::class)
            )
        );
    }

    public function testSupportsOnlyItsOwnOwnerType(): void
    {
        $this->assertTrue($this->checker->supports('discussion_group'));
        $this->assertFalse($this->checker->supports('section_document'));
        $this->assertFalse($this->checker->supports('group'));
    }

    public function testAMemberOfTheGroupIsAllowed(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C1');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $member = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'M1', $this->sectionId, $this->currentYearId);

        $this->assertTrue($this->checker->isAllowed($groupId, Role::IDENTIFIED, [$member]));
    }

    public function testANonMemberIsDenied(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C2');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $this->assertFalse($this->checker->isAllowed($groupId, Role::IDENTIFIED, [$outsider]));
    }

    public function testAChiefWhoIsNotAMemberIsDenied(): void
    {
        // No chief bypass on group files, exactly as on the group itself.
        $creator = GroupsTestHelper::createMember($this->pdo, 'C3');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);
        $chief = GroupsTestHelper::createMember($this->pdo, 'CHIEF');

        $this->assertFalse($this->checker->isAllowed($groupId, Role::CHIEF, [$chief]));
    }

    public function testASiteAdminIsAllowedAsAnImplicitModerator(): void
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'C4');
        $groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $creator);

        $this->assertTrue($this->checker->isAllowed($groupId, Role::ADMIN, []));
    }

    public function testAnUnknownGroupIsDenied(): void
    {
        $this->assertFalse($this->checker->isAllowed(4242, Role::ADMIN, []));
    }

    // --- One file at a time (issue #1119) -----------------------------

    /**
     * The address a member saw before the hiding: the post's markup carried
     * its link preview's `/files/…` image, and that image now leaves with
     * the post for everyone but a moderator.
     */
    public function testAHiddenPostsLinkPreviewImageIsAModeratorsOnly(): void
    {
        [$groupId, $member, $creator] = $this->groupWithMember();
        $post = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Regardez', '2026-01-01 10:00:00');
        (new PostLinkRepository($this->pdo))->attach($post, 'https://example.test', 'Titre', null, 500);

        $this->assertTrue($this->checker->isFileAllowed($groupId, 500, Role::IDENTIFIED, [$member], 50));

        (new PostRepository($this->pdo))->setHiddenAt($post, '2026-02-01 00:00:00');

        $this->assertFalse($this->checker->isFileAllowed($groupId, 500, Role::IDENTIFIED, [$member], 50));
        $this->assertTrue(
            $this->checker->isFileAllowed($groupId, 500, Role::IDENTIFIED, [$creator], self::MODERATOR_ACCOUNT)
        );
        $this->assertTrue($this->checker->isFileAllowed($groupId, 500, Role::ADMIN, [], null));
        // The moderator's member without the login the flag was granted to.
        $this->assertFalse($this->checker->isFileAllowed($groupId, 500, Role::IDENTIFIED, [$creator], null));
        // Any other file of the group is unaffected.
        $this->assertTrue($this->checker->isFileAllowed($groupId, 501, Role::IDENTIFIED, [$member], 50));

        (new PostRepository($this->pdo))->setHiddenAt($post, null);
        $this->assertTrue($this->checker->isFileAllowed($groupId, 500, Role::IDENTIFIED, [$member], 50));
    }

    /**
     * The original of a hidden post's photo: the gallery says which media
     * the file is the original of, and that media's own rule decides.
     */
    public function testTheOriginalOfAHiddenPostsPhotoIsAModeratorsOnly(): void
    {
        [$groupId, $member, $creator] = $this->groupWithMember();
        $this->groupRepo->setGalleryAlbumId($groupId, 9);
        $post = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Photos', '2026-01-01 10:00:00');
        $this->pdo->prepare(
            'INSERT INTO discussion_group_post_media (post_id, gallery_media_id, sort_order) VALUES (?, 61, 0)'
        )->execute([$post]);
        (new PostRepository($this->pdo))->setHiddenAt($post, '2026-02-01 00:00:00');

        $albums = $this->createStub(DelegatedAlbumManager::class);
        $albums->method('findMediaIdForOriginal')->willReturnCallback(
            static fn (int $albumId, int $fileId): ?int => $albumId === 9 && $fileId === 600 ? 61 : null
        );
        $checker = $this->checkerWith($albums);

        $this->assertFalse($checker->isFileAllowed($groupId, 600, Role::IDENTIFIED, [$member], 50));
        $this->assertTrue(
            $checker->isFileAllowed($groupId, 600, Role::IDENTIFIED, [$creator], self::MODERATOR_ACCOUNT)
        );
        // A file that is not the original of any media of the album.
        $this->assertTrue($checker->isFileAllowed($groupId, 601, Role::IDENTIFIED, [$member], 50));
    }

    public function testAFileOfAnUnknownGroupIsRefused(): void
    {
        $this->assertFalse($this->checker->isFileAllowed(4242, 1, Role::ADMIN, [], null));
    }

    /**
     * A section group whose creator moderates through MODERATOR_ACCOUNT,
     * and a derived member.
     *
     * @return array{0: int, 1: int, 2: int} group id, member id, creator id
     */
    private function groupWithMember(): array
    {
        $creator = GroupsTestHelper::createMember($this->pdo, 'CREATOR');
        $groupId = $this->groupService->createSectionGroup(
            'Louveteaux',
            $this->sectionId,
            $this->currentYearId,
            $creator,
            self::MODERATOR_ACCOUNT
        );
        $member = GroupsTestHelper::createMemberWithPeriod(
            $this->pdo,
            'MEMBER',
            $this->sectionId,
            $this->currentYearId
        );

        return [$groupId, $member, $creator];
    }

    private function checkerWith(DelegatedAlbumManager $albums): GroupFileOwnershipChecker
    {
        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')
            ->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        return new GroupFileOwnershipChecker(
            $this->groupRepo,
            new GroupAccessService(
                new GroupMemberRepository($this->pdo),
                new GroupSectionRepository($this->pdo),
                new SectionMembershipRepository($this->pdo)
            ),
            $resolver,
            GroupsTestHelper::hiddenGroupFiles($this->pdo, $albums)
        );
    }
}
