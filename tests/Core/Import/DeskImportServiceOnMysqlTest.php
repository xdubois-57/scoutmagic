<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskImportRejectedException;
use Core\Import\DeskImportRule;
use Core\Import\FunctionRepository;
use Core\Import\ImportSectionRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\UsesProductionEngine;

/** Validation and persistence must agree on the database's code equivalence. */
#[Group('database')]
final class DeskImportServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private DeskImportReplay $replay;
    private string $root;
    private int $yearId;
    private int $importerId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->importerId = (new UserAccountRepository($this->pdo, $encryption))
            ->create('importer@example.com', true)->id;
        $this->yearId = (new ScoutYearService($this->pdo))->ensureYear('2025-2026');
        $this->root = sys_get_temp_dir() . '/desk_collation_' . bin2hex(random_bytes(8));
        mkdir($this->root . '/desk', 0777, true);
        mkdir($this->root . '/storage');
        $this->replay = new DeskImportReplay($this->pdo, $encryption, $this->root, $this->root . '/storage');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    #[DataProvider('codeAliases')]
    public function testAnUnmarkedFunctionWithCodeAliasesIsImportedOnceAsMain(
        string $column,
        string $code,
        string $alias,
        string $unmarked,
        bool $alreadyKnown
    ): void {
        [$first, $second] = $this->aliasRows($column, $code, $alias, $alreadyKnown);
        $first['Fonction principale'] = 'false';
        $second['Fonction principale'] = $unmarked;
        $this->writeRows([$first, $second]);

        $this->replay->importYear('2025-2026', $this->yearId, $this->importerId);

        self::assertSame([1, 1, 2], $this->counts([
            'functions', 'member_functions', 'member_addresses',
        ]));
        self::assertSame(1, (int) $this->pdo->query('SELECT is_main_function FROM member_functions')->fetchColumn());
        $function = $this->pdo->query('SELECT role, confirmed FROM functions')->fetch(\PDO::FETCH_ASSOC);
        // The attested standard gets defaults only when newly encountered;
        // an already stored, unconfirmed mapping is preserved even here.
        $newStandard = $column === 'FONCTION' && $code === "Équipier d'unité" && !$alreadyKnown;
        self::assertSame($newStandard
            ? ['role' => 'admin', 'confirmed' => 1]
            : ['role' => 'identified', 'confirmed' => 0], $function);
    }

    #[DataProvider('codeAliases')]
    public function testContradictoryFlagsOnCodeAliasesAreRefusedBeforeAnyImportWrite(
        string $column,
        string $code,
        string $alias,
        string $unmarked,
        bool $alreadyKnown
    ): void {
        [$first, $second] = $this->aliasRows($column, $code, $alias, $alreadyKnown);
        $first['Fonction principale'] = 'true';
        $second['Fonction principale'] = $unmarked;
        $this->writeRows([$first, $second]);

        $this->assertRefusedWithoutWrites('la même fonction');
    }

    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function codeAliases(): iterable
    {
        $pairs = [
            'accent' => ["Équipier d'unité", "Equipier d'unite"],
            'fullwidth' => ['REFERENT', 'ＲＥＦＥＲＥＮＴ'],
            'ignorable after trailing space' => ['Référent', "Référent \u{200B}"],
        ];
        foreach (['FONCTION', 'Branche', 'Section'] as $column) {
            foreach ($pairs as $name => [$code, $alias]) {
                foreach (['false', ''] as $flag) {
                    foreach ([false, true] as $known) {
                        yield $column . '/' . $name . '/' . $flag . '/' . (int) $known
                            => [$column, $code, $alias, $flag, $known];
                    }
                }
            }
        }
    }

    #[DataProvider('absentCodes')]
    public function testAnAbsentAssociationDiffersFromANonemptyIgnorableCode(string $column): void
    {
        $first = ['FONCTION' => 'Intendant', 'Branche' => 'Louveteaux', 'Section' => ''];
        if ($column === 'Branche') {
            $first['Branche'] = '';
        }
        $second = array_replace($first, [$column => "\u{200B}"]);
        $this->writeRows([$first, $second]);

        $this->assertRefusedWithoutWrites('aucune fonction');
    }

    /** @return iterable<string, array{string}> */
    public static function absentCodes(): iterable
    {
        yield 'branch' => ['Branche'];
        yield 'section' => ['Section'];
    }

    /**
     * Assert the real collation's premise, then exercise both existing mappings
     * and codes encountered for the first time by this file.
     * @return array{array<string, string>, array<string, string>}
     */
    private function aliasRows(string $column, string $code, string $alias, bool $alreadyKnown): array
    {
        $comparison = $this->pdo->prepare(
            'SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4)'
        );
        $comparison->execute([$code, $alias]);
        self::assertSame(1, (int) $comparison->fetchColumn(), 'This pair must really be equivalent on this engine.');

        $first = array_replace([
            'FONCTION' => 'Intendant', 'Branche' => 'Louveteaux', 'Section' => 'SEC1',
        ], [$column => $code]);
        $second = array_replace($first, [
            $column => $alias, "Type d'adresse" => 'Adresse secondaire', 'Rue' => 'Rue B',
        ]);
        if ($alreadyKnown) {
            (new FunctionRepository($this->pdo))->create($first['FONCTION'], $first['FONCTION'], 'identified', false);
            $branch = (new AgeBranchRepository($this->pdo))->create($first['Branche'], $first['Branche']);
            (new ImportSectionRepository($this->pdo))->create($first['Section'], $branch, 'Première section');
        }

        return [$first, $second];
    }

    private function assertRefusedWithoutWrites(string $reason): void
    {
        $tables = [
            'functions', 'age_branches', 'sections', 'members', 'member_years', 'member_functions',
            'member_addresses', 'user_accounts', 'files', 'import_journal', 'fees_roster_snapshots',
        ];
        $before = $this->counts($tables);
        try {
            $this->replay->importYear('2025-2026', $this->yearId, $this->importerId);
            self::fail('The whole file must be refused before anything is written.');
        } catch (DeskImportRejectedException $e) {
            self::assertSame([DeskImportRule::MAIN_FUNCTION], array_map(static fn($error) => $error->rule, $e->errors));
            self::assertStringContainsString($reason, $e->getMessage());
        }
        self::assertSame($before, $this->counts($tables), 'The rejection must precede mapping creation and roster writes.');
        self::assertSame(['.', '..'], scandir($this->root . '/storage'));
    }

    /** @param list<array<string, string>> $rows */
    private function writeRows(array $rows): void
    {
        $sample = file(dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($sample);
        $header = str_getcsv($sample[0], ';', '"', '\\');
        $template = array_slice(str_getcsv($sample[1], ';', '"', '\\'), 0, count($header));
        $file = fopen($this->root . '/desk/2025-2026.csv', 'w');
        self::assertNotFalse($file);
        fwrite($file, $sample[0] . "\n");
        foreach ($rows as $overrides) {
            $values = array_replace(array_combine($header, $template), [
                'Tiers' => 'T900', 'Date début' => '', 'Date fin' => '', 'fin de mandat' => '',
                'Fonction principale' => 'false',
            ], $overrides);
            fputcsv($file, array_values($values), ';', '"', '\\');
        }
        fclose($file);
    }

    /** @param list<string> $tables
     *  @return list<int>
     */
    private function counts(array $tables): array
    {
        return array_map(fn(string $table): int => (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $tables);
    }
}
