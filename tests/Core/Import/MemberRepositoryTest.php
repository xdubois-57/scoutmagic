<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\MemberRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `findByDeskIds()`, the grouped form of `findByDeskId()` — and the
 * same rule, which is the point: a code is the member it names, or, once
 * that record was merged away, the member a merge kept (issue #1381,
 * for the Desk ids a news form answer stores).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new MemberRepository($this->pdo);
    }

    public function testCodesAreFoundInOneCallAndKeyedAsAsked(): void
    {
        $first = $this->member('T100');
        $second = $this->member('T200');

        $this->assertSame(
            ['T200' => ['id' => $second, 'desk_id' => 'T200'], 'T100' => ['id' => $first, 'desk_id' => 'T100']],
            $this->repository->findByDeskIds(['T200', 'T100', 'T999', '', 'T200'])
        );
        $this->assertSame([], $this->repository->findByDeskIds([]));
    }

    public function testAnAbandonedCodeLeadsToTheMemberKeptExactlyAsFindByDeskIdDoes(): void
    {
        $kept = $this->member('T100');
        $abandoned = $this->member('T900', $kept);
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?)')->execute([$kept, 'T900']);

        $grouped = $this->repository->findByDeskIds(['T900', 'T100']);

        $this->assertSame(['id' => $kept, 'desk_id' => 'T100'], $grouped['T900']);
        $this->assertSame($this->repository->findByDeskId('T900'), $grouped['T900']);
        $this->assertNotSame($abandoned, $grouped['T900']['id'], 'never the row merged away');
    }

    private function member(string $deskId, ?int $mergedInto = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id, merged_into_member_id) VALUES (?, ?)')
            ->execute([$deskId, $mergedInto]);

        return (int) $this->pdo->lastInsertId();
    }
}
