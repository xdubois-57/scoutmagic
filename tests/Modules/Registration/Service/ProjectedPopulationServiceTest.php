<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Api\ProjectedPopulationProvider;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ProjectedMemberEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ProjectedPopulationService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * `Api\ProjectedPopulationProvider` — the projection as a public contract.
 *
 * The property that matters is that it is a **façade**: the same people,
 * the same sections, the same counts as `ForecastService` already
 * produces. Two modules disagreeing about how many children a unit expects
 * next year would be worse than either being wrong alone, so every test
 * here compares the interface against the service rather than against
 * hand-written numbers — a hard-coded expectation would pass while the two
 * drifted apart.
 *
 * The second thing pinned is the nullable-dependency rule
 * (ARCHITECTURE.md §7.5): a consumer built without the provider works. Not
 * asserted about a real consumer, since IT-10 deliberately ships none —
 * asserted about a stand-in that declares the dependency the way a real one
 * must, which is what would break if the contract ever stopped being
 * nullable-injectable.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ProjectedPopulationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ForecastService $forecastService;
    private ProjectedPopulationService $provider;
    private RegistrationRequestRepository $requestRepository;
    private SectionTransferRepository $transferRepository;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxSectionId;
    private int $eclaireursSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $louveteauxBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireursBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->louveteauxSectionId = $this->createSection('LOUV1', $louveteauxBranchId, 'Louveteaux A');
        $this->eclaireursSectionId = $this->createSection('ECLA1', $eclaireursBranchId, 'Éclaireurs A');

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $this->transferRepository = new SectionTransferRepository($this->pdo);
        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $passageService = new PassageService(
            $this->pdo, $this->encryption, $sectionService, $this->transferRepository,
            $this->requestRepository, $ageBracketRepository
        );
        $this->forecastService = new ForecastService($this->pdo, $this->encryption, $sectionService, $passageService);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $slotService = new SlotService(
            $this->pdo, $this->encryption, $settingService, $ageBracketRepository,
            new SlotCapacityRepository($this->pdo), $this->requestRepository
        );

        $this->provider = new ProjectedPopulationService(
            $this->forecastService,
            $slotService,
            new ScoutYearService($this->pdo),
            $sectionService,
            $this->requestRepository,
            new ProjectedMemberEmailRepository($this->pdo, $this->encryption)
        );
    }

    // ── the façade agrees with the service ────────────────────────────

    public function testTheProjectedHeadcountIsTheOneTheForecastCounts(): void
    {
        $this->populateAllFourApports();

        $this->assertSame(
            $this->forecast()['summary']['projected_total'],
            count($this->provider->projectedPopulation($this->targetYearId)),
            'One projection, not two: the interface returns exactly the rows the page counts.'
        );
    }

    public function testEverySectionTotalMatchesTheForecastPage(): void
    {
        $this->populateAllFourApports();

        $expected = [];
        foreach ($this->forecast()['sections'] as $section) {
            if ($section['total'] > 0) {
                $expected[$section['id']] = [
                    'total' => $section['total'],
                    'certain' => $section['certain_total'],
                    'hypothesis' => $section['hypothesis_total'],
                    'gender' => $section['gender'],
                ];
            }
        }

        $actual = [];
        foreach ($this->provider->projectedSectionTotals($this->targetYearId) as $totals) {
            $actual[$totals->sectionId] = [
                'total' => $totals->total,
                'certain' => $totals->certainTotal,
                'hypothesis' => $totals->hypothesisTotal,
                'gender' => $totals->gender,
            ];
        }

        $this->assertSame($expected, $actual);
    }

    public function testTheRankBreakdownMatchesTheForecastPagesYearSegments(): void
    {
        $this->populateAllFourApports();

        $expected = [];
        foreach ($this->forecast()['sections'] as $section) {
            if ($section['year_segments'] !== []) {
                $segments = $section['year_segments'];
                ksort($segments);
                $expected[$section['id']] = $segments;
            }
        }

        $actual = [];
        foreach ($this->provider->projectedSectionTotals($this->targetYearId) as $totals) {
            if ($totals->byYearInBranch !== []) {
                $actual[$totals->sectionId] = $totals->byYearInBranch;
            }
        }

        $this->assertSame($expected, $actual);
    }

    public function testEveryPersonCarriesExactlyOneIdentity(): void
    {
        $this->populateAllFourApports();

        foreach ($this->provider->projectedPopulation($this->targetYearId) as $person) {
            $this->assertTrue(
                ($person->memberId === null) !== ($person->registrationRequestId === null),
                'A projected person is a member OR an accepted request, never both and never neither.'
            );
        }
    }

    public function testUnassignedPeopleAreReturnedButCountedInNoSection(): void
    {
        // A branch change with no destination picked yet, and an accepted
        // request with no section prévue: both are real people the unit
        // expects, and neither belongs to a section.
        $sacha = $this->createMember($this->currentYearId, 'Sacha', '2015-06-01', $this->louveteauxSectionId);
        $this->createRequest('Emile', '2019-06-01', null);

        $population = $this->provider->projectedPopulation($this->targetYearId);
        $unassigned = array_values(array_filter(
            $population,
            static fn ($person): bool => $person->sectionId === null
        ));

        $this->assertCount(2, $unassigned);
        $this->assertSame($this->forecast()['unassigned']['total'], count($unassigned));

        $sectionTotal = 0;
        foreach ($this->provider->projectedSectionTotals($this->targetYearId) as $totals) {
            $sectionTotal += $totals->total;
        }
        $this->assertSame(
            count($population) - count($unassigned),
            $sectionTotal,
            'sum(sections) + unassigned === the whole projection, the same invariant ForecastService carries.'
        );
        // Silence the unused-variable warning while keeping the fixture
        // readable: the member exists to be projected, not to be asserted on.
        $this->assertNotSame(0, $sacha['member_id']);
    }

    public function testOnlyRealDeskRowsAreCertain(): void
    {
        $this->populateAllFourApports();

        foreach ($this->provider->projectedPopulation($this->targetYearId) as $person) {
            $this->assertSame(
                $person->origin === 'desk',
                $person->certain,
                'A chosen destination is still staff\'s plan, not a Desk fact.'
            );
        }
    }

    // ── the addresses ─────────────────────────────────────────────────

    public function testAMembersOwnAddressAndAFamilysContactAddressBothCameBack(): void
    {
        $member = $this->createMember($this->currentYearId, 'Continuant', '2017-06-01', $this->louveteauxSectionId);
        $this->setMemberEmail($member['member_year_id'], 'famille@example.be');
        $requestId = $this->createRequest('Emile', '2019-06-01', $this->louveteauxSectionId);

        $recipients = $this->provider->reachableRecipients($this->targetYearId);
        $byEmail = [];
        foreach ($recipients as $recipient) {
            $byEmail[$recipient->email] = $recipient;
        }

        $this->assertArrayHasKey('famille@example.be', $byEmail);
        $this->assertSame($member['member_id'], $byEmail['famille@example.be']->memberId);
        $this->assertNull($byEmail['famille@example.be']->registrationRequestId);

        $request = $this->requestRepository->findById($requestId);
        $this->assertNotNull($request);
        $this->assertArrayHasKey($request->email, $byEmail);
        $this->assertSame($requestId, $byEmail[$request->email]->registrationRequestId);
        $this->assertNull($byEmail[$request->email]->memberId);
    }

    public function testSomebodyWithNoUsableAddressIsAbsentRatherThanEmpty(): void
    {
        // No email set at all on this member's year row.
        $this->createMember($this->currentYearId, 'Continuant', '2017-06-01', $this->louveteauxSectionId);

        foreach ($this->provider->reachableRecipients($this->targetYearId) as $recipient) {
            $this->assertNotSame(
                '',
                $recipient->email,
                'An entry with an empty address is one silent send failure per caller.'
            );
        }
        $this->assertSame([], $this->provider->reachableRecipients($this->targetYearId));
    }

    // ── the contract's edges ──────────────────────────────────────────

    public function testAYearThatDoesNotExistProjectsNothingRatherThanFailing(): void
    {
        $this->assertSame([], $this->provider->projectedPopulation(9999));
        $this->assertSame([], $this->provider->projectedSectionTotals(9999));
        $this->assertSame([], $this->provider->reachableRecipients(9999));
    }

    public function testAYearWithNoPredecessorProjectsNothingRatherThanAgainstTheWrongRoster(): void
    {
        $orphanYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2040-2041', '2040-09-01', '2041-08-31');

        $this->assertSame([], $this->provider->projectedPopulation($orphanYearId));
    }

    public function testAConsumerBuiltWithoutTheProviderStillWorks(): void
    {
        // ARCHITECTURE.md §7.5: the dependency is nullable and the
        // composition root injects it only when this module is enabled.
        $withoutIt = new class (null) {
            public function __construct(private ?ProjectedPopulationProvider $projection)
            {
            }

            /** @return array<int, \Modules\Registration\Api\ProjectedSectionTotals> */
            public function nextYearPerSection(int $targetYearId): array
            {
                return $this->projection?->projectedSectionTotals($targetYearId) ?? [];
            }
        };

        $this->assertSame([], $withoutIt->nextYearPerSection($this->targetYearId));

        $withIt = new class ($this->provider) {
            public function __construct(private ?ProjectedPopulationProvider $projection)
            {
            }

            /** @return array<int, \Modules\Registration\Api\ProjectedSectionTotals> */
            public function nextYearPerSection(int $targetYearId): array
            {
                return $this->projection?->projectedSectionTotals($targetYearId) ?? [];
            }
        };

        $this->createMember($this->targetYearId, 'Certain', '2016-06-01', $this->louveteauxSectionId);
        $this->assertNotSame([], $withIt->nextYearPerSection($this->targetYearId));
    }

    // ── fixture ───────────────────────────────────────────────────────

    /**
     * @return array{summary: array<string, int>, sections: array<int, array<string, mixed>>, unassigned: array<string, int>, pyramid: array<int, array<string, int>>, pyramid_max: int}
     */
    private function forecast(): array
    {
        return $this->forecastService->getForecast(
            $this->currentYearId,
            '2026-2027',
            $this->targetYearId,
            '2027-2028',
            '12-31'
        );
    }

    /**
     * The same four apports ForecastServiceTest exercises: a real target-year
     * row, a continuing animé, a branch change with a destination, and an
     * accepted request with a section prévue.
     */
    private function populateAllFourApports(): void
    {
        $this->createMember($this->targetYearId, 'Certain', '2016-06-01', $this->louveteauxSectionId, gender: 'F');
        $this->createMember($this->currentYearId, 'Continuant', '2017-06-01', $this->louveteauxSectionId);
        $sacha = $this->createMember($this->currentYearId, 'Sacha', '2015-06-01', $this->louveteauxSectionId);
        $this->transferRepository->setDestination($sacha['member_id'], $this->targetYearId, $this->eclaireursSectionId);
        $this->createRequest('Emile', '2019-06-01', $this->louveteauxSectionId, 'F');
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
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
        string $gender = 'M',
        ?int $memberId = null
    ): array {
        if ($memberId === null) {
            $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
            $memberId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, leaving, scout_year_offset)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
            $this->encryption->encrypt($gender, 'member_years.gender'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn identified', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    private function setMemberEmail(int $memberYearId, string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_years SET email_encrypted = ? WHERE id = ?');
        $stmt->execute([$this->encryption->encrypt($email, 'member_years.email'), $memberYearId]);
    }

    private function createRequest(string $firstName, string $birthDate, ?int $intendedSectionId, string $gender = 'M'): int
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Nouveau', 'child_first_name' => $firstName,
            'gender' => $gender, 'birth_date' => $birthDate, 'street' => 'Avenue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Bruxelles',
            'email' => strtolower($firstName) . uniqid() . '@example.com',
            'phone1' => '0470000000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);
        if ($intendedSectionId !== null) {
            $this->requestRepository->updateIntendedSection($created['id'], $intendedSectionId);
        }

        return $created['id'];
    }
}
