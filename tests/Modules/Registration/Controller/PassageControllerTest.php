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
class PassageControllerTest extends TestCase
{
    private \PDO $pdo;
    private PassageController $controller;
    private RegistrationRequestRepository $requestRepository;
    private SectionTransferRepository $transferRepository;
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
        $ageBracketRepository->upsert($louveteauxBranchId, 8, 4);
        $ageBracketRepository->upsert($eclaireursBranchId, 12, 4);
        $ageBracketRepository->upsert($pionniersBranchId, 16, 2);

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

        $this->controller = new PassageController(
            $twig, $passageService, $this->requestRepository, $this->transferRepository, $sectionService,
            $ageBracketRepository, $slotService, $scoutYearResolver, $scoutYearService
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
        $stmt->execute([$memberId, $this->currentYearId, $encryption->encrypt('Sacha'), $encryption->encrypt('Dupont'), $encryption->encrypt('2015-06-01')]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->louveteauxSectionId, (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->louveteauxSectionId)->fetchColumn()]);

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    private function jsonBodyRequest(string $method, string $path, array $body): Request
    {
        return new Request($method, $path, [], array_merge($body, ['_csrf_token' => CsrfGuard::generateToken()]), [], []);
    }

    public function testTargetYearIsPublicYearPlusOneDespiteStaffYearAndPreview(): void
    {
        $response = $this->controller->index(new Request('GET', '/passage', [], [], [], []), []);

        $this->assertStringContainsString('2027-2028', $response->getBody());
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

        $this->assertSame(302, $response->getStatusCode());
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

        $request = new Request('POST', "/passage/membre/{$member['member_id']}/destination", [], [
            'destination_section_id' => (string) $this->eclaireursSectionId, '_csrf_token' => 'bad',
        ], [], []);
        $this->controller->saveDestination($request, ['id' => (string) $member['member_id']]);

        $this->assertNull($this->transferRepository->findDestinationSectionId($member['member_id'], $this->targetYearId));
    }
}
