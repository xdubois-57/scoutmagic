<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\Repository\SectionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The two section-by-year reads the "Année scoute" workflow leans on,
 * judged on the engine production runs and the schema the real migration
 * builds: a GROUP BY with two conditional COUNT(DISTINCT …), and a LEFT
 * JOIN whose ordering puts a holder's main function first. The SQLite
 * fixture of the other tests is written by hand and would pass a query
 * the server reads differently.
 */
#[Group('database')]
class SectionYearReadsOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $yearId;
    private int $branchId;
    private int $chiefFunctionId;
    private int $animeFunctionId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2027-2028', '2027-09-01', '2028-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $this->branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Animateur', 'chief')");
        $this->chiefFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIME', 'Animé', 'identified')");
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();
    }

    public function testHeadcountsCountEachPersonOncePerRole(): void
    {
        $meute = $this->section('LOUV1', 'Meute');
        $chief = $this->memberYear();
        // Two functions in the same section: still one animateur.
        $this->assign($chief, $meute, $this->chiefFunctionId, true);
        $this->assign($chief, $meute, $this->chiefFunctionId, false);
        $this->assign($this->memberYear(), $meute, $this->animeFunctionId, true);
        $this->assign($this->memberYear(), $meute, $this->animeFunctionId, true);

        $rows = (new SectionRepository(Connection::withPdo($this->pdo)))->headcountsForYear($this->yearId);

        $this->assertCount(1, $rows);
        $this->assertSame($meute, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['chiefs']);
        $this->assertSame(2, $rows[0]['animes']);
    }

    public function testABadgeHoldingCarriesTheHoldersMainSection(): void
    {
        $first = $this->section('LOUV1', 'Meute');
        $main = $this->section('LOUV2', 'Meute B');
        $holder = $this->memberYear();
        $this->assign($holder, $first, $this->chiefFunctionId, false);
        $this->assign($holder, $main, $this->chiefFunctionId, true);
        $sectionless = $this->memberYear();
        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $badgeId = (int) $this->pdo->lastInsertId();
        $repository = new MemberBadgeRepository($this->pdo);
        $repository->assign($holder, $badgeId, null);
        $repository->assign($sectionless, $badgeId, null);

        $this->assertSame(
            [
                ['badge_id' => $badgeId, 'member_year_id' => $holder, 'section_id' => $main],
                ['badge_id' => $badgeId, 'member_year_id' => $sectionless, 'section_id' => null],
            ],
            $repository->findHoldingsWithSectionForScoutYear($this->yearId)
        );
    }

    private function section(string $deskCode, string $name): int
    {
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)')
            ->execute([$deskCode, $this->branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function memberYear(): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D-' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        )->execute([$memberId, $this->yearId, 'x', 'x']);

        return (int) $this->pdo->lastInsertId();
    }

    private function assign(int $memberYearId, int $sectionId, int $functionId, bool $main): void
    {
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$memberYearId, $functionId, $sectionId, $this->branchId, $main ? 1 : 0]);
    }
}
