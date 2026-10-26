<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Database\InstrumentedPdo;
use Core\Database\QueryCounter;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MemberResolutionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `MemberResolutionRepository::findMemberIdsByDeskIds()`: the « Tiers » of
 * a mail-merge file leads to the CURRENT member, by the rule of
 * `Core\Import\MemberRepository::findByDeskId()` (issue #999). The
 * case-insensitive matching the collation decides is held on the real
 * engine by `MemberDeskIdLookupOnMysqlTest`.
 */
#[Group('database')]
final class MemberDeskIdLookupTest extends TestCase
{
    private \PDO $pdo;
    private MemberResolutionRepository $repository;

    protected function setUp(): void
    {
        // Instrumented, so the two tests below can count what was asked.
        $this->pdo = DatabaseTestHelper::createTestDatabase(new InstrumentedPdo('sqlite::memory:'));
        $this->repository = new MemberResolutionRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testADirectMatchIsKeyedByTheValueAskedFor(): void
    {
        $lucie = $this->member('1203456');
        $antoine = $this->member('1203457');

        $this->assertSame(
            ['1203456' => $lucie, '1203457' => $antoine],
            $this->repository->findMemberIdsByDeskIds(['1203456', '1203457', '1203456'])
        );
    }

    public function testAnUnknownValueIsAbsentRatherThanGuessed(): void
    {
        $this->member('1203456');

        $this->assertSame([], $this->repository->findMemberIdsByDeskIds(['9999999']));
    }

    public function testAnAliasLeadsToTheMemberItWasRegisteredFor(): void
    {
        $kept = $this->member('1000001');
        $this->alias($kept, '0999999');

        $this->assertSame(['0999999' => $kept], $this->repository->findMemberIdsByDeskIds(['0999999']));
    }

    /**
     * The abandoned row keeps its own desk_id — a merge deletes nothing —
     * so without excluding it, it would answer for the code its alias
     * was created to redirect, and the file would reach a member whose
     * page shows nothing any more.
     */
    public function testAMergedRowNeverAnswersForItselfItsAliasDoes(): void
    {
        $kept = $this->member('1000001');
        $abandoned = $this->member('1000002', mergedInto: $kept);
        $this->alias($kept, '1000002');

        $found = $this->repository->findMemberIdsByDeskIds(['1000002']);

        $this->assertSame(['1000002' => $kept], $found);
        $this->assertNotContains($abandoned, $found);
    }

    public function testAMergedRowWithNoAliasStillLeadsToTheMemberKept(): void
    {
        $kept = $this->member('1000001');
        $this->member('1000002', mergedInto: $kept);

        $this->assertSame(['1000002' => $kept], $this->repository->findMemberIdsByDeskIds(['1000002']));
    }

    /**
     * A second merge does not repoint the first one's alias: A was merged
     * into B, then B into C, and A's alias still names B.
     */
    public function testAChainOfMergesIsFollowedToTheCurrentMember(): void
    {
        $current = $this->member('C');
        $middle = $this->member('B', mergedInto: $current);
        $this->member('A', mergedInto: $middle);
        $this->alias($middle, 'A');
        $this->alias($current, 'B');

        $this->assertSame(
            ['A' => $current, 'B' => $current, 'C' => $current],
            $this->repository->findMemberIdsByDeskIds(['A', 'B', 'C'])
        );
    }

    public function testNothingAskedIsNoQuery(): void
    {
        QueryCounter::reset();
        $this->assertSame([], $this->repository->findMemberIdsByDeskIds(['', '']));
        $this->assertSame(0, QueryCounter::count(), 'an empty IN () is refused by MySQL and MariaDB');
    }

    /**
     * Grouped, as the import needs: a five-thousand-row file is three
     * queries here, not one per row.
     */
    public function testTheLookupIsGroupedWhateverTheNumberOfValues(): void
    {
        $values = [];
        for ($i = 0; $i < 30; $i++) {
            $kept = $this->member("K{$i}");
            $this->member("M{$i}", mergedInto: $kept);
            $this->alias($kept, "M{$i}");
            $values[] = "K{$i}";
            $values[] = "M{$i}";
        }
        QueryCounter::reset();
        $this->assertCount(60, $this->repository->findMemberIdsByDeskIds($values));
        $this->assertSame(3, QueryCounter::count(), 'members, aliases, one merge hop — never one query per value');
    }

    private function member(string $deskId, ?int $mergedInto = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id, merged_into_member_id) VALUES (?, ?)')
            ->execute([$deskId, $mergedInto]);

        return (int) $this->pdo->lastInsertId();
    }

    private function alias(int $memberId, string $deskId): void
    {
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?)')
            ->execute([$memberId, $deskId]);
    }
}
