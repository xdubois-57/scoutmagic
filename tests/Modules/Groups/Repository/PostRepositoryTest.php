<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The newest visible post per group — what the group list's « Nouveau »
 * badge reads instead of the activity clock (issue #1349).
 *
 * @group database
 */
#[Group('database')]
class PostRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PostRepository $repository;
    private GroupRepository $groups;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->repository = new PostRepository($this->pdo);
        $this->groups = new GroupRepository($this->pdo);
    }

    public function testFindLatestCreatedAtByGroupsKeepsTheNewestVisiblePostOfOthersPerGroup(): void
    {
        $a = $this->groups->create('A', null, null, 1);
        $b = $this->groups->create('B', null, null, 1);
        $empty = $this->groups->create('Vide', null, null, 1);

        GroupsTestHelper::createPostAt($this->pdo, $a, 'ancien', '2026-01-01 10:00:00', 1, 9);
        GroupsTestHelper::createPostAt($this->pdo, $a, 'le mien', '2026-01-01 12:00:00', 1, 5);
        $hidden = GroupsTestHelper::createPostAt($this->pdo, $a, 'masqué', '2026-01-01 13:00:00', 1, 9);
        $this->repository->setHiddenAt($hidden, '2026-01-01 14:00:00');
        GroupsTestHelper::createPostAt($this->pdo, $b, 'ailleurs', '2026-01-01 11:00:00', 1, 9);

        $latest = $this->repository->findLatestCreatedAtByGroups([$a, $b, $empty], [5]);
        ksort($latest);

        $this->assertSame([$a => '2026-01-01 10:00:00', $b => '2026-01-01 11:00:00'], $latest);
        $this->assertSame(
            ['2026-01-01 12:00:00'],
            array_values($this->repository->findLatestCreatedAtByGroups([$a])),
            'with nobody excluded, the reader\'s own post counts too'
        );
    }

    public function testFindLatestCreatedAtByGroupsAnswersEmptyForNoGroups(): void
    {
        $this->assertSame([], $this->repository->findLatestCreatedAtByGroups([]));
    }
}
