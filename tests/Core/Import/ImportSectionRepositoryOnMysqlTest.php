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

    /**
     * The recomputation runs on the engine an installation runs (issue
     * #1460): an UPDATE of `sections` whose CASE reads the rosters, one
     * statement. A section with members from the given year on is active,
     * one whose members are all in an earlier year is not, and Staff d'U
     * always is.
     */
    public function testActivityIsRecomputedFromTheRostersFromAGivenYearOn(): void
    {
        $pdo = $this->productionEngine();
        $branch = (new AgeBranchRepository($pdo))->create('Louveteaux', 'Louveteaux');
        $repository = new ImportSectionRepository($pdo);
        $current = $repository->create('LOU_NOW', $branch, 'Meute de cette année');
        $next = $repository->create('LOU_NEXT', $branch, 'Meute de l’an prochain');
        $past = $repository->create('LOU_PAST', $branch, 'Meute d’avant');
        $empty = $repository->create('LOU_EMPTY', $branch, 'Meute vide');
        $staff = $repository->create('STAFFDU', $branch, "Staff d'U");
        $pdo->exec('UPDATE sections SET is_active = 0');

        $years = [
            'past' => $this->year($pdo, '2024-2025', '2024-09-01', '2025-08-31'),
            'current' => $this->year($pdo, '2025-2026', '2025-09-01', '2026-08-31'),
            'next' => $this->year($pdo, '2026-2027', '2026-09-01', '2027-08-31'),
        ];
        $pdo->exec("INSERT INTO functions (desk_code, label) VALUES ('ANIME', 'Animé')");
        $function = (int) $pdo->lastInsertId();
        $this->holds($pdo, 'T1', $years['current'], $function, $current);
        $this->holds($pdo, 'T2', $years['next'], $function, $next);
        $this->holds($pdo, 'T3', $years['past'], $function, $past);
        // Somebody whose row this year was deactivated does not count.
        $this->holds($pdo, 'T4', $years['current'], $function, $empty, false);

        $repository->recomputeActive('2025-09-01', 'STAFFDU');

        self::assertSame([$current, $next, $staff], $repository->activeIds());
    }

    private function year(\PDO $pdo, string $label, string $start, string $end): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $pdo->lastInsertId();
    }

    private function holds(\PDO $pdo, string $deskId, int $yearId, int $functionId, int $sectionId, bool $active = true): void
    {
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);
        $memberId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$memberId, $yearId, 'x', 'y', $active ? 1 : 0]);
        $memberYearId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)')
            ->execute([$memberYearId, $functionId, $sectionId]);
    }
}
