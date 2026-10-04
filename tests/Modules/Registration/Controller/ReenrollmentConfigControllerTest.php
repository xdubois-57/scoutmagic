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
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

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
    /**
     * « Now », for every test that does not choose its own: inside the
     * 2027 campaign (01-03 → 15-05), the year the fixture's public year asks
     * about. The page used to read the wall clock, and its tests passed or
     * failed with the season.
     */
    private const NOW = '2027-04-20 10:00:00';

    private \PDO $pdo;
    private \Modules\Registration\Service\ReenrollmentRecipientService $recipients;
    public SettingService $settingService;
    public ReenrollmentCampaignService $campaign;
    private ReenrollmentConfigController $controller;
    private int $currentYearId;
    private \Twig\Environment $twig;

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
        // From the manifest, never by hand — the fixture that hid the
        // markers being declared nowhere.
        RegistrationTestHelper::registerManifestSettings($this->settingService);
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '03-01', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '05-15', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '14', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS, '2', 'registration');
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
            new \Modules\Registration\Repository\PassageRosterRepository($this->pdo, $encryption),
            $encryption,
            new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
),
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

        $this->twig = $twig = TwigFactory::create(
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

        $this->recipients = new \Modules\Registration\Service\ReenrollmentRecipientService(
            new \Modules\Registration\Repository\PassageRosterRepository($this->pdo, $encryption),
            new ReenrollmentRepository($this->pdo, $encryption),
            $passageService
        );
        $this->controller = $this->controllerAt(new \DateTimeImmutable(self::NOW));

        AuthSession::login(1, 'chef@example.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function controllerAt(\DateTimeImmutable $now): ReenrollmentConfigController
    {
        return new ReenrollmentConfigController(
            $this->twig,
            $this->campaign,
            $this->settingService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            \Modules\Registration\Service\ReenrollmentSavePlanner::countingWith(
                $this->campaign,
                $this->settingService,
                $this->recipients,
                new SchedulerService(new SchedulerRepository($this->pdo))
            ),
            static fn (): \DateTimeImmutable => $now
        );
    }

    // ── the page ──────────────────────────────────────────────────────

    public function testTheSettingsPageShowsTheDatesCurrentlyStored(): void
    {
        $html = $this->settingsPage();

        $this->assertStringContainsString('03-01', $html);
        $this->assertStringContainsString('05-15', $html);
    }

    /**
     * **The page says whether each email of the campaign has gone out.**
     *
     * Clicking « Relancer » schedules a task and shows a success message;
     * without this the page never said whether it ran, so the only way to
     * find out was to click again — and write a second time to every
     * silent family.
     */
    public function testThePageSaysWhichEmailsHaveNotGoneOutYet(): void
    {
        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Pas encore envoyé', $html);
        $this->assertStringNotContainsString('Envoyé le', $html);
    }

    public function testThePageShowsTheMomentAnEmailActuallyWentOut(): void
    {
        $this->campaign->markDone(
            ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1),
            $this->currentCampaignKey(),
            new \DateTimeImmutable('2027-03-02 09:14:00')
        );

        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Envoyé le 02/03/2027 à 09:14', $html);
    }

    /**
     * A marker from a previous campaign is not this campaign's news — the
     * page would otherwise date an email that has not gone out yet.
     */
    public function testAPreviousCampaignsSendDateIsNotShown(): void
    {
        $this->campaign->markDone(
            ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1),
            '2020-05-15',
            new \DateTimeImmutable('2020-03-02 09:14:00')
        );

        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('02/03/2020', $html);
        $this->assertStringContainsString('Pas encore envoyé', $html);
    }

    /**
     * And an installation upgraded mid-campaign has a marker with no
     * moment beside it. « Pas encore envoyé » would be false for an email
     * a chief watched leave.
     */
    public function testAnEmailSentBeforeMomentsWereRecordedStillReadsAsSent(): void
    {
        $key = $this->currentCampaignKey();
        $marker = ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1);

        $this->campaign->markDone($marker, $key);
        // What an upgrade leaves behind: the marker, and an empty moment.
        $this->settingService->setInternal($marker . '_moment', '', 'registration');

        $html = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Envoyé, date inconnue', $html);
    }

    private function currentCampaignKey(): string
    {
        $key = $this->campaign->currentCampaignKey(new \DateTimeImmutable(self::NOW));
        $this->assertNotNull($key, 'the fixture must have a campaign in progress');

        return $key;
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
        $this->createAnime('Alix', 'famille@example.be');
        $this->campaign->open();

        $this->save(['is_open' => '0']);

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertSame(ReenrollmentCampaignService::EMAIL_CLOSING, $payload['type']);
    }

    /**
     * **The incident of issue #796, replayed.** On 4 October a chef d'unité
     * turned « Campagne ouverte » off. The campaign the page called current
     * was still the one that had closed on 15 May, and its silent families
     * received a closing e-mail five months late, with no question asked.
     * A campaign whose close date is behind us is over: closing it again
     * writes to nobody.
     */
    public function testClosingInOctoberACampaignThatEndedInMayWritesToNobody(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->campaign->open();

        $this->save(['is_open' => '0'], $this->controllerAt(new \DateTimeImmutable('2026-10-04 10:35:00')));

        $this->assertFalse($this->campaign->isOpen(), 'the switch itself still works');
        $this->assertSame([], $this->queued(), 'and no send_reenrollment_emails task exists');
    }

    public function testTheClosingEmailIsQueuedOncePerCampaignHoweverOftenItIsClosed(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->campaign->open();
        $this->save(['is_open' => '0']);
        $this->campaign->open();
        $this->save(['is_open' => '0']);

        // The row count, not the uniqueness of what the rows carry:
        // array_unique() collapses duplicates in PHP before the assertion
        // sees them, and an empty queue has no duplicates either.
        $this->assertCount(
            1,
            $this->queued(),
            'The hand-over guard is what keeps a twice-closed campaign to one closing e-mail.'
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

        $this->assertCount(1, $this->queued(), 'Two clicks, one reminder — counted in rows.');
    }

    // ── the e-mails switch and the opening question (issue #732) ─────

    public function testThePageExplainsWhichEmailsLeaveAndOffersTheSwitchOnByDefault(): void
    {
        $body = $this->settingsPage();

        $this->assertStringContainsString('Envoyer les e-mails de la campagne', $body);
        $this->assertMatchesRegularExpression('/id="emails-enabled"[^>]*checked/s', $body);
        $this->assertStringContainsString('aucun de ces e-mails ne part', $body);
        $this->assertStringContainsString('reenrollment-config.js', $body);
    }

    public function testSwitchingTheEmailsOffIsSaved(): void
    {
        $this->save([ReenrollmentCampaignService::SETTING_EMAILS_ENABLED => '0']);

        $this->assertFalse($this->campaign->emailsEnabled());
    }

    public function testAPostWithoutTheEmailsFieldLeavesItAsItWas(): void
    {
        $this->save([ReenrollmentCampaignService::SETTING_CLOSE_AT => '06-30']);

        $this->assertTrue($this->campaign->emailsEnabled());
    }

    // ── the universal confirmation (issue #796, IT-03) ───────────────

    /**
     * Without the plan's fingerprint nothing is written: the server shows
     * the confirmation itself — what a browser without the script gets.
     */
    public function testASaveThatChangesSomethingIsNotWrittenWithoutConfirmation(): void
    {
        $html = html_entity_decode(
            $this->saveUnconfirmed([ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => '10']),
            ENT_QUOTES
        );

        $this->assertSame('14', $this->stored(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS), 'nothing saved');
        $this->assertStringContainsString("Confirmer l'enregistrement", $html);
        $this->assertStringContainsString('Premier rappel : 14 → 10 jours avant la fermeture', $html);
        $this->assertStringContainsString('Aucun e-mail ne partira.', $html);
        $this->assertStringContainsString('name="plan_fingerprint"', $html);
        $this->assertStringContainsString('value="10"', $html, 'the form travels back unchanged');
    }

    public function testTheServersConfirmationPagePostsBackAndSaves(): void
    {
        $html = $this->saveUnconfirmed([ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => '10']);
        preg_match('/name="plan_fingerprint" value="([0-9a-f]{64})"/', $html, $m);

        $this->controller->save($this->post('/config/reinscription', [
            ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => '10',
            'plan_fingerprint' => $m[1] ?? '',
        ]), []);

        $this->assertSame('10', $this->stored(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS));
    }

    /**
     * A plan that moved between the question and the answer is refused,
     * and the chief reads the new one.
     */
    public function testAStalePlanIsRefusedAndShownAgain(): void
    {
        $this->campaign->open();
        $this->createAnime('Alix', 'famille@example.be');
        $fingerprint = (string) $this->preview(['is_open' => '0'])['fingerprint'];

        // Between the dialog and the click, the closing e-mail left.
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('closing'), $this->currentCampaignKey());

        $html = (string) $this->controller->save(
            $this->post('/config/reinscription', ['is_open' => '0', 'plan_fingerprint' => $fingerprint]),
            []
        )->getBody();

        $this->assertTrue($this->campaign->isOpen(), 'nothing written');
        $this->assertStringContainsString('La situation a changé', $html);
        $this->assertStringContainsString("L'e-mail de clôture de cette campagne est déjà parti", html_entity_decode($html, ENT_QUOTES));
    }

    public function testASaveThatChangesNothingAsksNothingAndSaysSo(): void
    {
        $preview = $this->preview([]);
        $this->assertFalse($preview['changed']);

        $this->controller->save($this->post('/config/reinscription', []), []);

        $this->assertSame('Aucun changement à enregistrer.', $this->flash('warning'));
    }

    /**
     * **One line per row of the maquette's matrix**: the dialog announces
     * exactly what the save then does — no e-mail announced that does not
     * leave, none leaving that was not announced.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: ?string, 3: string}>
     */
    public static function matrix(): array
    {
        return [
            'opening between two campaigns' => ['2026-10-04 10:35', ['is_open' => '1'], 'opening', ''],
            'opening date of today' => ['2027-04-20 10:00', [ReenrollmentCampaignService::SETTING_OPEN_AT => '04-20'], 'opening', ''],
            'closing a campaign in progress' => ['2027-04-20 10:00', ['open' => '1', 'is_open' => '0'], 'closing', ''],
            'closing with the e-mails off' => ['2027-04-20 10:00', ['open' => '1', 'is_open' => '0', ReenrollmentCampaignService::SETTING_EMAILS_ENABLED => '0'], null, 'désactivés'],
            'opening before the opening date' => ['2027-02-20 09:00', ['is_open' => '1'], 'opening', ''],
            'changing a reminder' => ['2027-04-20 10:00', [ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => '10'], null, 'ne change que des réglages'],
            'switching the e-mails off' => ['2027-04-20 10:00', [ReenrollmentCampaignService::SETTING_EMAILS_ENABLED => '0'], null, 'Plus aucun e-mail ne partira'],
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function testTheDialogAnnouncesExactlyWhatTheSaveDoes(string $now, array $fields, ?string $type, string $reason): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        if (($fields['open'] ?? '') === '1') {
            $this->campaign->open();
        }
        unset($fields['open']);
        $controller = $this->controllerAt(new \DateTimeImmutable($now));

        $dialog = $this->preview($fields, $controller)['dialog'];
        $this->save($fields, $controller);
        $queued = array_map(
            static fn (array $row): string => (string) json_decode((string) $row['payload'], true)['type'],
            $this->queued()
        );

        if ($type === null) {
            $this->assertNull($dialog['mail']);
            $this->assertStringContainsString($reason, $dialog['none']['reason']);
            $this->assertSame('Enregistrer', $dialog['confirm_label']);
            $this->assertSame([], $queued);
        } else {
            $this->assertSame('1 e-mail va partir', $dialog['mail']['headline']);
            $this->assertSame('Enregistrer et envoyer', $dialog['confirm_label']);
            $this->assertSame([$type], $queued);
        }
    }

    /**
     * The dialog names the campaign — the year and its dates (D6): a
     * « 2027-2028 » can no longer sit beside a 2026 date.
     */
    public function testTheDialogNamesTheCampaignItOpens(): void
    {
        $dialog = $this->preview(['is_open' => '1'], $this->controllerAt(new \DateTimeImmutable('2026-10-04 10:35')))['dialog'];

        $this->assertSame('Ouvre la campagne de réinscription pour 2027-2028', $dialog['campaign']['title']);
        $this->assertStringContainsString('Fermeture le 15/05/2027, rappels le 01/05/2027 et le 13/05/2027.', $dialog['campaign']['detail']);
        $this->assertStringContainsString("restera ouverte jusqu'au 15/05/2027, soit plus de 7 mois", $dialog['campaign']['detail']);
        $this->assertContains('Campagne : fermée → ouverte', $dialog['changes']);
    }

    /**
     * After the save, the page says what left and what did not (D8).
     */
    public function testAfterTheSaveThePageSaysWhatLeftAndWhatDidNot(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->save(['is_open' => '1']);
        $this->assertSame(
            "Enregistré. L'e-mail d'ouverture est programmé pour 1 famille : il part dans quelques minutes.",
            $this->flash('success')
        );

        $this->save(['is_open' => '0']);
        $this->save(['is_open' => '1']);
        $this->assertSame(
            "Enregistré. Aucun e-mail n'est parti : l'e-mail d'ouverture de cette campagne est déjà parti : la rouvrir n'écrit à personne.",
            $this->flash('success')
        );
    }

    public function testAnOpeningDateOfTodayConfirmedOpensNowAndQueuesTheOpeningEmailOnce(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->save($this->openingToday());

        $key = $this->todaysCampaignKey();
        $this->assertTrue($this->campaign->isOpen(), 'opened now, not at the next hourly pass');
        $this->assertTrue(
            $this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_OPENED, $key),
            'through the marker the clock reads, so it never opens it a second time'
        );
        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $this->assertSame(ReenrollmentCampaignService::EMAIL_OPENING, json_decode((string) $queued[0]['payload'], true)['type']);
    }

    public function testWithTheEmailsOffAnOpeningWritesToNobody(): void
    {
        $this->save([ReenrollmentCampaignService::SETTING_EMAILS_ENABLED => '0'] + $this->openingToday());

        $this->assertTrue($this->campaign->isOpen());
        $this->assertSame([], $this->queued());
    }

    public function testAnOpeningIsNeverWrittenUnasked(): void
    {
        $this->saveUnconfirmed(['is_open' => '1']);

        $this->assertFalse($this->campaign->isOpen(), 'no yes, no opening');
        $this->assertSame([], $this->queued());
    }

    public function testAReminderIsRefusedWhileTheEmailsAreOff(): void
    {
        $this->campaign->open();
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');

        $this->remind();

        $this->assertSame([], $this->queued());
        $this->assertStringContainsString('désactivés', $this->flash('error'));
    }

    public function testAManualReminderCarriesItsOwnOccurrence(): void
    {
        $this->campaign->open();

        $this->remind();

        $queued = $this->queued();
        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertNotEmpty($payload['occurrence']);
        $this->assertStringStartsWith('manual:', (string) $queued[0]['reference']);
        $this->assertStringEndsWith((string) $payload['occurrence'], (string) $queued[0]['reference']);
    }

    public function testTheReminderQuestionSaysAnEmailLeavesAndWhereTheAutomaticOnesStand(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->campaign->open();

        $body = html_entity_decode($this->dashboard(), ENT_QUOTES);

        $this->assertStringContainsString("1 e-mail va partir : une relance à la famille qui n'a pas encore répondu.", $body);
        $this->assertStringContainsString("Aucun rappel automatique n'a encore été envoyé.", $body);
        $this->assertStringContainsString('Prochain rappel automatique prévu le 01/05/2027.', $body);
    }

    /**
     * **The dialog never contradicts the box** (issue #796, D11): it said
     * « Aucun autre rappel automatique n'est prévu » while the settings
     * planned two, counted from a campaign already over.
     */
    public function testTheReminderQuestionReadsTheSameStepsAsTheBox(): void
    {
        $this->campaign->open();
        $this->campaign->markDone(
            ReenrollmentCampaignService::emailMarker('reminder_1'),
            '2027-05-15',
            new \DateTimeImmutable('2027-05-01 08:02')
        );

        $body = html_entity_decode($this->dashboard($this->controllerAt(new \DateTimeImmutable('2027-05-05 10:00'))), ENT_QUOTES);

        $this->assertStringContainsString('Envoyé le 01/05/2027 à 08:02', $body);
        $this->assertStringContainsString('Dernier rappel automatique envoyé le 01/05/2027.', $body);
        $this->assertStringContainsString('prévu le 13/05/2027', $body);
        $this->assertStringContainsString('Prochain rappel automatique prévu le 13/05/2027.', $body);
    }

    public function testWithTheEmailsOffTheReminderButtonIsDisabledAndSaysWhy(): void
    {
        $this->campaign->open();
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');

        $body = $this->controller->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('action="/config/reinscription/relance"', $body);
        $this->assertStringContainsString('Les e-mails de la campagne sont désactivés', $body);
        $this->assertStringContainsString('href="/config/reinscription/reglages"', $body);
        $this->assertSame(4, substr_count($body, 'Désactivé<'), 'every step says « Désactivé »');
    }

    // ── the dashboard and its five step states (issue #796, IT-04) ────

    /**
     * The eight situations of the maquette: the box always describes the
     * target year's campaign — never one already over — and says the
     * right thing about each step.
     *
     * @return array<string, array{0: string, 1: callable(self): void, 2: list<string>, 3: list<string>}>
     */
    public static function situations(): array
    {
        $opening = static fn (string $at): callable => static function (self $t) use ($at): void {
            $t->campaign->open();
            $t->campaign->markDone(ReenrollmentCampaignService::emailMarker('opening'), '2027-05-15', new \DateTimeImmutable($at));
        };

        return [
            'before the opening' => ['2027-02-20 10:00', static fn (self $t) => null,
                ['Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027', 'prévu le 01/03/2027', 'Fermée'], ['Pas envoyé']],
            'between two campaigns' => ['2026-10-04 10:00', static fn (self $t) => null,
                ['Campagne pour 2027-2028 : du 01/03/2027 au 15/05/2027', 'Campagne précédente, pour 2026-2027 : clôturée le 15/05/2026.'], ['2026-2027 : du']],
            'opened by hand' => ['2026-10-04 11:00', $opening('2026-10-04 10:35'),
                ['ouverte le 04/10/2026, fermeture le 15/05/2027', '(ouverte à la main)', 'Ouverte à la main, avant la date prévue.'], ['Campagne précédente']],
            'campaign in progress' => ['2027-04-20 10:00', $opening('2027-03-01 08:04'),
                ['Envoyé le 01/03/2027 à 08:04', 'prévu le 01/05/2027', 'Clôture prévue le 15/05/2027.'], ['Pas envoyé']],
            'missed date' => ['2027-05-03 10:00', $opening('2027-03-01 08:04'),
                ['Pas envoyé', 'prévu le 01/05/2027, date passée'], []],
            'skipped reminder' => ['2027-04-20 10:00', static function (self $t) use ($opening): void {
                $opening('2027-03-01 08:04')($t);
                $t->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '90', 'registration');
            }, ['Sauté', "le 14/02/2027 tombe avant l'ouverture"], []],
            'e-mails off' => ['2027-04-20 10:00', static function (self $t): void {
                $t->campaign->open();
                $t->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');
            }, ['Désactivé'], ['prévu le']],
            'after the close' => ['2027-06-12 10:00', static function (self $t): void {
                foreach (['opening', 'reminder_1', 'reminder_2', 'closing'] as $type) {
                    $t->campaign->markDone(ReenrollmentCampaignService::emailMarker($type), '2027-05-15', new \DateTimeImmutable('2027-05-15 23:30'));
                }
            }, ['Campagne pour 2027-2028', 'Envoyé le 15/05/2027 à 23:30', 'Fermée'], ['Clôture prévue', 'prévu le']],
        ];
    }

    /**
     * @param callable(self): void $arrange
     * @param list<string> $present
     * @param list<string> $absent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('situations')]
    public function testTheDashboardDescribesTheTargetYearsCampaignInEverySituation(
        string $now,
        callable $arrange,
        array $present,
        array $absent
    ): void {
        $arrange($this);

        // As read on screen: HTML entities decoded, whitespace collapsed.
        $body = (string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode($this->dashboard($this->controllerAt(new \DateTimeImmutable($now))), ENT_QUOTES)
        );

        foreach ($present as $text) {
            $this->assertStringContainsString($text, $body);
        }
        foreach ($absent as $text) {
            $this->assertStringNotContainsString($text, $body);
        }
    }

    /**
     * The two sub-pages share one rail, and the breadcrumb carries every
     * level (D9) — the manifest's own breadcrumb, as the router serves it.
     */
    public function testBothPagesCarryTheRailAndTheFullBreadcrumb(): void
    {
        $this->assertStringContainsString('reenrollment-page-picker', $this->dashboard());
        $this->assertStringContainsString('reenrollment-page-picker', $this->settingsPage());

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'), true);
        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === '/config/reinscription/reglages' && $route['method'] === 'GET') {
                $this->assertSame('Réglages', $route['breadcrumb']['label']);
                $this->assertSame(["Espace chefs d'U"], $route['breadcrumb']['parents']);
                $this->assertSame([['label' => 'Réinscriptions', 'path' => '/config/reinscription']], $route['breadcrumb']['ancestors']);

                return;
            }
        }
        $this->fail('GET /config/reinscription/reglages is not declared.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function newRoutes(): array
    {
        return [
            'the settings page' => ['GET', '/config/reinscription/reglages'],
            'saving the settings' => ['POST', '/config/reinscription/reglages'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newRoutes')]
    public function testTheNewRoutesAreAllowedAtAdminAndRefusedToAChief(string $method, string $path): void
    {
        AuthSession::login(1, 'chef@example.be', 'admin');
        $allowed = $this->dispatch($method, $path);
        $this->assertNotSame(403, $allowed->getStatusCode(), $allowed->getBody());

        AuthSession::login(2, 'animateur@example.be', 'chief');
        $this->assertSame(403, $this->dispatch($method, $path)->getStatusCode());
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
     * A save as the chief makes it: the plan asked first, then the form
     * posted with the plan's fingerprint (issue #796).
     *
     * @param array<string, string> $fields
     */
    private function save(array $fields, ?ReenrollmentConfigController $controller = null): void
    {
        $controller ??= $this->controller;
        $fingerprint = (string) ($this->preview($fields, $controller)['fingerprint'] ?? '');
        $controller->save($this->post('/config/reinscription', $fields + ['plan_fingerprint' => $fingerprint]), []);
    }

    /**
     * A save posted without the plan's fingerprint — what a browser
     * without the script sends. Answers with the page it gets back.
     *
     * @param array<string, string> $fields
     */
    private function saveUnconfirmed(array $fields): string
    {
        return (string) $this->controller->save($this->post('/config/reinscription', $fields), [])->getBody();
    }

    private function dashboard(?ReenrollmentConfigController $controller = null): string
    {
        return (string) ($controller ?? $this->controller)
            ->index(new Request('GET', '/config/reinscription', [], [], [], []), [])->getBody();
    }

    private function settingsPage(): string
    {
        return (string) $this->controller
            ->settings(new Request('GET', '/config/reinscription/reglages', [], [], [], []), [])->getBody();
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

    /**
     * A window that opens TODAY (self::NOW) and closes on 31 December,
     * without straddling a new year.
     *
     * @return array<string, string>
     */
    private function openingToday(): array
    {
        return [
            ReenrollmentCampaignService::SETTING_OPEN_AT => (new \DateTimeImmutable(self::NOW))->format('m-d'),
            ReenrollmentCampaignService::SETTING_CLOSE_AT => '12-31',
            ReenrollmentCampaignService::SETTING_EMAILS_ENABLED => '1',
        ];
    }

    private function todaysCampaignKey(): string
    {
        return (new \DateTimeImmutable(self::NOW))->format('Y') . '-12-31';
    }

    // ── the RBAC boundary of the preview route ───────────────────────

    /**
     * Dispatched through the router with the role_min the manifest itself
     * declares, so the guard under test is the one that ships: allowed at
     * admin, refused one level below.
     */
    public function testThePreviewRouteIsAllowedAtAdminAndRefusedToAChief(): void
    {
        AuthSession::login(1, 'chef@example.be', 'admin');
        $allowed = $this->dispatchPreview();
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getBody());
        $this->assertTrue(json_decode($allowed->getBody(), true)['success']);

        AuthSession::login(2, 'animateur@example.be', 'chief');
        $this->assertSame(403, $this->dispatchPreview()->getStatusCode());
    }

    private function dispatch(string $method, string $path): \Core\Http\Response
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'), true);
        $router = new \Core\Http\Router();
        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === $method) {
                $router->addRoute($route['method'], $route['path'], $route['controller'], $route['action'], $route['role_min']);
            }
        }
        $configFile = sys_get_temp_dir() . '/test_reenrollment_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $fc = new \Core\Http\FrontController($router, $this->twig, new \Core\Config\AppConfig($configFile));
        $fc->registerController(ReenrollmentConfigController::class, $this->controller);

        $token = CsrfGuard::generateToken();

        return $fc->handle(new \Tests\RequestWithInput(
            $method,
            $path,
            [],
            $method === 'POST' ? ['_csrf_token' => $token] : [],
            ['HTTP_X_CSRF_TOKEN' => $token],
            [],
            ''
        ));
    }

    private function dispatchPreview(): \Core\Http\Response
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'), true);
        $route = null;
        foreach ($manifest['routes'] as $candidate) {
            if ($candidate['path'] === '/config/reinscription/apercu') {
                $route = $candidate;
            }
        }
        $this->assertNotNull($route, 'the manifest declares the preview route');

        $router = new \Core\Http\Router();
        $router->addRoute($route['method'], $route['path'], $route['controller'], $route['action'], $route['role_min']);
        $configFile = sys_get_temp_dir() . '/test_reenrollment_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $fc = new \Core\Http\FrontController($router, $this->twig, new \Core\Config\AppConfig($configFile));
        $fc->registerController(ReenrollmentConfigController::class, $this->controller);

        $token = CsrfGuard::generateToken();
        return $fc->handle(new \Tests\RequestWithInput(
            'POST',
            '/config/reinscription/apercu',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
            [],
            (string) json_encode(['_csrf_token' => $token])
        ));
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function preview(array $fields, ?ReenrollmentConfigController $controller = null): array
    {
        $response = ($controller ?? $this->controller)->preview(
            new \Tests\RequestWithInput(
                'POST',
                '/config/reinscription/apercu',
                [],
                [],
                [],
                [],
                (string) json_encode($fields + ['_csrf_token' => CsrfGuard::generateToken()])
            ),
            []
        );

        return (array) json_decode($response->getBody(), true);
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
