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
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\TwigFactory;
use Modules\Registration\Controller\ReenrollmentController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentFormService;
use Modules\Registration\Service\ReenrollmentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * « Réinscription » — the page a parent answers on (spec §11).
 *
 * Two properties carry the whole thing, and both are about who a request
 * is allowed to speak for:
 *
 * - **The account decides which children, and nothing else does.** There
 *   is no member id in the URL, and the one in the body is not trusted:
 *   a save re-derives the account's own linked animés and refuses
 *   anything else. That is checked with a forged request, because a
 *   hidden field is not a boundary.
 * - **A question is only asked where there is a choice.** A child who
 *   stays in their section, or moves into a branch with one visible
 *   section, gets no section picker and no friend fields — asking would
 *   be asking a question with one answer, and the friend fields in
 *   particular exist only to place somebody.
 *
 * @group database
 */
class ReenrollmentControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReenrollmentController $controller;
    private ReenrollmentRepository $repository;
    private SettingService $settingService;
    private ReenrollmentCampaignService $campaign;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxA;
    private int $louveteauxB;
    private int $eclaireursA;
    private int $pionniersA;

    private const PARENT_EMAIL = 'parent@example.be';
    private const OTHER_EMAIL = 'ailleurs@example.be';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $louveteaux = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireurs = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $pionniers = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);
        $baladins = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);

        $this->louveteauxA = $this->createSection('LOUV1', $louveteaux, 'Louveteaux A');
        $this->louveteauxB = $this->createSection('LOUV2', $louveteaux, 'Louveteaux B');
        // Éclaireurs has ONE visible section: the branch a Louveteau of
        // the last rank moves into, and therefore the branch where no
        // question is worth asking.
        $this->eclaireursA = $this->createSection('ECLA1', $eclaireurs, 'Éclaireurs A');
        $this->pionniersA = $this->createSection('PION1', $pionniers, 'Pionniers A');
        $this->createSection('BALA1', $baladins, 'Baladins A');

        // No bracket rows to seed: AgeBracketRepository derives the entry
        // age and the duration from the branch's own sort order
        // (MemberYearService::branchForSortOrder()), so declaring the
        // branches above is declaring the age ranges.

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            ReenrollmentService::SETTING_FRIEND_WISH_LIMIT,
            '3',
            'number',
            'Souhaits',
            'Test.',
            'registration'
        );
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->currentYearId,
            'text',
            'Année publique',
            'Test.'
        );
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver(
            $scoutYearService,
            $this->settingService,
            new MemberYearRepository($this->pdo)
        );

        $passageService = new PassageService(
            $this->pdo,
            $this->encryption,
            $sectionService,
            new SectionTransferRepository($this->pdo),
            new RegistrationRequestRepository($this->pdo, $this->encryption),
            new AgeBracketRepository($this->pdo)
        );

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $reenrollmentService = new ReenrollmentService($this->repository, $this->settingService, $memberService);

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['registration' => dirname(__DIR__, 4) . '/modules/registration/views']
        );
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/reinscription');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        // IT-15 — the campaign window. Opened in the fixture, because
        // every test in this class is about what a family sees and does
        // while it IS open; the closed state has its own tests below.
        $this->campaign = new ReenrollmentCampaignService(
            $this->settingService,
            $scoutYearResolver,
            $scoutYearService,
            $this->repository,
            $passageService
        );
        $this->settingService->register(
            ReenrollmentCampaignService::SETTING_OPEN,
            '0',
            'boolean',
            'Campagne ouverte',
            'Test.',
            'registration'
        );
        $this->settingService->register(
            ReenrollmentCampaignService::SETTING_OPEN_AT,
            '03-01',
            'text',
            'Ouverture',
            'Test.',
            'registration'
        );
        $this->settingService->register(
            ReenrollmentCampaignService::SETTING_CLOSE_AT,
            '05-15',
            'text',
            'Fermeture',
            'Test.',
            'registration'
        );
        $this->campaign->open();

        $this->controller = new ReenrollmentController(
            $twig,
            new ReenrollmentFormService($memberService, $passageService, $reenrollmentService),
            $reenrollmentService,
            $scoutYearResolver,
            $scoutYearService,
            $this->campaign
        );

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    // ── whose children ────────────────────────────────────────────────

    public function testAnAccountLinkedToThreeAnimesSeesThreeCards(): void
    {
        $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->createAnime('Bo', '2016-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->createAnime('Cléo', '2015-06-01', $this->louveteauxB, self::PARENT_EMAIL);

        $body = $this->page();

        $this->assertStringContainsString('Alix', $body);
        $this->assertStringContainsString('Bo', $body);
        $this->assertStringContainsString('Cléo', $body);
        $this->assertSame(3, substr_count($body, 'class="reenrollment-form"'));
    }

    public function testAnAccountLinkedToNobodyGetsAnExplicitEmptyPage(): void
    {
        $body = $this->page();

        $this->assertStringNotContainsString('reenrollment-form', $body);
        // Twig escapes the apostrophe, so the assertion matches the part
        // that survives escaping rather than the sentence as written.
        $this->assertStringContainsString('Aucun animé', $body);
        $this->assertStringContainsString('rattaché à votre adresse', $body);
    }

    public function testAParentWhoIsAlsoAnAnimateurSeesNoCardForThemselves(): void
    {
        $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->createStaff('Akéla', '1990-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        $body = $this->page();

        $this->assertStringContainsString('Alix', $body);
        $this->assertSame(
            1,
            substr_count($body, 'class="reenrollment-form"'),
            'An animateur has nothing to answer about themselves.'
        );
    }

    public function testAnAccountCanNeverAnswerForAChildItIsNotLinkedTo(): void
    {
        $mine = $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $theirs = $this->createAnime('Zoé', '2017-06-01', $this->louveteauxA, self::OTHER_EMAIL);

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $response = $this->controller->save($this->post(['member_id' => (string) $theirs, 'decision' => 'reenrolled']), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(
            $this->repository->findAnswer($theirs, $this->targetYearId),
            'A forged member id is refused on the account\'s own list, never on a hidden field.'
        );
        $this->assertNull($this->repository->findAnswer($mine, $this->targetYearId));
    }

    public function testAnAccountCannotAnswerForAnAnimateurEitherEvenTheirOwn(): void
    {
        $staffId = $this->createStaff('Akéla', '1990-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $response = $this->controller->save($this->post(['member_id' => (string) $staffId, 'decision' => 'reenrolled']), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ── which questions ───────────────────────────────────────────────

    public function testAChildWhoStaysInTheirSectionIsAskedNeitherASectionNorFriends(): void
    {
        // Second-rank Louveteau: mid-branch, so no move at all.
        $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        $body = $this->page();

        $this->assertStringContainsString('reste dans sa section', $body);
        $this->assertStringNotContainsString('Section souhaitée', $body);
        $this->assertStringNotContainsString('name="friend_names[]"', $body);
    }

    public function testABranchWithOneVisibleSectionIsAnnouncedRatherThanAsked(): void
    {
        // Last-rank Louveteau moving into Éclaireurs, which has one section.
        $this->createAnime('Cléo', '2015-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        $body = $this->page();

        $this->assertStringContainsString('change de branche', $body);
        $this->assertStringContainsString("c'est la seule", $body);
        $this->assertStringNotContainsString('Section souhaitée', $body);
        $this->assertStringNotContainsString('name="friend_names[]"', $body);
    }

    public function testABranchWithSeveralVisibleSectionsAsksBoth(): void
    {
        // A hidden second Éclaireurs section is NOT a choice a family can
        // make, so it must not turn the question back on.
        $this->createSection('ECLA2', $this->branchIdOf($this->eclaireursA), 'Éclaireurs B', visible: false);
        $this->createAnime('Cléo', '2015-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->assertStringNotContainsString('Section souhaitée', $this->page());

        // Made visible, the same child now has a real choice.
        $this->pdo->exec("UPDATE sections SET is_visible = 1 WHERE desk_code = 'ECLA2'");

        $body = $this->page();
        $this->assertStringContainsString('Section souhaitée', $body);
        $this->assertSame(3, substr_count($body, 'name="friend_names[]"'));
    }

    public function testTheNumberOfFriendFieldsFollowsTheSetting(): void
    {
        $this->createSection('ECLA2', $this->branchIdOf($this->eclaireursA), 'Éclaireurs B');
        $this->createAnime('Cléo', '2015-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        $this->settingService->set(ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '1', 'registration');
        $this->assertSame(1, substr_count($this->page(), 'name="friend_names[]"'));

        $this->settingService->set(ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '5', 'registration');
        $this->assertSame(5, substr_count($this->page(), 'name="friend_names[]"'));
    }

    // ── answering ─────────────────────────────────────────────────────

    public function testAnAnswerIsStoredAndShownBackOnTheNextVisit(): void
    {
        $memberId = $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $response = $this->controller->save($this->post([
            'member_id' => (string) $memberId,
            'decision' => 'reenrolled',
            'family_comment' => 'Alix a hâte.',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $answer = $this->repository->findAnswer($memberId, $this->targetYearId);
        $this->assertNotNull($answer);
        $this->assertTrue($answer->isReenrolled());
        $this->assertSame('Alix a hâte.', $answer->familyComment);

        $body = $this->page();
        $this->assertStringContainsString('Alix a hâte.', $body);
        $this->assertStringContainsString('Réinscrit', $body);
    }

    public function testAFamilyMayChangeTheirMind(): void
    {
        $memberId = $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        AuthSession::login(1, self::PARENT_EMAIL, 'identified');

        $this->controller->save($this->post(['member_id' => (string) $memberId, 'decision' => 'reenrolled']), []);
        $this->controller->save($this->post(['member_id' => (string) $memberId, 'decision' => 'leaving', 'family_comment' => 'Nous déménageons.']), []);

        $answer = $this->repository->findAnswer($memberId, $this->targetYearId);
        $this->assertNotNull($answer);
        $this->assertFalse($answer->isReenrolled());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM registration_reenrollments')->fetchColumn()
        );
    }

    public function testFriendNamesAreStoredWithoutTellingTheFamilyAnything(): void
    {
        $this->createSection('ECLA2', $this->branchIdOf($this->eclaireursA), 'Éclaireurs B');
        $memberId = $this->createAnime('Cléo', '2015-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->createAnime('Zoé', '2015-06-01', $this->louveteauxB, self::OTHER_EMAIL);

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $this->controller->save($this->post([
            'member_id' => (string) $memberId,
            'decision' => 'reenrolled',
            'friend_names' => ['Zoé Dupont', 'Personne Du Tout'],
        ]), []);

        $answer = $this->repository->findAnswer($memberId, $this->targetYearId);
        $this->assertNotNull($answer);
        $this->assertSame(['Zoé Dupont', 'Personne Du Tout'], array_column($answer->friendWishes, 'rawName'));

        // The page shows the names back — they are the family's own words —
        // and says nothing whatsoever about whether either was recognised.
        $body = $this->page();
        $this->assertStringContainsString('Zoé Dupont', $body);
        $this->assertStringContainsString('Personne Du Tout', $body);
        foreach (['reconnu', 'introuvable', 'trouvé', 'ambigu'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    // ── the campaign window ───────────────────────────────────────────

    public function testAClosedCampaignLeavesThePageReadableAndTheAnswerVisible(): void
    {
        $memberId = $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $this->controller->save($this->post([
            'member_id' => (string) $memberId,
            'decision' => 'reenrolled',
            'family_comment' => 'Alix a hâte.',
        ]), []);

        $this->campaign->close();
        $body = $this->page();

        // The page stays: making it disappear would tell a family who had
        // answered that their answer went with it.
        $this->assertStringContainsString('Alix', $body);
        $this->assertStringContainsString('Alix a hâte.', $body);
        $this->assertStringContainsString('campagne de réinscription est clôturée', $body);
        $this->assertStringNotContainsString('class="reenrollment-form"', $body);
        $this->assertStringNotContainsString('Enregistrer ma réponse', $body);
    }

    public function testAClosedCampaignRefusesAWriteEvenFromAFormLeftOpenInATab(): void
    {
        $memberId = $this->createAnime('Alix', '2017-06-01', $this->louveteauxA, self::PARENT_EMAIL);
        $this->campaign->close();

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $response = $this->controller->save($this->post([
            'member_id' => (string) $memberId,
            'decision' => 'leaving',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(
            $this->repository->findAnswer($memberId, $this->targetYearId),
            'The window is enforced on the server, not by the absence of a button.'
        );
    }

    // ── the RBAC floor ────────────────────────────────────────────────

    public function testThePageIsDeclaredIdentifiedAndRefusesThePublic(): void
    {
        $roleMin = $this->declaredRoleMin('/reinscription', 'GET');
        $this->assertSame('identified', $roleMin);

        $this->startSession();
        AuthSession::logout();
        $this->assertNotNull(
            (new RbacGuard())->enforce(Role::fromString($roleMin)),
            'A visitor who is not signed in has no children to answer for.'
        );

        AuthSession::login(1, self::PARENT_EMAIL, 'identified');
        $this->assertNull((new RbacGuard())->enforce(Role::fromString($roleMin)));
    }

    public function testTheSaveCarriesTheSameFloor(): void
    {
        $this->assertSame('identified', $this->declaredRoleMin('/reinscription', 'POST'));
    }

    // ── fixture ───────────────────────────────────────────────────────

    private function page(): string
    {
        $this->startSession();
        AuthSession::login(1, self::PARENT_EMAIL, 'identified');

        return $this->controller->index(new Request('GET', '/reinscription', [], [], [], []), [])->getBody();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): Request
    {
        $this->startSession();
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/reinscription', [], $fields + ['_csrf_token' => $token], [], []);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    private function declaredRoleMin(string $path, string $method): string
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === $method) {
                return (string) $route['role_min'];
            }
        }

        $this->fail("{$method} {$path} is not declared in module.json.");
    }

    private function branchIdOf(int $sectionId): int
    {
        return (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();
    }

    private function createSection(string $deskCode, int $branchId, string $name, bool $visible = true): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name, $visible ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createAnime(string $firstName, string $birthDate, int $sectionId, string $email): int
    {
        return $this->createMember($firstName, $birthDate, $sectionId, $email, 'identified');
    }

    private function createStaff(string $firstName, string $birthDate, int $sectionId, string $email): int
    {
        return $this->createMember($firstName, $birthDate, $sectionId, $email, 'chief');
    }

    private function createMember(string $firstName, string $birthDate, int $sectionId, string $email, string $role): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, email_encrypted, email_blind_index, leaving, scout_year_offset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
            $this->encryption->encrypt('M', 'member_years.gender'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$role}', 'Fn {$role}', '{$role}')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = '{$role}'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $this->branchIdOf($sectionId)]);

        return $memberId;
    }
}
