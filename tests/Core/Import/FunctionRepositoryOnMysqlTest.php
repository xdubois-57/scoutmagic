<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\FunctionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class FunctionRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The collation invariant used by DeskImportValidator's normalized function key. */
    #[DataProvider('caseAliases')]
    public function testDeskCodeLookupIgnoresLetterCaseOnProductionCollation(string $code, string $aliasCode): void
    {
        $repository = new FunctionRepository($this->productionEngine());
        $id = $repository->create($code, $code, 'identified', false);
        $alias = $repository->findByDeskCode($aliasCode);

        self::assertNotNull($alias);
        self::assertSame($id, $alias['id']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function caseAliases(): iterable
    {
        yield 'ASCII' => ['Intendant', 'INTENDANT'];
        yield 'Unicode' => ["Équipier d'unité", "équipier D'UNITÉ"];
    }
}
