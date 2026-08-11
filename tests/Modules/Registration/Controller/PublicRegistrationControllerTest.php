<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use Modules\Registration\Controller\PublicRegistrationController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RegistrationService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;

/**
 * Full-stack tests through the real controller (GET renders the real form,
 * POST submits it) — covers anti-robot gating (mirrors Tests\Modules\News\
 * Controller\NewsFormHumanCheckTest's approach), sibling checkbox behavior,
 * and desired-section validation against the birth-date-derived branch.
 *
 * @group database
 */
class PublicRegistrationControllerTest extends TestCase
{
    private \PDO $pdo;
    private PublicRegistrationController $controller;
    private SettingService $settingService;
    private int $baladinsId;
    private int $louveteauxId;
    private int $baladinsSectionId;
    private int $louveteauxSectionId;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $this->louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$this->baladinsId, 'BALA1', 'Baladins A']);
        $this->baladinsSectionId = (int) $this->pdo->lastInsertId();
        $stmt->execute([$this->louveteauxId, 'LOUV1', 'Louveteaux A']);
        $this->louveteauxSectionId = (int) $this->pdo->lastInsertId();

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('registration_form_open', '0', 'boolean', 'Ouvert', 'desc', 'registration');
        $this->settingService->register('registration_waitlist_enabled', '0', 'boolean', 'Places', 'desc', 'registration');
        $this->settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $this->settingService->register('registration_unit_alert_email', '', 'text', 'Alerte', 'desc', 'registration');
        $this->settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'Dispo', 'desc', 'registration');
        $this->settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'Limité', 'desc', 'registration');
        $this->settingService->register('registration_parcours_image_file_id', '0', 'number', 'Image', 'desc', 'registration');
        $this->settingService->register('human_check_min_delay_seconds', '2', 'number', 'Délai', 'desc');
        $this->settingService->register('human_check_form_validity_seconds', '100', 'number', 'Validité', 'desc');
        $this->settingService->set('registration_form_open', '1', 'registration');

        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $scoutYearService = new ScoutYearService($this->pdo);
        $publicYearId = $scoutYearService->ensureYear('2026-2027');
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $publicYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $yearCodeRepository = new RegistrationYearCodeRepository($this->pdo);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $this->encryption);

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);

        $slotService = new SlotService($this->pdo, $this->encryption, $this->settingService, $ageBracketRepository, $slotCapacityRepository, $requestRepository);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send');

        $registrationService = new RegistrationService(
            $requestRepository, $yearCodeRepository, $scoutYearResolver, $scoutYearService, $this->settingService,
            $mailService, $editableContentService, $journalService, 'https://example.com', 'Unité Test'
        );

        $humanCheck = new HumanCheckService(
            $this->encryption, new HumanCheckRateLimitRepository($this->pdo), $this->settingService, $journalService
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/inscriptions');

        $this->controller = new PublicRegistrationController(
            $twig, $registrationService, $slotService, $sectionService, $ageBracketRepository,
            $scoutYearResolver, $memberService, $this->settingService, $humanCheck
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        unset($secondaryEmailRepository);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    /**
     * @return array<string, string>
     */
    private function humanCheckFields(): array
    {
        $response = $this->controller->index(new Request('GET', '/inscriptions', [], [], [], []), []);
        $body = $response->getBody();

        preg_match('/name="human_check_token" value="([^"]+)"/', $body, $tokenMatch);
        preg_match('/class="hc-trap"[^>]*>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*id="([^"]+)"/', $body, $trapMatch);

        $this->assertNotEmpty($tokenMatch, 'Page must render a human_check_token field for an anonymous visitor');
        $this->assertNotEmpty($trapMatch, 'Page must render the honeypot trap field');

        return ['human_check_token' => $tokenMatch[1], $trapMatch[1] => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFields(array $overrides = []): array
    {
        return array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            'parent_name' => 'Marie Dupont',
            'child_last_name' => 'Dupont',
            'child_first_name' => 'Léa',
            'gender' => 'F',
            'birth_date' => '2019-06-01',
            'street' => 'Rue de la Paix',
            'number' => '12',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'marie.dupont@example.com',
            'phone1' => '0470123456',
            'rgpd_accepted' => '1',
        ], $overrides);
    }

    public function testFormClosedReturns404(): void
    {
        $this->settingService->set('registration_form_open', '0', 'registration');

        $response = $this->controller->submit(new Request('POST', '/inscriptions', [], $this->baseFields(), [], []), []);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $response = $this->controller->submit(
            new Request('POST', '/inscriptions', [], $this->baseFields(['_csrf_token' => 'wrong']), [], []),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testHumanSubmissionAfterDelayIsAcceptedAndPersisted(): void
    {
        $hcFields = $this->humanCheckFields();
        sleep(2);

        $response = $this->controller->submit(
            new Request('POST', '/inscriptions', [], array_merge($this->baseFields(), $hcFields), [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM registration_requests')->fetchColumn());
    }

    public function testBotFillingHoneypotIsRejectedWithoutPersisting(): void
    {
        $hcFields = $this->humanCheckFields();
        $trapFieldName = array_key_last($hcFields);
        $hcFields[$trapFieldName] = 'I am a bot';

        $response = $this->controller->submit(
            new Request('POST', '/inscriptions', [], array_merge($this->baseFields(), $hcFields), [], []),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM registration_requests')->fetchColumn());
        // Sticky values: the parent's entered data must not be lost.
        $this->assertStringContainsString('Léa', $response->getBody());
    }

    public function testDesiredSectionMustMatchTheBirthDateDerivedBranch(): void
    {
        $hcFields = $this->humanCheckFields();
        sleep(2);

        // Target year is next year (2027-2028), reference date 12-31 =>
        // reference calendar year 2027 (Service\SlotMath::
        // referenceCalendarYear() keeps the scout year's start year for a
        // September-or-later reference month). birth_date 2021-06-01 =>
        // age 6 => Baladins (entry age 6). Submitting Louveteaux's section
        // for a Baladins-age child must be silently dropped to "no
        // preference" rather than trusted.
        $response = $this->controller->submit(
            new Request('POST', '/inscriptions', [], array_merge(
                $this->baseFields(['birth_date' => '2021-06-01']),
                $hcFields,
                ['desired_section_id' => (string) $this->louveteauxSectionId]
            ), [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $row = $this->pdo->query('SELECT desired_section_id FROM registration_requests ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotSame((string) $this->louveteauxSectionId, (string) $row['desired_section_id']);
    }

    public function testSiblingIdsAreIgnoredWhenNotIdentified(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $hcFields = $this->humanCheckFields();
        sleep(2);

        $response = $this->controller->submit(
            new Request('POST', '/inscriptions', [], array_merge(
                $this->baseFields(),
                $hcFields,
                ['sibling_member_ids' => [(string) $memberId]]
            ), [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $requestId = (int) $this->pdo->query('SELECT id FROM registration_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM registration_request_siblings WHERE registration_request_id = ' . $requestId)->fetchColumn());
    }
}
