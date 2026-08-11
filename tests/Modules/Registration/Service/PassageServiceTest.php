<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * "Passage" page's domain logic: only last-rank-of-branch animés change
 * branch, leaving/end-of-parcours members are excluded, household
 * grouping follows the address blind index (never a "fratrie"), and a
 * sibling declared at submission resolves to their OWN current section.
 *
 * @group database
 */
class PassageServiceTest extends TestCase
{
    private \PDO $pdo;
    private PassageService $service;
    private RegistrationRequestRepository $requestRepository;
    private EncryptionService $encryption;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxBranchId;
    private int $eclaireursBranchId;
    private int $pionniersBranchId;
    private int $louveteauxSectionId;
    private int $eclaireursSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $this->louveteauxBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->eclaireursBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->pionniersBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);

        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $ageBracketRepository->upsert($this->louveteauxBranchId, 8, 4);
        $ageBracketRepository->upsert($this->eclaireursBranchId, 12, 4);
        $ageBracketRepository->upsert($this->pionniersBranchId, 16, 2);

        $this->louveteauxSectionId = $this->createSection('LOUV1', $this->louveteauxBranchId, 'Louveteaux A');
        $this->eclaireursSectionId = $this->createSection('ECLA1', $this->eclaireursBranchId, 'Éclaireurs A');

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $transferRepository = new SectionTransferRepository($this->pdo);
        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $this->service = new PassageService(
            $this->pdo, $this->encryption, $sectionService, $transferRepository, $this->requestRepository, $ageBracketRepository
        );
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{member_id: int, member_year_id: int}
     */
    private function createMember(
        string $firstName,
        string $birthDate,
        int $sectionId,
        bool $leaving = false,
        string $functionRole = 'identified',
        ?string $street = null,
        ?string $postalCode = null
    ): array {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, leaving)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->currentYearId,
            $this->encryption->encrypt($firstName), $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($birthDate), $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', 'Fn {$functionRole}', '{$functionRole}')");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        if ($street !== null) {
            $blind = $this->encryption->blindIndex(\Core\Member\AddressNormalizer::normalize($street, '1', null, $postalCode ?? '1000'));
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, address_normalized_blind_index) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$memberYearId, 'home', $this->encryption->encrypt($street), $blind]);
        }

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    // Reference year for '2026-2027' (default 12-31 reference date) is 2026.
    // Louveteaux: entry_age 8, duration 4 -> ages 8-11, last rank = age 11 -> birth year 2015.
    // Éclaireurs: entry_age 12, duration 4 -> ages 12-15.
    // Pionniers: entry_age 16, duration 2 -> ages 16-17, last rank = age 17 -> birth year 2009.

    public function testLastRankLouveteauxIsABranchChangeCandidate(): void
    {
        $sacha = $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId);

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $names = $this->allNames($changes);
        $this->assertContains('Sacha Dupont', $names);
    }

    public function testMidBranchMemberIsNotACandidate(): void
    {
        // Age 9 -> Louveteaux year-in-branch 2 of 4, not last rank.
        $this->createMember('MidLouveteau', '2017-06-01', $this->louveteauxSectionId);

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $this->assertSame([], $this->allNames($changes));
    }

    public function testLeavingMemberIsExcludedEvenAtLastRank(): void
    {
        $this->createMember('Partant', '2015-06-01', $this->louveteauxSectionId, leaving: true);

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $this->assertSame([], $this->allNames($changes));
    }

    public function testEndOfParcoursPionnierIsExcluded(): void
    {
        $pionnierSectionId = $this->createSection('PION1', $this->pionniersBranchId, 'Pionniers A');
        $this->createMember('DernierePionnier', '2009-06-01', $pionnierSectionId);

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $this->assertSame([], $this->allNames($changes));
    }

    public function testDestinationOptionsAreLimitedToArrivalBranch(): void
    {
        $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId);

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $member = $this->findMember($changes, 'Sacha Dupont');
        $optionIds = array_column($member['destination_options'], 'id');
        $this->assertSame([$this->eclaireursSectionId], $optionIds);
    }

    public function testHouseholdGroupsBySharedAddressNotSiblingDeclaration(): void
    {
        $sacha = $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId, street: 'Rue de la Paix', postalCode: '1000');
        $this->createMember('Colocataire', '2017-06-01', $this->eclaireursSectionId, functionRole: 'identified', street: 'Rue de la Paix', postalCode: '1000');
        $this->createMember('AutreAdresse', '2017-06-01', $this->eclaireursSectionId, street: 'Avenue Louise', postalCode: '1050');

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $member = $this->findMember($changes, 'Sacha Dupont');
        $householdNames = array_column($member['household'], 'name');
        $this->assertContains('Colocataire Dupont', $householdNames);
        $this->assertNotContains('AutreAdresse Dupont', $householdNames);
    }

    public function testHouseholdGroupsAddressesWrittenDifferently(): void
    {
        // Same real address, two different spellings — must still resolve
        // to the same normalized blind index (module spec: "même adresse",
        // not a literal string match).
        $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId, street: 'Rue de la Paix', postalCode: '1000');
        $this->createMember('Frere', '2017-06-01', $this->eclaireursSectionId, street: 'rue de la paix', postalCode: '1000');

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $member = $this->findMember($changes, 'Sacha Dupont');
        $householdNames = array_column($member['household'], 'name');
        $this->assertContains('Frere Dupont', $householdNames);
    }

    public function testLeavingHouseholdMemberIsExcludedFromHousehold(): void
    {
        $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId, street: 'Rue de la Paix', postalCode: '1000');
        $this->createMember('PartantMemeAdresse', '2017-06-01', $this->eclaireursSectionId, leaving: true, street: 'Rue de la Paix', postalCode: '1000');

        $changes = $this->service->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);

        $member = $this->findMember($changes, 'Sacha Dupont');
        $householdNames = array_column($member['household'], 'name');
        $this->assertNotContains('PartantMemeAdresse Dupont', $householdNames);
    }

    public function testIntendedSectionIsTheSameFieldTheFicheReadsAndWrites(): void
    {
        // "Section prévue" set as if from the fiche (Controller\
        // RegistrationRequestController::saveIntendedSection() itself
        // just calls this same repository method) must be visible on the
        // Passage page's own read of that request — one column, two
        // surfaces, never a separate copy.
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'Petit', 'child_first_name' => 'Nouveau',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'nouveau2@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $this->requestRepository->updateIntendedSection($created['id'], $this->louveteauxSectionId);

        $rows = $this->service->getNewRegistrations($this->targetYearId, '2027-2028', '12-31', $this->currentYearId);

        $this->assertSame($this->louveteauxSectionId, $rows[0]['request']->intendedSectionId);
    }

    public function testSiblingInNewRegistrationResolvesToOwnCurrentSection(): void
    {
        $sacha = $this->createMember('Sacha', '2015-06-01', $this->louveteauxSectionId);

        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Petit', 'child_first_name' => 'Nouveau',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'nouveau@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => 'RAS',
        ], null, [$sacha['member_id']]);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $rows = $this->service->getNewRegistrations($this->targetYearId, '2027-2028', '12-31', $this->currentYearId);

        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['siblings']);
        $this->assertSame('Sacha Dupont', $rows[0]['siblings'][0]['name']);
        $this->assertSame('Louveteaux A', $rows[0]['siblings'][0]['section_label']);
    }

    public function testNewRegistrationsOnlyIncludeAcceptedNotEncoded(): void
    {
        $accepted = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'A', 'child_first_name' => 'Accepted',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'a@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($accepted['id'], 'accepted', null);

        $pending = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'B', 'child_first_name' => 'Pending',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'b@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $encoded = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'C', 'child_first_name' => 'Encoded',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'c@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK99');
        $this->requestRepository->updateStatus($encoded['id'], 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($encoded['id'], $memberId, new \DateTimeImmutable());

        $rows = $this->service->getNewRegistrations($this->targetYearId, '2027-2028', '12-31', $this->currentYearId);

        $names = array_map(fn($row) => $row['request']->childFirstName, $rows);
        $this->assertSame(['Accepted'], $names);
    }

    /**
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $changes
     * @return array<int, string>
     */
    private function allNames(array $changes): array
    {
        $names = [];
        foreach ($changes as $group) {
            foreach ($group['members'] as $member) {
                $names[] = $member['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $changes
     * @return array<string, mixed>
     */
    private function findMember(array $changes, string $name): array
    {
        foreach ($changes as $group) {
            foreach ($group['members'] as $member) {
                if ($member['name'] === $name) {
                    return $member;
                }
            }
        }

        $this->fail("Member {$name} not found");
    }
}
