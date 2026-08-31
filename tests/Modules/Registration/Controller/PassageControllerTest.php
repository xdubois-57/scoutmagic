<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\PassageController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Full-stack tests for the Passage controller: the public-year+1 anchor
 * survives a staff year and a session preview, "section prévue" writes
 * through to the same field the fiche uses, and a destination outside the
 * arrival branch is refused server-side.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PassageControllerTest extends TestCase
{
    private \PDO $pdo;
    private PassageController $controller;
    private RegistrationRequestRepository $requestRepository;
    private SectionTransferRepository $transferRepository;
    private PassageService $passageService;
    private \Modules\Registration\Service\ForecastService $forecastService;
    private \Modules\Registration\Service\ProjectedPopulationService $projection;
    private \Modules\Registration\Repository\ReenrollmentRepository $reenrollmentRepository;
    private \Modules\Registration\Repository\PassageNoteRepository $passageNoteRepository;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxSectionId;
    private int $eclaireursSectionId;
    private int $pionniersSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $farYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2028-2029', '2028-09-01', '2029-08-31');

        $louveteauxBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireursBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $pionniersBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);

        $this->louveteauxSectionId = $this->createSection('LOUV1', $louveteauxBranchId, 'Louveteaux A');
        $this->eclaireursSectionId = $this->createSection('ECLA1', $eclaireursBranchId, 'Éclaireurs A');
        $this->pionniersSectionId = $this->createSection('PION1', $pionniersBranchId, 'Pionniers A');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'D', 'd', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'L', 'l', 'registration');
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);
        // A staff year and a session preview both point at the FAR year —
        // resolveYears() must ignore both and still anchor on public + 1.
        $settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $farYearId);
        ScoutYearSession::setPreview($farYearId);

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotService = new SlotService($this->pdo, $encryption, $settingService, $ageBracketRepository, $slotCapacityRepository, $this->requestRepository);
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $this->transferRepository = new SectionTransferRepository($this->pdo);

        $passageService = new PassageService(
            $this->pdo, $encryption, $sectionService, $this->transferRepository, $this->requestRepository, $ageBracketRepository
        );
        $this->passageService = $passageService;

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/passage');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        // The real projection, not a stub: the statistics box the save
        // response carries back is the projection's own numbers, and a
        // stub here would let the two stop agreeing.
        $this->forecastService = new \Modules\Registration\Service\ForecastService(
            $this->pdo, $encryption, $sectionService, $passageService
        );
        $this->projection = new \Modules\Registration\Service\ProjectedPopulationService(
            $this->forecastService,
            $slotService,
            $scoutYearService,
            $sectionService,
            $this->requestRepository,
            new \Modules\Registration\Repository\ProjectedMemberEmailRepository($this->pdo, $encryption)
        );

        // IT-17 — the planning block under each line, wired real for the
        // same reason as the projection above it.
        $this->reenrollmentRepository = new \Modules\Registration\Repository\ReenrollmentRepository($this->pdo, $encryption);
        $this->passageNoteRepository = new \Modules\Registration\Repository\PassageNoteRepository($this->pdo, $encryption);
        $reenrollmentService = \Tests\Modules\Registration\RegistrationTestHelper::reenrollmentService(
            $this->pdo,
            $encryption,
            $settingService
        );

        $this->controller = new PassageController(
            $twig, $passageService, $this->requestRepository, $this->transferRepository, $sectionService,
            $ageBracketRepository, $slotService, $scoutYearResolver, $scoutYearService,
            new \Modules\Registration\Service\PassageStatisticsService($sectionService, $this->projection),
            \Tests\Modules\Registration\RegistrationTestHelper::passagePlanning(
                $this->pdo,
                $encryption,
                $settingService,
                $reenrollmentService
            ),
            $this->passageNoteRepository,
            $this->reenrollmentRepository,
            // No llm_connector in this fixture: the AI block is optional,
            // and its absence is one of the things this class asserts.
            null,
            \Tests\Modules\Registration\RegistrationTestHelper::passageOptimization(
                $this->pdo,
                $encryption,
                $settingService
            )
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
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
    private function createLouveteauLastRank(): array
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->currentYearId, $encryption->encrypt('Sacha', 'member_years.first_name'), $encryption->encrypt('Dupont', 'member_years.last_name'), $encryption->encrypt('2015-06-01', 'member_years.birth_date')]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->louveteauxSectionId, (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->louveteauxSectionId)->fetchColumn()]);

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    /**
     * Both save actions read a JSON body and answer in JSON (the page saves
     * in place, without reloading) — same contract, and same mocking, as
     * Controller\DeparturesControllerTest's own helper.
     */
    private function jsonBodyRequest(string $method, string $path, array $body): Request
    {
        return $this->rawJsonRequest($method, $path, array_merge($body, ['_csrf_token' => CsrfGuard::generateToken()]));
    }

    private function rawJsonRequest(string $method, string $path, array $body): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$method, $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($body));

        return $request;
    }

    public function testTargetYearIsPublicYearPlusOneDespiteStaffYearAndPreview(): void
    {
        $response = $this->controller->index(new Request('GET', '/passage', [], [], [], []), []);

        $this->assertStringContainsString('2027-2028', $response->getBody());
    }

    /**
     * Displaying the page must not write anything: assigning the obvious
     * single-option destinations moved to Task\AutoAssignPassageHandler, so
     * a GET (a link prefetch, a crawler, a plain refresh) no longer touches
     * the database. The picker still offers that single option meanwhile,
     * so nothing is blocked on the task having run.
     */
    public function testIndexDoesNotWriteDestinations(): void
    {
        $member = $this->createLouveteauLastRank();

        $this->controller->index(new Request('GET', '/passage', [], [], [], []), []);

        $this->assertNull($this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId));
    }

    /**
     * setUp only ever creates one Éclaireurs section — a last-rank Louveteau
     * therefore has exactly one possible destination, and there is no
     * decision for staff to make (module spec).
     */
    public function testAutoAssignPersistsTheOnlyPossibleDestination(): void
    {
        $member = $this->createLouveteauLastRank();

        $branchChanges = $this->passageService->getBranchChanges($this->currentYearId, '2026-2027', $this->targetYearId);
        $this->assertSame(1, $this->passageService->countSingleOptionDestinationsToAssign($branchChanges));

        $this->passageService->autoAssignSingleOptionDestinations($branchChanges, $this->targetYearId);

        $destinations = $this->transferRepository->findDestinationsForMembers([$member['member_id']], $this->targetYearId);
        $this->assertSame($this->eclaireursSectionId, $destinations[$member['member_id']] ?? null);
    }

    public function testSaveIntendedSectionWritesTheSameFieldAsTheFiche(): void
    {
        $member = $this->createLouveteauLastRank();
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'Nouveau', 'child_first_name' => 'Enfant',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'enfant@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $response = $this->controller->saveIntendedSection(
            $this->jsonBodyRequest('POST', "/passage/inscription/{$created['id']}/section", ['intended_section_id' => (string) $this->louveteauxSectionId]),
            ['id' => (string) $created['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->louveteauxSectionId, $this->requestRepository->findById($created['id'])->intendedSectionId);
    }

    public function testSaveDestinationAcceptsArrivalBranchSection(): void
    {
        $member = $this->createLouveteauLastRank();

        $this->controller->saveDestination(
            $this->jsonBodyRequest('POST', "/passage/membre/{$member['member_id']}/destination", ['destination_section_id' => (string) $this->eclaireursSectionId]),
            ['id' => (string) $member['member_id']]
        );

        $this->assertSame(
            $this->eclaireursSectionId,
            $this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId)
        );
    }

    public function testSaveDestinationRejectsSectionOutsideArrivalBranch(): void
    {
        $member = $this->createLouveteauLastRank();

        $this->controller->saveDestination(
            // Pionniers, not Éclaireurs — Sacha's real arrival branch.
            $this->jsonBodyRequest('POST', "/passage/membre/{$member['member_id']}/destination", ['destination_section_id' => (string) $this->pionniersSectionId]),
            ['id' => (string) $member['member_id']]
        );

        $this->assertNull($this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId));
    }

    public function testSaveDestinationRejectsInvalidCsrf(): void
    {
        $member = $this->createLouveteauLastRank();

        $request = $this->rawJsonRequest('POST', "/passage/membre/{$member['member_id']}/destination", [
            'destination_section_id' => $this->eclaireursSectionId, '_csrf_token' => 'bad',
        ]);
        $this->controller->saveDestination($request, ['id' => (string) $member['member_id']]);

        $this->assertNull($this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId));
    }

    /**
     * "— Non défini —" (value 0) must take a destination back off, not be
     * refused as an invalid selection — otherwise a pick, including one
     * autoAssignSingleOptionDestinations() made on its own, could never be
     * undone.
     */
    public function testSaveDestinationClearsOnZero(): void
    {
        $member = $this->createLouveteauLastRank();
        $this->transferRepository->setDestination($member['member_id'], $this->targetYearId, $this->eclaireursSectionId);

        $response = $this->controller->saveDestination(
            $this->jsonBodyRequest('POST', "/passage/membre/{$member['member_id']}/destination", ['destination_section_id' => 0]),
            ['id' => (string) $member['member_id']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId));
    }

    public function testSaveIntendedSectionClearsOnZero(): void
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'Nouveau', 'child_first_name' => 'Enfant',
            'gender' => 'M', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'enfant@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);
        $this->requestRepository->updateIntendedSection($created['id'], $this->louveteauxSectionId);

        $this->controller->saveIntendedSection(
            $this->jsonBodyRequest('POST', "/passage/inscription/{$created['id']}/section", ['intended_section_id' => 0]),
            ['id' => (string) $created['id']]
        );

        $this->assertNull($this->requestRepository->findById($created['id'])->intendedSectionId);
    }

    /**
     * Regression guard for the bug that made this whole page read-only: the
     * selects used to carry onchange="this.form.submit()", which the site's
     * CSP (script-src without 'unsafe-inline' — a nonce never covers an on*
     * attribute) blocks outright, and the forms had no submit button. Any
     * inline event handler reintroduced here would break saving again
     * without failing a single controller test.
     */
    // ── IT-12: the statistics box ─────────────────────────────────────

    public function testTheSaveResponseCarriesTheRecomputedStatistics(): void
    {
        $member = $this->createLouveteauLastRank();

        $response = $this->controller->saveDestination(
            $this->jsonBodyRequest('POST', "/passage/membre/{$member['member_id']}/destination", ['destination_section_id' => (string) $this->eclaireursSectionId]),
            ['id' => (string) $member['member_id']]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertArrayHasKey(
            'statistics_html',
            $decoded,
            'The box travels in the save\'s own answer — one round trip, and no cached box to invalidate.'
        );
        $this->assertStringContainsString('id="passage-statistics"', $decoded['statistics_html']);
        // Recomputed, not the page-load copy: the member has just arrived
        // in Éclaireurs A, and the box says so.
        $this->assertStringContainsString('Éclaireurs A', $decoded['statistics_html']);
    }

    public function testClearingADestinationAlsoRefreshesTheBox(): void
    {
        $member = $this->createLouveteauLastRank();
        $this->transferRepository->setDestination($member['member_id'], $this->targetYearId, $this->eclaireursSectionId);

        $response = $this->controller->saveDestination(
            $this->jsonBodyRequest('POST', "/passage/membre/{$member['member_id']}/destination", ['destination_section_id' => '0']),
            ['id' => (string) $member['member_id']]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('statistics_html', $decoded);
        $this->assertStringNotContainsString(
            'Éclaireurs A',
            $decoded['statistics_html'],
            'Taking a destination back must empty the section it was in, not leave a stale figure.'
        );
    }

    public function testSavingAnIntendedSectionRefreshesTheBoxToo(): void
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'Nouveau', 'child_first_name' => 'Enfant',
            'gender' => 'F', 'birth_date' => '2019-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'enfant@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $response = $this->controller->saveIntendedSection(
            $this->jsonBodyRequest('POST', "/passage/inscription/{$created['id']}/section", ['intended_section_id' => (string) $this->louveteauxSectionId]),
            ['id' => (string) $created['id']]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('statistics_html', $decoded);
        $this->assertStringContainsString('Louveteaux', $decoded['statistics_html']);
    }

    public function testTheBoxOnThePageCarriesBothScopesAndTheArrivalsWarning(): void
    {
        // With nobody placed anywhere the box has no branch to draw and
        // says so instead; a destination is what gives it something to
        // show, which is the state this test is about.
        $member = $this->createLouveteauLastRank();
        $this->transferRepository->setDestination($member['member_id'], $this->targetYearId, $this->eclaireursSectionId);

        $body = $this->controller->index(new Request('GET', '/passage', [], [], [], []), [])->getBody();

        // Both scopes are rendered at once and the switch only shows one:
        // flipping it must not cost a request, and half a stale box would
        // be worse than either half.
        $this->assertStringContainsString('data-scope="projected"', $body);
        $this->assertStringContainsString('data-scope="arrivals"', $body);
        $this->assertStringContainsString('Les animés qui restent dans leur section ne sont pas comptés ici', $body);
    }

    // ── IT-17: the family's answer, and what the staff make of it ─────

    /**
     * The chief's own reading of the wish is a value of the staff's, in a
     * table of the staff's. Recording one for a family who never answered
     * must not create an answer for them — every reader of
     * registration_reenrollments takes a row there as « this family has
     * answered ».
     */
    public function testTheStaffWishIsRecordedWithoutFabricatingAFamilyAnswer(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();

        $response = $this->controller->savePreferredSection(
            $this->jsonBodyRequest('POST', '/passage/membre/' . $member['member_id'] . '/souhait', [
                'preferred_section_id' => $this->eclaireursSectionId,
            ]),
            ['id' => (string) $member['member_id']]
        );

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(
            $this->eclaireursSectionId,
            $this->passageNoteRepository->find($member['member_id'], $this->targetYearId())['preferred_section_id']
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM registration_reenrollments')->fetchColumn(),
            'the staff wrote about a family; they did not answer for them'
        );
    }

    public function testAStaffWishOutsideTheArrivalBranchIsRefused(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();

        $response = $this->controller->savePreferredSection(
            $this->jsonBodyRequest('POST', '/passage/membre/' . $member['member_id'] . '/souhait', [
                'preferred_section_id' => $this->louveteauxSectionId,
            ]),
            ['id' => (string) $member['member_id']]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($this->passageNoteRepository->find($member['member_id'], $this->targetYearId()));
    }

    /**
     * The two staff fields save independently, exactly as the departures
     * grid's checkbox and comment do — one save must never clobber the
     * other's field.
     */
    public function testTheStaffNoteAndTheStaffWishNeverClobberEachOther(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();

        $this->controller->savePreferredSection(
            $this->jsonBodyRequest('POST', '/passage/membre/' . $member['member_id'] . '/souhait', [
                'preferred_section_id' => $this->eclaireursSectionId,
            ]),
            ['id' => (string) $member['member_id']]
        );
        $this->controller->saveStaffNote(
            $this->jsonBodyRequest('POST', '/passage/membre/' . $member['member_id'] . '/note', [
                'note' => 'À placer avec son frère.',
            ]),
            ['id' => (string) $member['member_id']]
        );

        $stored = $this->passageNoteRepository->find($member['member_id'], $this->targetYearId());
        $this->assertSame($this->eclaireursSectionId, $stored['preferred_section_id']);
        $this->assertSame('À placer avec son frère.', $stored['staff_note']);
    }

    public function testTheStaffNoteIsNotReadableInTheDatabase(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();

        $this->controller->saveStaffNote(
            $this->jsonBodyRequest('POST', '/passage/membre/' . $member['member_id'] . '/note', [
                'note' => 'Situation familiale délicate.',
            ]),
            ['id' => (string) $member['member_id']]
        );

        $raw = (string) $this->pdo->query('SELECT staff_note_encrypted FROM registration_passage_notes')->fetchColumn();
        $this->assertStringNotContainsString('délicate', $raw);
    }

    public function testAForgedMemberOnAWishResolutionIsRefused(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();

        $repository = new \Modules\Registration\Repository\ReenrollmentRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $repository->saveAnswer($member['member_id'], $this->targetYearId(), 'reenrolled', null, null, null, [
            ['raw_name' => 'Léo', 'matched_member_id' => null, 'match_state' => 'ambiguous'],
        ]);
        $wishId = (int) $this->pdo->query('SELECT id FROM registration_friend_wishes')->fetchColumn();

        // 999 is nobody, and certainly nobody the name « Léo » resolves to.
        $response = $this->controller->resolveWish(
            $this->jsonBodyRequest('POST', '/passage/souhait/' . $wishId . '/rattacher', ['matched_member_id' => 999]),
            ['id' => (string) $wishId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($repository->findWish($wishId)?->matchedMemberId);
    }

    public function testWithoutTheAiConnectorThePageOffersNoReReading(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');

        $body = $this->controller->index(new Request('GET', '/passage', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('passage-ai-review', $body);
        $this->assertStringNotContainsString('Relire', $body);
    }

    public function testTheReReadingEndpointRefusesWhenThereIsNoConnector(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');

        $response = $this->controller->reviewComments(
            $this->jsonBodyRequest('POST', '/passage/relire-commentaires', []),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    // ── IT-18: optimise and reset ─────────────────────────────────────

    public function testOptimisingPlacesEverybodyNobodyHadPlaced(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $first = $this->createLouveteauLastRank();
        $second = $this->createLouveteauLastRank();

        $response = $this->controller->optimize(
            $this->jsonBodyRequest('POST', '/passage/optimiser', ['method' => 'balanced']),
            []
        );

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['placed']);
        $this->assertNotSame(
            null,
            $this->transferRepository->findDestinationSectionId($first['member_id'], $this->targetYearId())
        );
        $this->assertNotSame(
            null,
            $this->transferRepository->findDestinationSectionId($second['member_id'], $this->targetYearId())
        );
    }

    /**
     * The answer carries the recomputed box, exactly like a single save —
     * one round trip, and no cached figure between a decision and its
     * effect.
     */
    public function testTheOptimisationAnswerCarriesTheRecomputedStatistics(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $this->createLouveteauLastRank();

        $body = json_decode($this->controller->optimize(
            $this->jsonBodyRequest('POST', '/passage/optimiser', ['method' => 'balanced']),
            []
        )->getBody(), true);

        $this->assertStringContainsString('passage-statistics', $body['statistics_html']);
    }

    public function testOptimisingRejectsAForgedRequestWithoutACsrfToken(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $this->createLouveteauLastRank();

        $response = $this->controller->optimize(
            $this->rawJsonRequest('POST', '/passage/optimiser', ['method' => 'balanced']),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testResettingEmptiesTheYearAndAnswersWithTheRecomputedBox(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $member = $this->createLouveteauLastRank();
        $this->transferRepository->setDestination($member['member_id'], $this->targetYearId(), $this->eclaireursSectionId);

        $body = json_decode($this->controller->resetAssignments(
            $this->jsonBodyRequest('POST', '/passage/reinitialiser', []),
            []
        )->getBody(), true);

        $this->assertTrue($body['success']);
        $this->assertStringContainsString('passage-statistics', $body['statistics_html']);
        // Éclaireurs holds exactly ONE section in this fixture, so the
        // reset puts it straight back — which is the documented behaviour,
        // not a leftover: a branch with one section was never a decision to
        // lose. The multi-section case is pinned in
        // PassageOptimizationServiceTest, where the branch really offers a
        // choice.
        $this->assertSame(
            $this->eclaireursSectionId,
            $this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId())
        );
        $this->assertSame(1, $body['reassigned']);
    }

    public function testThePageAnnouncesWhatTheDialogWillAndWillNotTouch(): void
    {
        \Core\Security\AuthSession::login(1, 'admin@example.com', 'admin');
        $kept = $this->createLouveteauLastRank();
        $this->createLouveteauLastRank();
        $this->transferRepository->setDestination($kept['member_id'], $this->targetYearId(), $this->eclaireursSectionId);

        $body = $this->controller->index(new Request('GET', '/passage', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Optimiser la répartition', $body);
        $this->assertMatchesRegularExpression('#<strong>1</strong>\s*personne à répartir#', $body);
        $this->assertMatchesRegularExpression('#<strong>1</strong>\s*assignation déjà faite sera conservée#', $body);
    }

    private function targetYearId(): int
    {
        return (new ScoutYearService($this->pdo))->ensureYear('2027-2028');
    }

    public function testRenderedPageCarriesNoInlineEventHandler(): void
    {
        $this->createLouveteauLastRank();

        $html = $this->controller->index(new Request('GET', '/passage', [], [], [], []), [])->getBody();

        $this->assertSame(
            0,
            preg_match('/\son[a-z]+\s*=\s*["\']/i', $html),
            'La page Passage ne doit contenir aucun gestionnaire d\'événement inline (bloqué par la CSP).'
        );
        $this->assertStringContainsString('passage-save', $html);
    }
}
