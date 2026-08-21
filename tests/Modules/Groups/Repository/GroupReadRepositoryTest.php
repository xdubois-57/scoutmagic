<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\GroupRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The per-member read mark: written once per group view, read in batch for
 * the group list and the home card, and the source of the "vu par" answer.
 *
 * @group database
 */
#[Group('database')]
class GroupReadRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private GroupReadRepository $repository;
    private int $groupId;
    private int $otherGroupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupRepo = new GroupRepository($this->pdo);
        $this->groupId = $groupRepo->create('Louveteaux', null, null, 1);
        $this->otherGroupId = $groupRepo->create('Baladins', null, null, 1);

        $this->repository = new GroupReadRepository($this->pdo);
    }

    public function testMarkReadThenReadBackRoundTrips(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 10:00:00');

        $this->assertSame(
            [$this->groupId => '2026-01-01 10:00:00'],
            $this->repository->lastReadFor([$this->groupId], [3])
        );
    }

    public function testMarkingReadAgainMovesTheMarkForward(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 10:00:00');
        $this->repository->markRead($this->groupId, 3, '2026-01-01 12:00:00');

        $this->assertSame(
            [$this->groupId => '2026-01-01 12:00:00'],
            $this->repository->lastReadFor([$this->groupId], [3])
        );
    }

    /**
     * Two tabs genuinely race here — an older one finishing its write
     * after a newer one must not un-read what the newer one already saw.
     */
    public function testAnOlderMarkNeverMovesTheMarkBackwards(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 12:00:00');
        $this->repository->markRead($this->groupId, 3, '2026-01-01 10:00:00');

        $this->assertSame(
            [$this->groupId => '2026-01-01 12:00:00'],
            $this->repository->lastReadFor([$this->groupId], [3])
        );
    }

    public function testLastReadForIsBatchedAcrossGroupsAndOnlyReturnsWhatWasRead(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 10:00:00');

        $result = $this->repository->lastReadFor([$this->groupId, $this->otherGroupId], [3]);

        $this->assertSame([$this->groupId => '2026-01-01 10:00:00'], $result);
        $this->assertArrayNotHasKey($this->otherGroupId, $result, 'a never-opened group is simply absent');
    }

    public function testLastReadForIgnoresAnotherMembersMark(): void
    {
        $this->repository->markRead($this->groupId, 4, '2026-01-01 10:00:00');

        $this->assertSame([], $this->repository->lastReadFor([$this->groupId], [3]));
    }

    /**
     * An account linked to two members of the same group has ONE reading
     * position — whichever of its members recorded the latest one.
     */
    public function testLastReadForTakesTheLatestAcrossEveryLinkedMember(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 10:00:00');
        $this->repository->markRead($this->groupId, 4, '2026-01-01 15:00:00');

        $this->assertSame(
            [$this->groupId => '2026-01-01 15:00:00'],
            $this->repository->lastReadFor([$this->groupId], [3, 4])
        );
    }

    public function testLastReadForWithNoIdsReturnsEmptyWithoutQuerying(): void
    {
        $this->assertSame([], $this->repository->lastReadFor([], [3]));
        $this->assertSame([], $this->repository->lastReadFor([$this->groupId], []));
    }

    public function testMembersWhoReadSinceOnlyReturnsThoseWhoOpenedItAfterwards(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 09:00:00');
        $this->repository->markRead($this->groupId, 4, '2026-01-01 11:00:00');
        $this->repository->markRead($this->groupId, 5, '2026-01-01 12:00:00');

        $this->assertSame([4, 5], $this->repository->membersWhoReadSince($this->groupId, '2026-01-01 10:00:00'));
    }

    public function testMembersWhoReadSinceIsScopedToItsOwnGroup(): void
    {
        $this->repository->markRead($this->otherGroupId, 3, '2026-01-01 12:00:00');

        $this->assertSame([], $this->repository->membersWhoReadSince($this->groupId, '2026-01-01 10:00:00'));
    }

    public function testReadMarksForGroupReturnsEveryMarkKeyedByMember(): void
    {
        $this->repository->markRead($this->groupId, 3, '2026-01-01 09:00:00');
        $this->repository->markRead($this->groupId, 4, '2026-01-01 11:00:00');

        $this->assertSame(
            [3 => '2026-01-01 09:00:00', 4 => '2026-01-01 11:00:00'],
            $this->repository->readMarksForGroup($this->groupId)
        );
    }

    public function testReadMarksForGroupIsScopedToItsOwnGroup(): void
    {
        $this->repository->markRead($this->otherGroupId, 3, '2026-01-01 09:00:00');

        $this->assertSame([], $this->repository->readMarksForGroup($this->groupId));
    }
}
