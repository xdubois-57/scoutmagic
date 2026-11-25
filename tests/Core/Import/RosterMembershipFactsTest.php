<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\RosterMembershipFacts;
use Core\Import\RosterSnapshotRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\UsesProductionEngine;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class RosterMembershipFactsTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RosterSnapshotRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->seed();
    }

    private function seed(): void
    {
        $this->repository = new RosterSnapshotRepository($this->pdo);
        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date) VALUES
            (10, '2026-2027', '2026-09-01', '2027-08-31'),
            (11, '2010-2011', '2010-09-01', '2011-08-31'),
            (12, '2025-2026', '2025-09-01', '2026-08-31')");
        $this->pdo->exec("INSERT INTO members (id, desk_id) VALUES (1, 'ZX777_1')");
        $this->pdo->exec("INSERT INTO member_years (id, member_id, scout_year_id,
                first_name_encrypted, last_name_encrypted)
            VALUES (1, 1, 10, 'enc', 'enc')");
        $this->pdo->exec(
            "INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ZX777_FN', 'Fonction "
            . "test', 'identified')"
        );
    }

    private function addStart(?string $date, bool $main = false): void
    {
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, start_date, '
            . 'is_main_function) VALUES (1, 1, ?, ?)'
        )
            ->execute([$date, (int) $main]);
    }

    private function previousImport(string $date): void
    {
        $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) '
            . 'VALUES (12, 0, 0, ?)'
        )
            ->execute([$date]);
    }

    /** @return array{?string, ?bool, ?bool} */
    private static function values(RosterMembershipFacts $facts): array
    {
        return [
            $facts->firstFunctionStart?->format('Y-m-d'), $facts->allFunctionStartsKnown, $facts->presentPreviousYear,
        ];
    }

    public function testLiveFactsChangeButTheCapturedFactsStayFrozen(): void
    {
        $this->addStart('2027-06-10', true);
        $this->addStart('2027-05-31');
        $this->previousImport('2026-08-01 00:00:00');
        $asOf = new \DateTimeImmutable('2027-07-01 12:00:00');
        $snapshot = $this->repository->capture(10, $asOf);
        $this->pdo->exec("UPDATE member_functions SET start_date = '2027-07-01'");
        $this->pdo->exec("INSERT INTO member_years (member_id, scout_year_id,
                first_name_encrypted, last_name_encrypted, is_active)
            VALUES (1, 12, 'enc', 'enc', 0)");

        self::assertSame([
            ['2027-07-01', true, true], ['2027-05-31', true, false],
        ], [
            self::values($this->repository->findMembershipFactsForYear(10, $asOf)[1]),
            self::values($this->repository->findMembers($snapshot->id)[0]->membershipFacts),
        ]);
    }

    /** @return iterable<string, array{list<?string>, ?string, bool}> */
    public static function starts(): iterable
    {
        yield 'no functions' => [[], null, false];
        yield 'null only' => [[null], null, false];
        yield 'known and missing' => [['2027-06-01', null], '2027-06-01', false];
        yield 'all known minimum' => [['2027-06-10', '2027-05-31'], '2027-05-31', true];
    }

    #[DataProvider('starts')]
    public function testEveryFunctionContributesToTheMinimumAndCompleteness(
        array $starts,
        ?string $first,
        bool $complete
    ): void
    {
        foreach ($starts as $index => $date) {
            $this->addStart($date, $index === 0);
        }
        self::assertSame([$first, $complete, null], self::values(
            $this->repository->findMembershipFactsForYear(10, new \DateTimeImmutable('2027-07-01'))[1]
        ));
    }

    /** @return iterable<string, array{?string, ?int, ?bool}> */
    public static function previousEvidence(): iterable
    {
        yield 'created year without import' => [null, null, null];
        yield 'qualified absence' => ['2026-08-01', null, false];
        yield 'import too late' => ['2027-08-01', null, null];
        yield 'inactive presence excludes' => [null, 0, true];
        yield 'presence despite late import' => ['2027-08-01', 1, true];
    }

    #[DataProvider('previousEvidence')]
    public function testAbsenceNeedsAnImportBeforeTheObservation(?string $import, ?int $active, ?bool $expected): void
    {
        if ($import !== null) {
            $this->previousImport($import);
        }
        if ($active !== null) {
            $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
                . 'last_name_encrypted, is_active) VALUES (1, 12, ?, ?, ?)'
            )
                ->execute(['enc', 'enc', $active]);
        }
        $facts = $this->repository->findMembershipFactsForYear(10, new \DateTimeImmutable('2027-07-01'));
        self::assertSame($expected, $facts[1]->presentPreviousYear);
    }

    public function testLegacySnapshotFactsAreNotFilledFromTheLiveRoster(): void
    {
        $this->addStart('2027-06-01');
        $this->previousImport('2026-08-01');
        $this->pdo->exec(
            "INSERT INTO fees_roster_snapshots (id, scout_year_id, taken_at, member_count) VALUES (1, "
            . "10, '2027-07-01', 1)"
        );
        $this->pdo->exec('INSERT INTO fees_roster_snapshot_members (snapshot_id, member_id) VALUES (1, 1)');
        self::assertSame([null, null, null], self::values($this->repository->findMembers(1)[0]->membershipFacts));
    }

    public function testTheProjectionRunsOnTheDeclaredMysqlSchema(): void
    {
        $this->pdo = $this->productionEngine();
        $this->seed();
        $this->addStart('2027-06-01', true);
        $this->addStart(null);
        $this->previousImport('2026-08-01');
        $snapshot = $this->repository->capture(10, new \DateTimeImmutable('2027-07-01'));
        self::assertSame(
            ['2027-06-01', false, false],
            self::values($this->repository->findMembers($snapshot->id)[0]->membershipFacts)
        );
    }
}
