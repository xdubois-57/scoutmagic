<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\PassageRosterRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The four roster reads issue #646 moved out of `Service\ForecastService`
 * and `Service\SlotService`. Each is pinned on the rows that tell its
 * filters apart: a leaver, a staff member, an unsectioned member, an
 * inactive row, a member holding two functions, and a row of another year.
 */
#[Group('database')]
class PassageRosterRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PassageRosterRepository $roster;
    private int $yearId;
    private int $otherYearId;
    private int $sectionId;
    private int $animeFunctionId;
    private int $chiefFunctionId;
    private int $intendantFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->roster = new PassageRosterRepository($this->pdo, $this->encryption);

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
        $stmt->execute(['LOUV1', $branchId, 'Louveteaux A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->animeFunctionId = $this->insertFunction('ANIME', 'identified');
        $this->chiefFunctionId = $this->insertFunction('CHEF', 'chief');
        $this->intendantFunctionId = $this->insertFunction('INTENDANT', 'intendant');
    }

    public function testCountAnimesCountsLeaversOnceEachButNeverStaffUnsectionedOrInactiveRows(): void
    {
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true], [$this->animeFunctionId, true]]);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], leaving: true);
        $this->insertMemberYear($this->yearId, [[$this->chiefFunctionId, true]]);
        $this->insertMemberYear($this->yearId, [[$this->intendantFunctionId, true]]);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, false]]);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], active: false);
        $this->insertMemberYear($this->otherYearId, [[$this->animeFunctionId, true]]);

        $this->assertSame(2, $this->roster->countAnimes($this->yearId));
    }

    public function testCountLeavingAnimesCountsOnlyAnimesMarkedLeaving(): void
    {
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]]);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true], [$this->animeFunctionId, true]], leaving: true);
        $this->insertMemberYear($this->yearId, [[$this->chiefFunctionId, true]], leaving: true);
        $this->insertMemberYear($this->yearId, [[$this->intendantFunctionId, true]], leaving: true);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], leaving: true, active: false);

        $this->assertSame(1, $this->roster->countLeavingAnimes($this->yearId));
        $this->assertSame(0, $this->roster->countLeavingAnimes($this->otherYearId));
    }

    public function testFindAnimeAgesDecryptsOneEntryPerStayingAnime(): void
    {
        $twoFunctions = $this->insertMemberYear(
            $this->yearId,
            [[$this->animeFunctionId, true], [$this->animeFunctionId, true]],
            birthDate: '2017-03-04',
            offset: 1
        );
        $noBirthDate = $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], birthDate: null);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], leaving: true);
        $this->insertMemberYear($this->yearId, [[$this->chiefFunctionId, true]]);
        $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, false]]);
        $this->insertMemberYear($this->otherYearId, [[$this->animeFunctionId, true]]);

        $ages = $this->roster->findAnimeAges($this->yearId);
        ksort($ages);

        $this->assertSame([
            $twoFunctions['member_year_id'] => ['birth_date' => '2017-03-04', 'scout_year_offset' => 1],
            $noBirthDate['member_year_id'] => ['birth_date' => null, 'scout_year_offset' => 0],
        ], $ages);
    }

    public function testFindGendersAndAgesAnswersEachMemberFromTheRequestedYearWhateverTheirRow(): void
    {
        $anime = $this->insertMemberYear($this->yearId, [[$this->animeFunctionId, true]], birthDate: '2016-05-06', gender: 'F', offset: -1);
        $inactiveStaff = $this->insertMemberYear($this->yearId, [[$this->chiefFunctionId, true]], active: false, birthDate: null, gender: null);
        $otherYearOnly = $this->insertMemberYear($this->otherYearId, [[$this->animeFunctionId, true]]);

        $found = $this->roster->findGendersAndAges(
            [$anime['member_id'], $inactiveStaff['member_id'], $otherYearOnly['member_id'], $anime['member_id']],
            $this->yearId
        );
        ksort($found);

        $this->assertSame([
            $anime['member_id'] => ['gender' => 'F', 'birth_date' => '2016-05-06', 'scout_year_offset' => -1],
            $inactiveStaff['member_id'] => ['gender' => null, 'birth_date' => null, 'scout_year_offset' => 0],
        ], $found);
    }

    public function testFindGendersAndAgesOfNobodyIsEmpty(): void
    {
        $this->assertSame([], $this->roster->findGendersAndAges([], $this->yearId));
    }

    private function insertFunction(string $deskCode, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, array{0: int, 1: bool}> $functions function id, and whether it sits in the section
     * @return array{member_id: int, member_year_id: int}
     */
    private function insertMemberYear(
        int $scoutYearId,
        array $functions,
        bool $leaving = false,
        bool $active = true,
        ?string $birthDate = '2016-01-01',
        ?string $gender = 'M',
        int $offset = 0
    ): array {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-' . bin2hex(random_bytes(4)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                birth_date_encrypted, gender_encrypted, scout_year_offset, is_active, leaving)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $birthDate !== null ? $this->encryption->encrypt($birthDate, 'member_years.birth_date') : null,
            $gender !== null ? $this->encryption->encrypt($gender, 'member_years.gender') : null,
            $offset,
            $active ? 1 : 0,
            $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $link = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        foreach ($functions as $index => [$functionId, $inSection]) {
            $link->execute([$memberYearId, $functionId, $inSection ? $this->sectionId : null, $index === 0 ? 1 : 0]);
        }

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }
}
