<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageOptimizationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * IT-18 — « Optimiser la répartition ».
 *
 * The two blocks of the page are passed IN, exactly as the controller
 * hands them over, so a test can state a small situation whose right
 * answer is obvious and check that the algorithm finds it. What still
 * comes from the database is what the score reads about people — answers,
 * staff notes, sibling links, the projection — because those are the
 * couplings worth exercising for real.
 *
 * @group database
 */
class PassageOptimizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private PassageOptimizationService $service;
    private ReenrollmentRepository $reenrollmentRepository;
    private SectionTransferRepository $transferRepository;
    private RegistrationRequestRepository $requestRepository;
    private int $publicYearId;
    private int $targetYearId;
    private int $eclaireursBranchId;
    private int $sectionA;
    private int $sectionB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $scoutYears = new ScoutYearService($this->pdo);
        $this->publicYearId = $scoutYears->ensureYear('2026-2027');
        $this->targetYearId = $scoutYears->ensureYear('2027-2028');

        RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->eclaireursBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->sectionA = $this->createSection('ECLA1', $this->eclaireursBranchId, 'Éclaireurs A');
        $this->sectionB = $this->createSection('ECLA2', $this->eclaireursBranchId, 'Éclaireurs B');

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            PassageOptimizationService::SETTING_MAX_IMBALANCE,
            '30',
            'number',
            'Écart',
            'Test.',
            'registration'
        );
        $this->settingService->register(
            PassageOptimizationService::SETTING_KEEP_SIBLINGS,
            '1',
            'boolean',
            'Fratries',
            'Test.',
            'registration'
        );

        $this->reenrollmentRepository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->transferRepository = new SectionTransferRepository($this->pdo);
        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $this->service = new PassageOptimizationService(
            RegistrationTestHelper::passageService($this->pdo, $this->encryption),
            RegistrationTestHelper::projectedPopulation($this->pdo, $this->encryption, $this->settingService),
            $this->requestRepository,
            $this->reenrollmentRepository,
            new PassageNoteRepository($this->pdo, $this->encryption),
            $this->transferRepository,
            new MemberYearRepository($this->pdo),
            $this->settingService,
            $this->pdo
        );
    }

    // ── the optimum of a case small enough to check by eye ────────────

    /**
     * Four children, two sections, and two of them asking for Éclaireurs A.
     * The only distribution that both honours the two wishes and keeps the
     * sections even is two and two, with the askers in A.
     */
    public function testItFindsTheObviousOptimum(): void
    {
        $members = [];
        foreach (['Alix', 'Bo', 'Cléo', 'Dan'] as $name) {
            $members[$name] = $this->createMember($name);
        }
        $this->answer($members['Alix'], $this->sectionA);
        $this->answer($members['Bo'], $this->sectionA);

        $outcome = $this->plan($this->branchChanges(array_values($members)));

        $this->assertSame($this->sectionA, $outcome->memberDestinations[$members['Alix']]);
        $this->assertSame($this->sectionA, $outcome->memberDestinations[$members['Bo']]);
        $this->assertSame(
            [2, 2],
            $this->loadPerSection($outcome->memberDestinations),
            'two and two is the only even split of four'
        );
    }

    public function testEverybodyIsPlacedSomewhereEvenWithNoWishAtAll(): void
    {
        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo', 'Cléo']);

        $outcome = $this->plan($this->branchChanges($members));

        $this->assertCount(3, $outcome->memberDestinations);
        foreach ($outcome->memberDestinations as $sectionId) {
            $this->assertContains($sectionId, [$this->sectionA, $this->sectionB]);
        }
        $this->assertSame(3, $outcome->placedCount);
        $this->assertSame(0, $outcome->keptCount);
    }

    // ── a hand assignment survives ────────────────────────────────────

    public function testALineThatAlreadyCarriesASectionIsNeverTouched(): void
    {
        $alix = $this->createMember('Alix');
        $bo = $this->createMember('Bo');
        $cleo = $this->createMember('Cléo');

        // Alix is where a chief put them, and the family asked for the
        // other section — the case where the optimiser would move them if
        // it were allowed to.
        $this->answer($alix, $this->sectionB);

        $rows = $this->branchChanges([$alix, $bo, $cleo]);
        $rows['Éclaireurs']['members'][0]['destination_section_id'] = $this->sectionA;

        $outcome = $this->plan($rows);

        $this->assertArrayNotHasKey($alix, $outcome->memberDestinations);
        $this->assertSame(1, $outcome->keptCount);
        $this->assertSame(2, $outcome->placedCount);
    }

    // ── the two limits in conflict ────────────────────────────────────

    /**
     * Section A already holds ten older Éclaireurs who simply stay; B holds
     * none. Four first-years arrive.
     *
     * The two limits cannot both hold: an even 2/2 keeps the first-years
     * perfectly balanced and leaves the sections at 12 and 2, an 83 %
     * headcount spread. §14 says the first-year limit wins, the headcount
     * one may be exceeded, and the result announces it in so many words.
     */
    public function testTheFirstYearLimitWinsAndTheOvershootIsAnnounced(): void
    {
        $this->seedProjectedSection($this->sectionA, 10, birthDate: '2012-06-01');

        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo', 'Cléo', 'Dan']);
        $outcome = $this->plan($this->branchChanges($members));

        $this->assertSame(
            [2, 2],
            $this->loadPerSection($outcome->memberDestinations),
            'the first-years are spread evenly, which is the limit that wins'
        );

        $this->assertNotSame([], $outcome->warnings);
        $this->assertStringContainsString("Écart d'effectif", $outcome->warnings[0]);
        $this->assertStringContainsString('au-delà de la limite', $outcome->warnings[0]);
        $this->assertStringContainsString('premières années', $outcome->warnings[0]);
    }

    /**
     * One section in the branch and four arrivals: no distribution can
     * spread anything. The button still answers.
     */
    public function testWithNoFeasibleSolutionADistributionIsStillProduced(): void
    {
        $this->settingService->set(PassageOptimizationService::SETTING_MAX_IMBALANCE, '0', 'registration');
        $this->seedProjectedSection($this->sectionA, 8, birthDate: '2014-06-01');

        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo']);
        $outcome = $this->plan($this->branchChanges($members));

        $this->assertCount(2, $outcome->memberDestinations, 'a distribution comes back whatever the limits say');
        $this->assertNotSame([], $outcome->warnings, 'and it says so');
    }

    // ── determinism ───────────────────────────────────────────────────

    public function testThreeRunsOfTheSameInputGiveTheSameAnswer(): void
    {
        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo', 'Cléo', 'Dan', 'Eli']);
        $this->answer($members[0], $this->sectionB);
        $rows = $this->branchChanges($members);

        $first = $this->plan($rows)->memberDestinations;
        $second = $this->plan($rows)->memberDestinations;
        $third = $this->plan($rows)->memberDestinations;

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    // ── siblings ──────────────────────────────────────────────────────

    public function testTwoChildrenAtOneAddressLandInTheSameSection(): void
    {
        $alix = $this->createMember('Alix', address: 'rue-des-fleurs-12');
        $bo = $this->createMember('Bo', address: 'rue-des-fleurs-12');
        $cleo = $this->createMember('Cléo', address: 'avenue-du-parc-3');
        $dan = $this->createMember('Dan', address: 'avenue-du-parc-3');

        $outcome = $this->plan($this->branchChanges([$alix, $bo, $cleo, $dan]));

        $this->assertSame(
            $outcome->memberDestinations[$alix],
            $outcome->memberDestinations[$bo],
            'the same address is the link §14 names, used as the page already shows it'
        );
        $this->assertSame($outcome->memberDestinations[$cleo], $outcome->memberDestinations[$dan]);
    }

    /**
     * Four children, two sections, two each. Alix and Bo share an address;
     * Alix also named Cléo as a friend. Only one of the two ties can be
     * kept — and which one shows whether the setting is doing anything.
     *
     * Siblings rank ABOVE friend wishes (§14), so with the setting on Alix
     * goes with Bo. With it off there is no sibling tie left to outrank
     * anything, and the friend wish decides instead.
     */
    public function testTheSiblingCriterionDisappearsWhenTheSettingIsOff(): void
    {
        $alix = $this->createMember('Alix', address: 'rue-des-fleurs-12');
        $bo = $this->createMember('Bo', address: 'rue-des-fleurs-12');
        $cleo = $this->createMember('Cléo');
        $dan = $this->createMember('Dan');
        $this->answerWithWishes($alix, [
            ['raw_name' => 'Cléo', 'matched_member_id' => $cleo, 'match_state' => 'unique'],
        ]);
        $rows = $this->branchChanges([$alix, $bo, $cleo, $dan]);

        $withSiblings = $this->plan($rows)->memberDestinations;
        $this->assertSame($withSiblings[$alix], $withSiblings[$bo], 'the household tie outranks the friend wish');

        $this->settingService->set(PassageOptimizationService::SETTING_KEEP_SIBLINGS, '0', 'registration');
        $withoutSiblings = $this->plan($rows)->memberDestinations;

        $this->assertSame($withoutSiblings[$alix], $withoutSiblings[$cleo], 'with no household tie, the wish decides');
        $this->assertNotSame($withoutSiblings[$alix], $withoutSiblings[$bo]);
    }

    // ── friend wishes ─────────────────────────────────────────────────

    public function testOnlyAWishMatchedToOneMemberIsActedOn(): void
    {
        $alix = $this->createMember('Alix');
        $bo = $this->createMember('Bo');
        $cleo = $this->createMember('Cléo');
        $dan = $this->createMember('Dan');

        // Alix names Cléo, resolved to exactly one member — usable.
        $this->answerWithWishes($alix, [
            ['raw_name' => 'Cléo', 'matched_member_id' => $cleo, 'match_state' => 'unique'],
        ]);
        // Bo names somebody the server could not pin down — not usable,
        // and the optimiser must not guess.
        $this->answerWithWishes($bo, [
            ['raw_name' => 'Dan', 'matched_member_id' => null, 'match_state' => 'ambiguous'],
        ]);

        $outcome = $this->plan($this->branchChanges([$alix, $bo, $cleo, $dan]));

        $this->assertSame(
            $outcome->memberDestinations[$alix],
            $outcome->memberDestinations[$cleo],
            'a uniquely matched wish is kept'
        );
    }

    public function testAWishAChiefDisambiguatedCountsLikeAMatchedOne(): void
    {
        $alix = $this->createMember('Alix');
        $bo = $this->createMember('Bo');
        $cleo = $this->createMember('Cléo');
        $dan = $this->createMember('Dan');
        $this->answerWithWishes($alix, [
            ['raw_name' => 'Bo', 'matched_member_id' => $bo, 'match_state' => 'resolved'],
        ]);

        $outcome = $this->plan($this->branchChanges([$alix, $bo, $cleo, $dan]));

        $this->assertSame($outcome->memberDestinations[$alix], $outcome->memberDestinations[$bo]);
    }

    /**
     * The two methods differ exactly where §14 says they do: « Respecter
     * les souhaits » keeps the pair together even though that leaves one
     * section empty, and « Souhaits et équilibre » does not.
     */
    public function testRespectingWishesIgnoresTheBalanceAndBalancingDoesNot(): void
    {
        $alix = $this->createMember('Alix');
        $bo = $this->createMember('Bo');
        $this->answerWithWishes($alix, [
            ['raw_name' => 'Bo', 'matched_member_id' => $bo, 'match_state' => 'unique'],
        ]);
        $rows = $this->branchChanges([$alix, $bo]);

        $wishes = $this->service->plan(
            [],
            $rows,
            $this->publicYearId,
            $this->targetYearId,
            PassageOptimizationService::METHOD_WISHES
        )->memberDestinations;
        $this->assertSame($wishes[$alix], $wishes[$bo]);

        $balanced = $this->plan($rows)->memberDestinations;
        $this->assertNotSame($balanced[$alix], $balanced[$bo]);
    }

    // ── writing ───────────────────────────────────────────────────────

    public function testApplyingWritesEveryDestinationOrNone(): void
    {
        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo']);
        $outcome = $this->plan($this->branchChanges($members));

        $this->service->apply($outcome, $this->targetYearId);

        foreach ($outcome->memberDestinations as $memberId => $sectionId) {
            $this->assertSame(
                $sectionId,
                $this->transferRepository->findDestinationSectionId($memberId, $this->targetYearId)
            );
        }
    }

    public function testResetEmptiesEverythingThenPutsBackTheSectionsThatWereNeverAChoice(): void
    {
        $alix = $this->createMember('Alix');
        $bo = $this->createMember('Bo');
        $this->transferRepository->setDestination($alix, $this->targetYearId, $this->sectionA);
        $this->transferRepository->setDestination($bo, $this->targetYearId, $this->sectionB);

        // A branch with ONE section: the reset puts that one back, because
        // it was never a decision to lose.
        $onlyBranch = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);
        $onlySection = $this->createSection('PION1', $onlyBranch, 'Pionniers A');
        $eli = $this->createMember('Eli');

        $rows = $this->branchChanges([$alix, $bo]);
        $rows['Pionniers'] = ['section_label' => 'Louveteaux', 'members' => [[
            'member_id' => $eli,
            'name' => 'Eli',
            'branch_year_label' => '1',
            'household' => [],
            'destination_section_id' => null,
            'destination_options' => [$this->sectionRow($onlySection, $onlyBranch, 'Pionniers A')],
        ]]];

        $this->service->reset($rows, $this->targetYearId);

        $this->assertNull($this->transferRepository->findDestinationSectionId($alix, $this->targetYearId));
        $this->assertNull($this->transferRepository->findDestinationSectionId($bo, $this->targetYearId));
        $this->assertSame($onlySection, $this->transferRepository->findDestinationSectionId($eli, $this->targetYearId));
    }

    public function testResetAlsoClearsTheIntendedSectionOfAnAcceptedRequest(): void
    {
        $requestId = $this->createAcceptedRequest('Zoé', $this->sectionA);

        $this->service->reset([], $this->targetYearId);

        $this->assertNull($this->requestRepository->findById($requestId)?->intendedSectionId);
    }

    // ── the dialog's own numbers ──────────────────────────────────────

    public function testTheDialogCountsWhatIsSettledAndWhatIsNot(): void
    {
        $members = array_map(fn(string $n): int => $this->createMember($n), ['Alix', 'Bo', 'Cléo']);
        $rows = $this->branchChanges($members);
        $rows['Éclaireurs']['members'][0]['destination_section_id'] = $this->sectionA;

        $this->assertSame(['kept' => 1, 'to_place' => 2], $this->service->counts([], $rows));
    }

    // ── fixture ───────────────────────────────────────────────────────

    /**
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     */
    private function plan(array $branchChanges): \Modules\Registration\Service\PassageOptimizationOutcome
    {
        return $this->service->plan(
            [],
            $branchChanges,
            $this->publicYearId,
            $this->targetYearId,
            PassageOptimizationService::METHOD_BALANCED
        );
    }

    /**
     * The « changements de branche » block, shaped exactly as
     * PassageService::getBranchChanges() returns it.
     *
     * @param array<int, int> $memberIds
     * @return array<string, array{section_label: string, members: array<int, array<string, mixed>>}>
     */
    private function branchChanges(array $memberIds): array
    {
        $members = [];
        foreach ($memberIds as $memberId) {
            $members[] = [
                'member_id' => $memberId,
                'name' => 'Membre ' . $memberId,
                'branch_year_label' => '4',
                'household' => [],
                'destination_section_id' => null,
                'destination_options' => [
                    $this->sectionRow($this->sectionA, $this->eclaireursBranchId, 'Éclaireurs A'),
                    $this->sectionRow($this->sectionB, $this->eclaireursBranchId, 'Éclaireurs B'),
                ],
            ];
        }

        return ['Éclaireurs' => ['section_label' => 'Louveteaux A', 'members' => $members]];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionRow(int $id, int $branchId, string $name): array
    {
        return [
            'id' => $id,
            'desk_code' => 'S' . $id,
            'name' => $name,
            'email' => null,
            'age_branch_id' => $branchId,
            'branch_name' => 'Éclaireurs',
            'branch_sort_order' => 30,
            'is_visible' => true,
            'is_active' => true,
            'color' => null,
        ];
    }

    /**
     * @param array<int, int> $destinations
     * @return array<int, int> how many landed in each section, section id order
     */
    private function loadPerSection(array $destinations): array
    {
        $counts = [$this->sectionA => 0, $this->sectionB => 0];
        foreach ($destinations as $sectionId) {
            $counts[$sectionId] = ($counts[$sectionId] ?? 0) + 1;
        }

        return array_values($counts);
    }

    private function answer(int $memberId, int $preferredSectionId): void
    {
        $this->reenrollmentRepository->saveAnswer(
            $memberId,
            $this->targetYearId,
            'reenrolled',
            $preferredSectionId,
            null,
            null,
            []
        );
    }

    /**
     * @param array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}> $wishes
     */
    private function answerWithWishes(int $memberId, array $wishes): void
    {
        $this->reenrollmentRepository->saveAnswer(
            $memberId,
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            null,
            $wishes
        );
    }

    /**
     * People who simply stay in a section next year — the base load the
     * optimiser adds its arrivals to. Written as real member_years of the
     * TARGET year, which is what the projection reads.
     */
    private function seedProjectedSection(int $sectionId, int $count, string $birthDate): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createMember(
                'Assis' . $sectionId . '-' . $i,
                sectionId: $sectionId,
                scoutYearId: $this->targetYearId,
                birthDate: $birthDate
            );
        }
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(
        string $firstName,
        ?string $address = null,
        ?int $sectionId = null,
        ?int $scoutYearId = null,
        string $birthDate = '2014-06-01'
    ): int {
        $sectionId ??= $this->sectionA;
        $scoutYearId ??= $this->publicYearId;

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, leaving, scout_year_offset, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
            $this->encryption->encrypt('M', 'member_years.gender'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        if ($address !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_addresses (member_year_id, address_type, address_normalized_blind_index) VALUES (?, ?, ?)'
            );
            $stmt->execute([$memberYearId, 'main', $address]);
        }

        return $memberId;
    }

    private function createAcceptedRequest(string $firstName, int $intendedSectionId): int
    {
        ['id' => $id] = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Parent',
            'child_last_name' => 'Dupont',
            'child_first_name' => $firstName,
            'gender' => 'F',
            'birth_date' => '2016-06-01',
            'street' => 'Rue',
            'number' => '1',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'famille@example.be',
            'phone1' => '+32470000000',
            'phone2' => null,
            'remarks' => null,
        ], null, []);

        $this->requestRepository->updateStatus($id, 'accepted', null);
        $this->requestRepository->updateIntendedSection($id, $intendedSectionId);

        return $id;
    }
}
