<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\MemberRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * `findByDeskIds()` on the engine production runs, against the schema as
 * the migration builds it.
 *
 * `desk_id IN (…)` is a comparison the COLLATION decides: under
 * utf8mb4_unicode_ci « t100 » finds the member stored as « T100 », where
 * SQLite's BINARY comparison does not. The method maps a row back to the
 * code asked for through a lowercased key; keyed by the stored spelling,
 * the engine would find the member and the caller would still be told
 * the code designates nobody. The alias path runs here too, with the real
 * foreign keys and the unique index on `member_desk_id_aliases.desk_id`.
 */
#[Group('database')]
final class MemberRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private MemberRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new MemberRepository($this->pdo);
    }

    public function testACodeIsFoundWhateverItsCaseAndKeyedAsAsked(): void
    {
        $memberId = $this->member('T100');
        $this->assertSame(
            '1',
            (string) $this->pdo->query("SELECT COUNT(*) FROM members WHERE desk_id = 't100'")->fetchColumn(),
            'the premise: this engine compares desk_id case-insensitively'
        );

        $this->assertSame(
            ['t100' => ['id' => $memberId, 'desk_id' => 'T100'], 'T100' => ['id' => $memberId, 'desk_id' => 'T100']],
            $this->repository->findByDeskIds(['t100', 'T100'])
        );
    }

    public function testAnAliasInAnotherCaseLeadsToTheMemberKept(): void
    {
        $kept = $this->member('K-1');
        $this->member('OLD-1', $kept);
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?)')
            ->execute([$kept, 'OLD-1']);

        $this->assertSame(['old-1' => ['id' => $kept, 'desk_id' => 'K-1']], $this->repository->findByDeskIds(['old-1']));
    }

    private function member(string $deskId, ?int $mergedInto = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id, merged_into_member_id) VALUES (?, ?)')
            ->execute([$deskId, $mergedInto]);

        return (int) $this->pdo->lastInsertId();
    }
}
