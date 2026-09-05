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
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\ReenrollmentConfigController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ReenrollmentCampaignService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * « Espace chefs d'U > Réinscription » — where a chef d'unité sets the
 * campaign's window and watches the answers arrive.
 *
 * The page was measured at 24 %: everything a chef actually does on it —
 * saving the dates, flipping the manual switch, asking for a reminder —
 * ran in no test at all. What it decides is not cosmetic. The switch
 * queues the closing e-mail to every silent family; a malformed date
 * saved as-is would make the campaign open on a day that never comes.
 *
 * Four properties carry the page:
 *
 * - **A malformed value changes nothing.** The field is skipped, the
 *   stored value survives, and the rest of the form still saves — the
 *   alternative is a campaign whose dates are half-written.
 * - **The manual switch never touches the scheduled markers**, so opening
 *   early cannot make the automatic transition fire twice, nor closing
 *   early make it not fire at all.
 * - **A close, however it happened, owes the families their closing
 *   e-mail** — and the queue's own reference keeps it to one per campaign.
 * - **A reminder is refused on a campaign nobody can answer any more**,
 *   with a sentence that says why rather than a silent no-op.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReenrollmentConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ReenrollmentCampaignService $campaign;
    private ReenrollmentConfigController $controller;
    private int $currentYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2026-2027',
            '2026-09-01',
            '2027-08-31'
        );
        RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['LOUV1', $branchId, 'Louveteaux A']);

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            [ReenrollmentCampaignService::SETTING_OPEN, '0', 'boolean'],
            [ReenrollmentCampaignService::SETTING_OPEN_AT, '03-01', 'text'],
            [ReenrollmentCampaignService::SETTING_CLOSE_AT, '05-15', 'text'],
            [ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '14', 'number'],
            [ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS, '2', 'number'],
            [ReenrollmentCampaignService::MARKER_OPENED, '', 'text'],
            [ReenrollmentCampaignService::MARKER_CLOSED, '', 'text'],
        ] as [$key, $default, $type]) {
            $this->settingService->register($key, $default, $type, $key, 'Test.', 'registration');
        }
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->currentYearId,
            'text',
            'Année publique',
            'Test.'
        );
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $connection = Connection::withPdo($this->pdo);
        $scoutYearService = new ScoutYearService($this->pdo);
        $passageService = new PassageService(
            $this->pdo,
            $encryption,
            new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo)),
            new SectionTransferRepository($this->pdo),
            new RegistrationRequestRepository($this->pdo, $encryption),
            new AgeBracketRepository($this->pdo)
        );
        $this->campaign = new ReenrollmentCampaignService(
            $this->settingService,
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            $scoutYearService,
            new ReenrollmentRepository($this->pdo, $encryption),
            $passageService
        );

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['registration' => dirname(__DIR__, 4) . '/modules/registration/views']
        );
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/reinscription');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new ReenrollmentConfigController(
            $twig,
            $this->campaign,
            $this->settingService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );

        AuthSession::login(1, 'chef@example.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── the page ──────────────────────────────────────────────────────

    public function testThePageShowsTheDatesCurrentlyStored(): void
    {
        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('03-01', $html);
        $this->assertStringContainsString('05-15', $html);
    }

    /**
     * A page that listed them would be a list of children whose parents
     * have said they are leaving, sitting on a configuration screen.
     */
    public function testThePageCountsTheAnimesAndNamesNone(): void
    {
        $this->createAnime('Alix', 'famille@example.be');

        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('Alix', $html);
        $this->assertStringNotContainsString('famille@example.be', $html);
    }

    // ── saving ────────────────────────────────────────────────────────

    public function testAWellFormedWindowIsSaved(): void
    {
        $this->save([
            ReenrollmentCampaignService::SETTING_OPEN_AT => '02-15',
            ReenrollmentCampaignService::SETTING_CLOSE_AT => '06-30',
        ]);

        $this->assertSame('02-15', $this->stored(ReenrollmentCampaignService::SETTING_OPEN_AT));
        $this->assertSame('06-30', $this->stored(ReenrollmentCampaignService::SETTING_CLOSE_AT));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedDates(): array
    {
        return [
            'plain words' => [ReenrollmentCampaignService::SETTING_OPEN_AT, 'bientôt'],
            'a full date' => [ReenrollmentCampaignService::SETTING_OPEN_AT, '2027-03-01'],
            'one digit short' => [ReenrollmentCampaignService::SETTING_CLOSE_AT, '5-15'],
            'emptied' => [ReenrollmentCampaignService::SETTING_CLOSE_AT, ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDates')]
    public function testAMalformedDateLeavesTheStoredOneAlone(string $key, string $value): void
    {
        $before = $this->stored($key);

        $this->save([$key => $value]);

        $this->assertSame($before, $this->stored($key), 'A half-written campaign window is worse than an unchanged one.');
    }

    public function testANonNumericReminderDelayLeavesTheStoredOneAlone(): void
    {
        $this->save([ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => 'deux semaines']);

        $this->assertSame('14', $this->stored(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS));
    }

    public function testAMalformedFieldDoesNotStopTheOthersFromSaving(): void
    {
        $this->save([
            ReenrollmentCampaignService::SETTING_OPEN_AT => "n'importe quoi",
            ReenrollmentCampaignService::SETTING_CLOSE_AT => '06-30',
            ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => '21',
        ]);

        $this->assertSame('03-01', $this->stored(ReenrollmentCampaignService::SETTING_OPEN_AT));
        $this->assertSame('06-30', $this->stored(ReenrollmentCampaignService::SETTING_CLOSE_AT));
        $this->assertSame('21', $this->stored(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS));
    }

    // ── the manual switch ─────────────────────────────────────────────

    public function testOpeningTheCampaignByHandOpensIt(): void
    {
        $this->save(['is_open' => '1']);

        $this->assertTrue($this->campaign->isOpen());
    }

    /**
     * Opening early must not make the scheduled transition fire twice, nor
     * closing early make it not fire at all.
     */
    public function testTheManualSwitchNeverTouchesTheScheduledMarkers(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15', 'registration');

        $this->save(['is_open' => '1']);

        $this->assertSame(
            '2027-05-15',
            $this->stored(ReenrollmentCampaignService::MARKER_OPENED)
        );
    }

    public function testClosingTheCampaignByHandOwesTheFamiliesTheirClosingEmail(): void
    {
        $this->campaign->open();

        $this->save(['is_open' => '0']);

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertSame(ReenrollmentCampaignService::EMAIL_CLOSING, $payload['type']);
    }

    public function testTheClosingEmailIsQueuedOncePerCampaignHoweverOftenItIsClosed(): void
    {
        $this->campaign->open();
        $this->save(['is_open' => '0']);
        $this->campaign->open();
        $this->save(['is_open' => '0']);

        $references = array_column($this->queued(), 'reference');
        $this->assertSame(
            $references,
            array_unique($references),
            'The queue reference is what keeps a twice-closed campaign to one closing e-mail.'
        );
    }

    public function testSavingWithoutChangingTheSwitchQueuesNothing(): void
    {
        $this->campaign->open();

        $this->save(['is_open' => '1', ReenrollmentCampaignService::SETTING_CLOSE_AT => '06-30']);

        $this->assertSame([], $this->queued());
    }

    // ── the manual reminder ───────────────────────────────────────────

    public function testAReminderOnAClosedCampaignIsRefusedAndSaysWhy(): void
    {
        $this->campaign->close();

        $this->remind();

        $this->assertSame([], $this->queued());
        $this->assertStringContainsString('fermée', $this->flash('error'));
    }

    public function testAReminderOutsideAnyCampaignIsRefusedAndSaysWhy(): void
    {
        $this->campaign->open();
        // No window at all: there is no campaign key to attach a reminder to.
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '', 'registration');

        $this->remind();

        $this->assertSame([], $this->queued());
        $this->assertStringContainsString('Aucune campagne', $this->flash('error'));
    }

    public function testAReminderOnAnOpenCampaignIsQueuedForTheSilentFamilies(): void
    {
        $this->campaign->open();

        $this->remind();

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertSame(ReenrollmentCampaignService::EMAIL_REMINDER_1, $payload['type']);
        $this->assertSame(0, $payload['after_key'], 'A manual reminder starts from the top of the list.');
    }

    /**
     * Two clicks in one second are one reminder; two clicks a week apart
     * are two. The reference carries the minute it was asked for.
     */
    public function testTwoClicksInTheSameMinuteAreOneReminder(): void
    {
        $this->campaign->open();

        $this->remind();
        $this->remind();

        $references = array_column($this->queued(), 'reference');
        $this->assertCount(1, array_unique($references));
    }

    // ── the boundary ──────────────────────────────────────────────────

    public function testASaveWithoutACsrfTokenChangesNothing(): void
    {
        $this->controller->save(
            new Request(
                'POST',
                '/config/reinscription',
                [],
                [ReenrollmentCampaignService::SETTING_CLOSE_AT => '06-30'],
                [],
                []
            ),
            []
        );

        $this->assertSame('05-15', $this->stored(ReenrollmentCampaignService::SETTING_CLOSE_AT));
    }

    public function testAReminderWithoutACsrfTokenQueuesNothing(): void
    {
        $this->campaign->open();

        $this->controller->remind(new Request('POST', '/config/reinscription/relance', [], [], [], []), []);

        $this->assertSame([], $this->queued());
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * @param array<string, string> $fields
     */
    private function save(array $fields): void
    {
        $this->controller->save($this->post('/config/reinscription', $fields), []);
    }

    private function remind(): void
    {
        $this->controller->remind($this->post('/config/reinscription/relance', []), []);
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): Request
    {
        return new Request('POST', $path, [], $fields + ['_csrf_token' => CsrfGuard::generateToken()], [], []);
    }

    private function stored(string $key): string
    {
        return (string) $this->settingService->get($key, 'registration', '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queued(): array
    {
        return $this->pdo
            ->query("SELECT payload, reference FROM scheduled_actions WHERE task_key = 'send_reenrollment_emails'")
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Reading it CLEARS it, exactly as a page render does — which is why
     * each test that asserts on a flash asks for it once.
     */
    private function flash(string $type): string
    {
        $flash = \Core\Http\FlashMessage::get();

        return is_array($flash) && ($flash['type'] ?? '') === $type ? (string) $flash['message'] : '';
    }

    private function createAnime(string $firstName, string $email): int
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['DESK_' . uniqid()]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted,
                 gender_encrypted, email_encrypted, email_blind_index, leaving, scout_year_offset, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $encryption->encrypt($firstName, 'member_years.first_name'),
            $encryption->encrypt('Dupont', 'member_years.last_name'),
            $encryption->encrypt('2017-06-01', 'member_years.birth_date'),
            $encryption->encrypt('M', 'member_years.gender'),
            $encryption->encrypt($email, 'member_years.email'),
            $encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')"
        );
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $sectionId = (int) $this->pdo->query('SELECT id FROM sections LIMIT 1')->fetchColumn();
        $branchId = (int) $this->pdo
            ->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)
            ->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberId;
    }
}
