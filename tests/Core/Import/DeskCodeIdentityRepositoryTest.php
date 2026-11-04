<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Database\InstrumentedPdo;
use Core\Database\QueryCounter;
use Core\Import\DeskCodeIdentityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('database')]
final class DeskCodeIdentityRepositoryTest extends TestCase
{
    public function testSqliteUsesItsBinaryComparisonAndBindsEveryCodeLiterally(): void
    {
        $repository = new DeskCodeIdentityRepository(new InstrumentedPdo('sqlite::memory:'));
        $before = QueryCounter::count();
        $codes = ['Intendant', 'INTENDANT', 'Équipier', 'Equipier', '', "\u{200B}", "a' OR 1=1 --", '0', '00'];

        $identities = $repository->identify([...$codes, ...$codes]);

        self::assertSame($codes, array_map('strval', array_keys($identities)));
        self::assertSame(count($codes), count(array_unique($identities)));
        self::assertSame(1, QueryCounter::count() - $before);
    }

    public function testAnEmptyBatchDoesNotQueryTheDatabase(): void
    {
        $repository = new DeskCodeIdentityRepository(new InstrumentedPdo('sqlite::memory:'));
        $before = QueryCounter::count();

        self::assertSame([], $repository->identify([]));
        self::assertSame(0, QueryCounter::count() - $before);
    }
}
