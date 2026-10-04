<?php

declare(strict_types=1);

namespace Tests\Core\Member\Repository;

use Core\Member\Repository\MemberSectionTotemRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #722: the section totem (« Akela ») is per member-year AND per
 * section, set by a chief and nothing else.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberSectionTotemRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberSectionTotemRepository $repo;
    private int $memberId;
    private int $memberYearId;
    private int $louveteaux;
    private int $eclaireurs;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new MemberSectionTotemRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('L1', {$branchId}, 'Meute')");
        $this->louveteaux = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('E1', {$branchId}, 'Troupe')");
        $this->eclaireurs = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->memberYearId = $this->insertMemberYear($yearId);
    }

    public function testSetStoresTheTotemEncrypted(): void
    {
        $this->repo->set($this->memberYearId, $this->louveteaux, '  Akela ');

        $this->assertSame([$this->memberYearId => [$this->louveteaux => 'Akela']], $this->repo->forMemberYears([$this->memberYearId]));
        $stored = (string) $this->pdo->query('SELECT totem_encrypted FROM member_section_totems')->fetchColumn();
        $this->assertStringNotContainsString('Akela', $stored);
    }

    public function testSetReplacesAndBlankRemoves(): void
    {
        $this->repo->set($this->memberYearId, $this->louveteaux, 'Akela');
        $this->repo->set($this->memberYearId, $this->louveteaux, 'Baloo');
        $this->assertSame([$this->louveteaux => 'Baloo'], $this->repo->forMemberYears([$this->memberYearId])[$this->memberYearId]);

        $this->repo->set($this->memberYearId, $this->louveteaux, '   ');
        $this->assertSame([], $this->repo->forMemberYears([$this->memberYearId]));
        $this->repo->set($this->memberYearId, $this->louveteaux, null);
        $this->assertSame([], $this->repo->forMemberYears([$this->memberYearId]));
    }

    public function testOneTotemPerSection(): void
    {
        $this->repo->set($this->memberYearId, $this->louveteaux, 'Akela');
        $this->repo->set($this->memberYearId, $this->eclaireurs, 'Hathi');

        $this->assertSame(
            [$this->louveteaux => 'Akela', $this->eclaireurs => 'Hathi'],
            $this->repo->forMemberYears([$this->memberYearId])[$this->memberYearId]
        );
    }

    /**
     * Nothing is carried into a new scout year: the next year's member-year
     * row starts without a section totem.
     */
    public function testANewScoutYearStartsWithoutSectionTotem(): void
    {
        $this->repo->set($this->memberYearId, $this->louveteaux, 'Akela');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $nextYear = $this->insertMemberYear((int) $this->pdo->lastInsertId());

        $this->assertSame([], $this->repo->forMemberYears([$nextYear]));
        $this->assertSame([], $this->repo->forMemberYears([]));
    }

    private function insertMemberYear(int $scoutYearId): int
    {
        $this->pdo->exec("INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES ({$this->memberId}, {$scoutYearId}, 'x', 'y')");
        return (int) $this->pdo->lastInsertId();
    }
}
