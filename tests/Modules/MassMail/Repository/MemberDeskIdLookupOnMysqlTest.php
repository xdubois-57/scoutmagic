<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MemberResolutionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The « Tiers » lookup of a mail-merge file on the engine production
 * runs, against the schema as the migration builds it.
 *
 * `desk_id IN (…)` is a comparison the COLLATION decides: under
 * utf8mb4_unicode_ci « abc1 » finds the member stored as « ABC1 », where
 * SQLite's BINARY comparison does not. The repository therefore maps a row
 * back to the value asked for through a lowercased key; keyed by the
 * stored spelling, the engine would find the member and the import would
 * still call the Tiers unknown and refuse the file. The alias and merge
 * paths are replayed here too, with the real foreign keys and the real
 * unique index on `member_desk_id_aliases.desk_id`.
 */
#[Group('database')]
final class MemberDeskIdLookupOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private MemberResolutionRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new MemberResolutionRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testADeskIdIsFoundWhateverItsCaseAndKeyedAsAsked(): void
    {
        $memberId = $this->member('ABC1');
        $this->assertSame(
            '1',
            (string) $this->pdo->query("SELECT COUNT(*) FROM members WHERE desk_id = 'abc1'")->fetchColumn(),
            'the premise: this engine compares desk_id case-insensitively'
        );

        $this->assertSame(
            ['abc1' => $memberId, 'ABC1' => $memberId],
            $this->repository->findMemberIdsByDeskIds(['abc1', 'ABC1'])
        );
    }

    public function testAnAliasInAnotherCaseLeadsToTheMemberKept(): void
    {
        $kept = $this->member('K-1');
        $this->member('OLD-1', $kept);
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?)')
            ->execute([$kept, 'OLD-1']);

        $this->assertSame(['old-1' => $kept], $this->repository->findMemberIdsByDeskIds(['old-1']));
    }

    public function testAChainOfMergesEndsOnTheCurrentMember(): void
    {
        $current = $this->member('C-1');
        $middle = $this->member('B-1', $current);
        $this->member('A-1', $middle);
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?), (?, ?)')
            ->execute([$middle, 'A-1', $current, 'B-1']);

        $this->assertSame(
            ['A-1' => $current, 'B-1' => $current, 'C-1' => $current],
            $this->repository->findMemberIdsByDeskIds(['A-1', 'B-1', 'C-1', 'Z-9']),
            'and a value nobody knows stays absent'
        );
    }

    private function member(string $deskId, ?int $mergedInto = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id, merged_into_member_id) VALUES (?, ?)')
            ->execute([$deskId, $mergedInto]);

        return (int) $this->pdo->lastInsertId();
    }
}
