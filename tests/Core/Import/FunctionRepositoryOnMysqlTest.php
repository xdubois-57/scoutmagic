<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\FunctionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class FunctionRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The collation invariant used by DeskImportValidator's normalized function key. */
    public function testDeskCodeLookupIgnoresLetterCaseOnProductionCollation(): void
    {
        $repository = new FunctionRepository($this->productionEngine());
        $id = $repository->create('Intendant', 'Intendant', 'identified', false);
        $alias = $repository->findByDeskCode('INTENDANT');

        self::assertNotNull($alias);
        self::assertSame($id, $alias['id']);
    }
}
