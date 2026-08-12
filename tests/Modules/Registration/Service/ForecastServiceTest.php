<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\AddressNormalizer;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * "Prévisions" page's domain logic: the four apports (real Desk data for
 * the target year, continuing animés projected forward, branch changes,
 * accepted new registrations) combine into one headcount without double
 * counting, "non attribués" is tracked separately but still counted in the
 * unit total, and the section/unassigned totals always reconcile.
 *
 * @group database
 */
class ForecastServiceTest extends TestCase
{
    private \PDO $pdo;
    private ForecastService $service;
    private RegistrationRequestRepository $requestRepository;
    private SectionTransferRepository $transferRepository;
    private EncryptionService $encryption;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxBranchId;
    private int $eclaireursBranchId;
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

        $ageBracketRepository = new AgeBracketRepository($this->pdo);

        $this->louveteauxSectionId = $this->createSection('LOUV1', $this->louveteauxBranchId, 'Louveteaux A');
        $this->eclaireursSectionId = $this->createSection('ECLA1', $this->eclaireursBranchId, 'Éclaireurs A');

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->transferRepository = new SectionTransferRepository($this->pdo);
        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $passageService = new PassageService(
            $this->pdo, $this->encryption, $sectionService, $this->transferRepository, $this->requestRepository, $ageBracketRepository
        );

        $this->service = new ForecastService($this->pdo, $this->encryption, $sectionService, $passageService);
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
        int $scoutYearId,
        string $firstName,
        string $birthDate,
        int $sectionId,
        bool $leaving = false,
        string $gender = 'M',
        ?int $memberId = null,
        ?string $street = null,
        ?string $postalCode = null
    ): array {
        if ($memberId === null) {
            $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
            $memberId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, leaving)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $this->encryption->encrypt($firstName), $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($birthDate), $this->encryption->encrypt($gender), $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn identified', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();

        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        if ($street !== null) {
            $blind = $this->encryption->blindIndex(AddressNormalizer::normalize($street, '1', null, $postalCode ?? '1000'));
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, address_normalized_blind_index) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$memberYearId, 'home', $this->encryption->encrypt($street), $blind]);
        }

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    /**
     * @return array{id: int}
     */
    private function createRequest(string $firstName, string $birthDate, ?int $intendedSectionId, string $gender = 'M'): int
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Nouveau', 'child_first_name' => $firstName,
            'gender' => $gender, 'birth_date' => $birthDate, 'street' => 'Avenue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Bruxelles', 'email' => strtolower($firstName) . uniqid() . '@example.com',
            'phone1' => '0470000000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);
        if ($intendedSectionId !== null) {
            $this->requestRepository->updateIntendedSection($created['id'], $intendedSectionId);
        }

        return $created['id'];
    }

    // Reference year for '2026-2027' is 2026, for '2027-2028' is 2027.
    // Louveteaux: entry_age 8, duration 4 -> ages 8-11 (last rank: birth year 2015/2016 depending on reference year).

    public function testFourApportsCombineWithoutDoubleCounting(): void
    {
        // (a) Certain — already re-imported for the target year.
        $this->createMember($this->targetYearId, 'Certain', '2016-06-01', $this->louveteauxSectionId);

        // (b) Continuing — mid-branch this year, stays in Louveteaux A.
        $this->createMember($this->currentYearId, 'Continuant', '2017-06-01', $this->louveteauxSectionId);

        // (c) Branch change — last rank this year, destination chosen.
        $sacha = $this->createMember($this->currentYearId, 'Sacha', '2015-06-01', $this->louveteauxSectionId);
        $this->transferRepository->setDestination($sacha['member_id'], $this->targetYearId, $this->eclaireursSectionId);

        // (d) New registration — accepted, section prévue chosen.
        $this->createRequest('Emile', '2019-06-01', $this->louveteauxSectionId);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(4, $forecast['summary']['projected_total']);
        $this->assertSame(0, $forecast['unassigned']['total']);

        $bySectionId = [];
        foreach ($forecast['sections'] as $section) {
            $bySectionId[$section['id']] = $section;
        }
        $this->assertSame(3, $bySectionId[$this->louveteauxSectionId]['total']); // Certain, Continuant, Emile
        $this->assertSame(1, $bySectionId[$this->louveteauxSectionId]['certain_total']);
        $this->assertSame(2, $bySectionId[$this->louveteauxSectionId]['hypothesis_total']);
        $this->assertSame(1, $bySectionId[$this->eclaireursSectionId]['total']); // Sacha
    }

    /**
     * The module spec's own explicitly-flagged risk: a request encoded in
     * Desk and reimported exists both as a member and as a request — must
     * be counted exactly once (via the real member row), never twice.
     */
    public function testEncodedAndReimportedRequestCountedOnce(): void
    {
        $requestId = $this->createRequest('Encode', '2019-06-01', $this->louveteauxSectionId);
        $created = $this->requestRepository->findById($requestId);
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK_ENCODED');
        $this->requestRepository->linkToMemberAndEncode($requestId, $memberId, new \DateTimeImmutable());

        // The now-real member is re-imported into the target year, same
        // section, same person.
        $this->createMember($this->targetYearId, 'Encode', '2019-06-01', $this->louveteauxSectionId, memberId: $memberId);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(1, $forecast['summary']['projected_total']);
        $bySectionId = [];
        foreach ($forecast['sections'] as $section) {
            $bySectionId[$section['id']] = $section;
        }
        $this->assertSame(1, $bySectionId[$this->louveteauxSectionId]['total']);
        $this->assertSame(1, $bySectionId[$this->louveteauxSectionId]['certain_total']);
        $this->assertSame(0, $bySectionId[$this->louveteauxSectionId]['hypothesis_total']);
    }

    public function testLeavingMemberDoesNotAppearInProjection(): void
    {
        $this->createMember($this->currentYearId, 'Partant', '2017-06-01', $this->louveteauxSectionId, leaving: true);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(0, $forecast['summary']['projected_total']);
    }

    public function testUnassignedPassageAndRegistrationTrackedSeparatelyButCountedInTotal(): void
    {
        // Branch-change candidate, no destination chosen.
        $this->createMember($this->currentYearId, 'SansDestination', '2015-06-01', $this->louveteauxSectionId);
        // Accepted registration, no section prévue chosen.
        $this->createRequest('SansSection', '2019-06-01', null);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(2, $forecast['unassigned']['total']);
        $this->assertSame(1, $forecast['unassigned']['from_passage']);
        $this->assertSame(1, $forecast['unassigned']['from_registration']);
        $this->assertSame(2, $forecast['summary']['projected_total']);

        foreach ($forecast['sections'] as $section) {
            $this->assertSame(0, $section['total'], "Section {$section['label']} must not count unassigned members");
        }

        // Still in the unit-wide pyramid.
        $totalInPyramid = 0;
        foreach ($forecast['pyramid'] as $row) {
            $totalInPyramid += $row['male'] + $row['female'] + $row['other'];
        }
        $this->assertSame(2, $totalInPyramid);
    }

    public function testTotalEqualsSumOfSectionsPlusUnassigned(): void
    {
        $this->createMember($this->targetYearId, 'Certain', '2016-06-01', $this->louveteauxSectionId);
        $this->createMember($this->currentYearId, 'SansDestination', '2015-06-01', $this->louveteauxSectionId);
        $this->createRequest('SansSection', '2019-06-01', null);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $sectionsSum = array_sum(array_column($forecast['sections'], 'total'));
        $this->assertSame($forecast['summary']['projected_total'], $sectionsSum + $forecast['unassigned']['total']);
    }

    public function testDeparturesCountedInSummary(): void
    {
        $this->createMember($this->currentYearId, 'Partant1', '2017-06-01', $this->louveteauxSectionId, leaving: true);
        $this->createMember($this->currentYearId, 'Partant2', '2017-06-01', $this->louveteauxSectionId, leaving: true);
        $this->createMember($this->currentYearId, 'Reste', '2017-06-01', $this->louveteauxSectionId);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(2, $forecast['summary']['departures_count']);
    }

    /**
     * countDeparturesForYear() must never count a leaving staff/intendant
     * member as an animé departure — chief/admin were already excluded,
     * but Intendant was missing from that filter.
     */
    public function testDeparturesCountExcludesLeavingIntendant(): void
    {
        $this->createMember($this->currentYearId, 'Partant', '2017-06-01', $this->louveteauxSectionId, leaving: true);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $intendantMemberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, leaving)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $intendantMemberId, $this->currentYearId,
            $this->encryption->encrypt('Intendant'), $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt('1990-01-01'), $this->encryption->encrypt('M'),
        ]);
        $intendantMemberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('intendant', 'Intendant', 'intendant')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'intendant'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->louveteauxSectionId)->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$intendantMemberYearId, $functionId, $this->louveteauxSectionId, $branchId]);

        $this->assertSame(1, $this->service->countDeparturesForYear($this->currentYearId));
    }

    public function testNewRegistrationsCountedInSummaryRegardlessOfPlacement(): void
    {
        $this->createRequest('Placee', '2019-06-01', $this->louveteauxSectionId);
        $this->createRequest('NonPlacee', '2019-06-01', null);

        $forecast = $this->service->getForecast($this->currentYearId, '2026-2027', $this->targetYearId, '2027-2028', '12-31');

        $this->assertSame(2, $forecast['summary']['new_registrations_count']);
    }

    /**
     * The module spec requires this page to work with `statistics` (module
     * id member_stats) disabled — verified here structurally: ForecastService
     * has no constructor dependency on anything from that namespace, so a
     * disabled/absent module can never break it.
     */
    /**
     * groupCapacityByBranch() reshapes Service\SlotService::
     * capacityBreakdownForYear()'s flat row list — never recomputes
     * capacity/remaining itself — into per-branch groups in canonical
     * order, each row keeping its own (possibly negative, over-capacity)
     * remaining count and tier untouched.
     */
    public function testGroupCapacityByBranchGroupsRowsByBranchInOrder(): void
    {
        $rows = [
            ['age_branch_id' => $this->louveteauxBranchId, 'branch_label' => 'Louveteaux', 'branch_sort_order' => 20, 'year_in_branch' => 1, 'capacity' => 10, 'projected' => 3, 'accepted' => 2, 'remaining' => 5, 'tier' => 'available'],
            ['age_branch_id' => $this->louveteauxBranchId, 'branch_label' => 'Louveteaux', 'branch_sort_order' => 20, 'year_in_branch' => 2, 'capacity' => 10, 'projected' => 8, 'accepted' => 1, 'remaining' => 1, 'tier' => 'heavy'],
            ['age_branch_id' => $this->eclaireursBranchId, 'branch_label' => 'Éclaireurs', 'branch_sort_order' => 30, 'year_in_branch' => 1, 'capacity' => 8, 'projected' => 8, 'accepted' => 2, 'remaining' => -2, 'tier' => 'heavy'],
        ];

        $grouped = $this->service->groupCapacityByBranch($rows);

        $this->assertCount(2, $grouped);
        $this->assertSame('Louveteaux', $grouped[0]['label']);
        $this->assertCount(2, $grouped[0]['rows']);
        $this->assertSame(['year_in_branch' => 1, 'capacity' => 10, 'remaining' => 5, 'tier' => 'available'], $grouped[0]['rows'][0]);
        $this->assertSame(['year_in_branch' => 2, 'capacity' => 10, 'remaining' => 1, 'tier' => 'heavy'], $grouped[0]['rows'][1]);

        $this->assertSame('Éclaireurs', $grouped[1]['label']);
        // Over capacity — a real, negative remaining is never clamped away.
        $this->assertSame(-2, $grouped[1]['rows'][0]['remaining']);
    }

    public function testGroupCapacityByBranchResolvesEachBranchsRepresentativeSectionColor(): void
    {
        $rows = [
            ['age_branch_id' => $this->louveteauxBranchId, 'branch_label' => 'Louveteaux', 'branch_sort_order' => 20, 'year_in_branch' => 1, 'capacity' => 10, 'projected' => 3, 'accepted' => 2, 'remaining' => 5, 'tier' => 'available'],
        ];

        $grouped = $this->service->groupCapacityByBranch($rows);

        $this->assertSame(\Core\Member\MemberYearService::colorForBranchSortOrder(20), $grouped[0]['color']);
    }

    public function testGroupCapacityByBranchReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->service->groupCapacityByBranch([]));
    }

    public function testHasNoDependencyOnMemberStatsModule(): void
    {
        $reflection = new \ReflectionClass(ForecastService::class);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertStringNotContainsStringIgnoringCase('MemberStats', $typeName);
        }
    }
}
