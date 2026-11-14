<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Api\DelegatedMediaAccessChecker;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
use PHPUnit\Framework\TestCase;

class DelegatedAlbumAccessRegistryTest extends TestCase
{
    public function testDeniesWhenNoCheckerIsRegistered(): void
    {
        $registry = new DelegatedAlbumAccessRegistry([]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 1, Role::CHIEF, []));
    }

    public function testDeniesWhenNoCheckerSupportsTheOwnerType(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->method('supports')->willReturn(false);
        $checker->expects($this->never())->method('isAllowed');
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 1, Role::CHIEF, []));
    }

    public function testDelegatesToTheCheckerThatSupportsTheOwnerType(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->expects($this->once())->method('supports')->with('some_owner_type')->willReturn(true);
        $checker->expects($this->once())->method('isAllowed')->with(42, Role::CHIEF, [1, 2])->willReturn(true);
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertTrue($registry->isAllowed('some_owner_type', 42, Role::CHIEF, [1, 2]));
    }

    public function testReturnsFalseWhenTheSupportingCheckerDenies(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->expects($this->once())->method('supports')->willReturn(true);
        $checker->expects($this->once())->method('isAllowed')->willReturn(false);
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 42, Role::CHIEF, []));
    }

    public function testTheFirstMatchingCheckerWins(): void
    {
        $first = $this->createMock(DelegatedAlbumAccessChecker::class);
        $first->expects($this->once())->method('supports')->willReturn(true);
        $first->expects($this->once())->method('isAllowed')->willReturn(true);
        $second = $this->createMock(DelegatedAlbumAccessChecker::class);
        $second->expects($this->never())->method('supports');
        $registry = new DelegatedAlbumAccessRegistry([$first, $second]);

        $this->assertTrue($registry->isAllowed('some_owner_type', 42, Role::CHIEF, []));
    }

    // --- isMediaAllowed (issue #1119) -----------------------------------

    /** A checker with no per-media rule answers for every media of its album. */
    public function testAMediaFollowsTheAlbumWhenTheCheckerHasNoMediaRule(): void
    {
        $checker = $this->createStub(DelegatedAlbumAccessChecker::class);
        $checker->method('supports')->willReturn(true);
        $checker->method('isAllowed')->willReturn(true);

        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertTrue($registry->isMediaAllowed('t', 42, 7, Role::CHIEF, [], 3));
    }

    public function testTheMediaRuleIsAskedWithTheMediaAndTheLogin(): void
    {
        $checker = $this->mediaChecker(true, false);
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isMediaAllowed('t', 42, 7, Role::CHIEF, [1, 2], 3));
        $this->assertSame([[42, 7, Role::CHIEF, [1, 2], 3]], $checker->mediaCalls);
        // The album-wide answer alone is unchanged.
        $this->assertTrue($registry->isAllowed('t', 42, Role::CHIEF, [1, 2]));
    }

    /** The album comes first: a reader refused the album is refused every media, unasked. */
    public function testTheMediaRuleIsNotAskedWhenTheAlbumIsRefused(): void
    {
        $checker = $this->mediaChecker(false, true);

        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isMediaAllowed('t', 42, 7, Role::CHIEF, [], 3));
        $this->assertSame([], $checker->mediaCalls);
    }

    public function testAMediaOfAnUnclaimedOwnerTypeIsRefused(): void
    {
        $this->assertFalse((new DelegatedAlbumAccessRegistry([]))->isMediaAllowed('t', 42, 7, Role::ADMIN, [], 3));
    }

    /**
     * @return DelegatedAlbumAccessChecker&DelegatedMediaAccessChecker&object{mediaCalls: list<array<int, mixed>>}
     */
    private function mediaChecker(bool $album, bool $media): object
    {
        return new class ($album, $media) implements DelegatedAlbumAccessChecker, DelegatedMediaAccessChecker {
            /** @var list<array<int, mixed>> */
            public array $mediaCalls = [];

            public function __construct(private bool $album, private bool $media)
            {
            }

            public function supports(string $ownerType): bool
            {
                return true;
            }

            public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
            {
                return $this->album;
            }

            public function isMediaAllowed(
                int $ownerId,
                int $mediaId,
                Role $currentRole,
                array $linkedMemberIds,
                ?int $userAccountId
            ): bool {
                $this->mediaCalls[] = [$ownerId, $mediaId, $currentRole, $linkedMemberIds, $userAccountId];

                return $this->media;
            }
        };
    }
}
