<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use Core\Import\ImportSectionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class ImportSectionRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** Address-line code variants keep the same section id and branch on the real schema. */
    public function testDeskCodeLookupIgnoresLetterCase(): void
    {
        $pdo = $this->productionEngine();
        $branch = (new AgeBranchRepository($pdo))->create('Louveteaux', 'Louveteaux');
        $repository = new ImportSectionRepository($pdo);
        $id = $repository->create('Louv1', $branch, 'Renards');
        $alias = $repository->findByDeskCode('LOUV1');

        self::assertNotNull($alias);
        self::assertSame($id, $alias['id']);
        self::assertSame($branch, $alias['age_branch_id']);
    }
}
