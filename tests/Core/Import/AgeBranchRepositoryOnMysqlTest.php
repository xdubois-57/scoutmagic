<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class AgeBranchRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The validator must use the same branch identity as the production lookup. */
    public function testDeskCodeLookupIgnoresUnicodeLetterCase(): void
    {
        $repository = new AgeBranchRepository($this->productionEngine());
        $id = $repository->create('Éclaireurs', 'Éclaireurs');
        $alias = $repository->findByDeskCode('éclaireurs');

        self::assertNotNull($alias);
        self::assertSame($id, $alias['id']);
    }
}
