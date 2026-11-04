<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Database\QueryCounter;
use Core\Import\DeskCodeIdentityRepository;
use Core\Import\FunctionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class DeskCodeIdentityRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    #[DataProvider('codePairs')]
    public function testIdentitiesAgreeWithTheActualMappingLookup(string $code, string $other, bool $equivalent): void
    {
        $pdo = $this->productionEngine();
        $functions = new FunctionRepository($pdo);
        $id = $functions->create($code, $code, 'identified', false);
        $found = $functions->findByDeskCode($other);
        self::assertSame($equivalent ? $id : null, $found['id'] ?? null, 'The real mapping lookup must establish the premise.');

        $identities = (new DeskCodeIdentityRepository($pdo))->identify([$code, $other]);

        self::assertSame($equivalent, $identities[$code] === $identities[$other]);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function codePairs(): iterable
    {
        yield 'case' => ['Intendant', 'INTENDANT', true];
        yield 'accents' => ["Équipier d'unité", "Equipier d'unite", true];
        yield 'decomposed accents' => ['Éclaireurs', "E\u{0301}claireurs", true];
        yield 'fullwidth' => ['REFERENT', 'ＲＥＦＥＲＥＮＴ', true];
        yield 'ignorable inside' => ['Intendant', "In\u{200B}tendant", true];
        yield 'trailing spaces' => ['Intendant', 'Intendant  ', true];
        yield 'space before trailing ignorable' => ['Intendant', "Intendant \u{200B}", true];
        yield 'many trailing ignorables' => ['Intendant', 'Intendant' . str_repeat("\u{200B}", 2048), true];
        yield 'difference after a long prefix' => ['Intendant', 'Intendant' . str_repeat(' ', 2048) . 'A', false];
        yield 'punctuation stays significant' => ['Référent-A', 'Referent A', false];
        yield 'inner spaces stay significant' => ['Référent A', 'Référent  A', false];
        yield 'non-Latin codes stay significant' => ['東京', '大阪', false];
        yield 'numeric codes stay significant' => ['0', '00', false];
        yield 'an ignored-only string really compares as empty' => ['', "\u{200B}", true];
    }

    public function testUnseenCodesAreComparedInOneReadOnlyBatchBeyondPlaceholderLimits(): void
    {
        $pdo = $this->productionEngine();
        $repository = new DeskCodeIdentityRepository($pdo);
        $codes = [];
        for ($i = 0; $i < 600; $i++) {
            $codes[] = 'RÉFÉRENT-' . $i;
            $codes[] = 'Referent-' . $i;
        }
        $before = QueryCounter::count();

        $identities = $repository->identify([...$codes, ...$codes]);

        self::assertSame(1, QueryCounter::count() - $before);
        self::assertCount(600, array_unique($identities));
        $pairsAgree = [];
        for ($i = 0; $i < 600; $i++) {
            $pairsAgree[] = $identities['RÉFÉRENT-' . $i] === $identities['Referent-' . $i];
        }
        self::assertSame(array_fill(0, 600, true), $pairsAgree);
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM functions')->fetchColumn());
    }
}
