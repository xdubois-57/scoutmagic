<?php

declare(strict_types=1);

namespace Tests\Modules\MemberStats\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\MemberStats\Repository\MemberStatsRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Repository behaviour against SQLite: only active animés are returned, and
 * animateurs/chefs (members holding any elevated function) are excluded — the
 * fix for the inflated statistics counts.
 *
 * @group database
 */
class MemberStatsRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberStatsRepository $repo;
    private int $yearId = 1;

    private int $baladinsBranchId;
    private int $louveteauxBranchId;
    private int $animeFnId;
    private int $chiefFnId;
    private int $intendantFnId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repo = new MemberStatsRepository(Connection::withPdo($this->pdo), $this->enc);

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31')");

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 10)");
        $this->baladinsBranchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->louveteauxBranchId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('MEMBRE', 'Membre', 'identified')");
        $this->animeFnId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $this->chiefFnId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('INT', 'Intendant', 'intendant')");
        $this->intendantFnId = (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, array{fn: int, branch: ?int, main?: bool}> $functions
     */
    private function seedMember(string $deskId, string $birthDate, ?string $gender, array $functions, bool $active = true): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->yearId,
            $this->enc->encrypt('First'),
            $this->enc->encrypt('Last'),
            $this->enc->encrypt($birthDate),
            $gender !== null ? $this->enc->encrypt($gender) : null,
            $active ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        foreach ($functions as $f) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$memberYearId, $f['fn'], $f['branch'], ($f['main'] ?? false) ? 1 : 0]);
        }
    }

    public function testOnlyActiveAnimesAreReturned(): void
    {
        // Animé baladin.
        $this->seedMember('D1', '2019-05-01', 'M', [
            ['fn' => $this->animeFnId, 'branch' => $this->baladinsBranchId, 'main' => true],
        ]);
        // Animé louveteau.
        $this->seedMember('D2', '2016-03-01', 'F', [
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
        ]);
        // Animateur (chief) — principal function is elevated → excluded.
        $this->seedMember('D3', '1995-01-01', 'F', [
            ['fn' => $this->chiefFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
        ]);
        // Intendant — excluded.
        $this->seedMember('D4', '1990-01-01', 'M', [
            ['fn' => $this->intendantFnId, 'branch' => $this->baladinsBranchId, 'main' => true],
        ]);
        // Inactive animé — excluded.
        $this->seedMember('D6', '2019-01-01', 'M', [
            ['fn' => $this->animeFnId, 'branch' => $this->baladinsBranchId, 'main' => true],
        ], active: false);
        // Principal function has no branch — excluded (cannot be placed).
        $this->seedMember('D7', '2018-01-01', 'F', [
            ['fn' => $this->animeFnId, 'branch' => null, 'main' => true],
        ]);

        $rows = $this->repo->getMemberBranchData($this->yearId);

        $this->assertCount(2, $rows);

        $byBranch = [];
        foreach ($rows as $r) {
            $byBranch[$r['branch_label']] = $r;
        }
        ksort($byBranch);

        $this->assertArrayHasKey('Baladins', $byBranch);
        $this->assertSame('2019-05-01', $byBranch['Baladins']['birth_date']);
        $this->assertSame('M', $byBranch['Baladins']['gender']);
        $this->assertSame(10, $byBranch['Baladins']['branch_sort_order']);

        $this->assertArrayHasKey('Louveteaux', $byBranch);
        $this->assertSame('2016-03-01', $byBranch['Louveteaux']['birth_date']);
        $this->assertSame('F', $byBranch['Louveteaux']['gender']);
    }

    public function testOnlyThePrincipalFunctionDecidesBranchAndStaffStatus(): void
    {
        // Pionnier animé (principal) who is ALSO an assistant chef (secondary):
        // counted as an animé in the principal branch. This is the case that was
        // wrongly excluded before — older animés often hold a leadership function.
        $this->seedMember('P1', '2009-01-01', 'M', [
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
            ['fn' => $this->chiefFnId, 'branch' => $this->baladinsBranchId, 'main' => false],
        ]);

        // The inverse: principal function is elevated, secondary is an animé role
        // → excluded (this member is staff).
        $this->seedMember('P2', '1998-01-01', 'F', [
            ['fn' => $this->chiefFnId, 'branch' => $this->baladinsBranchId, 'main' => true],
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => false],
        ]);

        $rows = $this->repo->getMemberBranchData($this->yearId);

        $this->assertCount(1, $rows);
        $this->assertSame('Louveteaux', $rows[0]['branch_label']);
        $this->assertSame('2009-01-01', $rows[0]['birth_date']);
    }

    public function testDuplicatedFunctionRowsCountTheMemberOnce(): void
    {
        // A member with two postal addresses has their function repeated by the
        // import; the member must still be counted exactly once.
        $this->seedMember('DUP', '2016-06-06', 'F', [
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
        ]);

        $rows = $this->repo->getMemberBranchData($this->yearId);

        $this->assertCount(1, $rows);
        $this->assertSame('Louveteaux', $rows[0]['branch_label']);
    }

    public function testMainFunctionDeterminesBranchForAnAnime(): void
    {
        // Two identified functions in different branches; the main one wins.
        $this->seedMember('D10', '2016-01-01', 'M', [
            ['fn' => $this->animeFnId, 'branch' => $this->louveteauxBranchId, 'main' => true],
            ['fn' => $this->animeFnId, 'branch' => $this->baladinsBranchId, 'main' => false],
        ]);

        $rows = $this->repo->getMemberBranchData($this->yearId);

        $this->assertCount(1, $rows);
        $this->assertSame('Louveteaux', $rows[0]['branch_label']);
    }
}
