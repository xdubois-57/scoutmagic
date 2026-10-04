<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentSavePlan;
use Modules\Registration\Service\ReenrollmentSavePlanner;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The one plan of what saving the reenrollment configuration writes to
 * families (issue #796, D1) — the matrix of the roadmap, for every line
 * that does not wait on the « campaign per target year » of IT-02.
 *
 * The family counts are a stand-in (41 families in all, 27 silent): who
 * the recipients are is ReenrollmentRecipientService's business, pinned in
 * ReenrollmentCampaignServiceTest. What is pinned here is which e-mail the
 * plan announces, for which campaign, and why none when none.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReenrollmentSavePlannerTest extends TestCase
{
    private SettingService $settingService;
    private ReenrollmentCampaignService $campaign;
    private ReenrollmentSavePlanner $planner;
    /** @var list<string> the e-mails queued and not finished, as « type:campaign » */
    private array $inFlight = [];

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $currentYearId = RegistrationTestHelper::insertScoutYear($pdo, '2026-2027', '2026-09-01', '2027-08-31');
        RegistrationTestHelper::insertScoutYear($pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $this->settingService = new SettingService(new SettingRepository($pdo));
        RegistrationTestHelper::registerManifestSettings($this->settingService);
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '03-01', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '05-15', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '14', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS, '2', 'registration');
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId, 'text', 'Année publique', 'Test.');
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);

        $connection = Connection::withPdo($pdo);
        $scoutYearService = new ScoutYearService($pdo);
        $this->campaign = new ReenrollmentCampaignService(
            $this->settingService,
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($pdo)),
            $scoutYearService,
            new ReenrollmentRepository($pdo, $encryption),
            new PassageService(
                new \Modules\Registration\Repository\PassageRosterRepository($pdo, $encryption),
                $encryption,
                new SectionService(
                    new SectionRepository($connection),
                    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($pdo))
                ),
                new SectionTransferRepository($pdo),
                new RegistrationRequestRepository($pdo, $encryption),
                new AgeBracketRepository($pdo)
            )
        );

        $this->planner = new ReenrollmentSavePlanner(
            $this->campaign,
            $this->settingService,
            static fn (bool $silentOnly): int => $silentOnly ? 27 : 41,
            fn (string $type, string $key): bool => in_array($type . ':' . $key, $this->inFlight, true)
        );
    }

    // ── closing by the switch ─────────────────────────────────────────

    public function testClosingARunningCampaignSendsTheClosingEmailToTheSilentFamilies(): void
    {
        $this->campaign->open();

        $plan = $this->plan(['is_open' => false], '2027-04-20 10:00');

        $this->assertSame(['campaign' => '2027-05-15'], $plan->closing);
        $this->assertSame(
            [['type' => 'closing', 'campaign' => '2027-05-15', 'families' => 27, 'deferred' => false]],
            $plan->emails
        );
        $this->assertNull($plan->noEmailReason);
    }

    /**
     * The incident (issue #796): a switch still « on » in October. The
     * campaign is now the target year's, which has not begun: closing it
     * writes to nobody, and does not touch May's.
     */
    public function testClosingInOctoberACampaignThatHasNotBegunWritesToNobody(): void
    {
        $this->campaign->open();

        $plan = $this->plan(['is_open' => false], '2026-10-04 10:35');

        $this->assertSame(['campaign' => '2027-05-15'], $plan->closing);
        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_NOT_STARTED, $plan->noEmailReason);
    }

    /**
     * Opened by hand in October, the campaign has begun: closing it writes
     * the closing e-mail to whoever has not answered (maquette « ouverte à
     * la main »).
     */
    public function testClosingACampaignOpenedByHandInOctoberWritesTheClosingEmail(): void
    {
        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('opening'), '2027-05-15');

        $plan = $this->plan(['is_open' => false], '2026-10-04 11:00');

        $this->assertSame('closing', $plan->emails[0]['type'] ?? null);
        $this->assertSame('2027-05-15', $plan->emails[0]['campaign'] ?? null);
    }

    /**
     * And a campaign really over — closed in May, reopened in June for one
     * late family — is closed again without a second closing e-mail.
     */
    public function testClosingAgainAfterTheCloseWritesToNobody(): void
    {
        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('closing'), '2027-05-15');

        $plan = $this->plan(['is_open' => false], '2027-06-12 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_ALREADY_SENT, $plan->noEmailReason);
    }

    /**
     * Opening by hand in October opens the target year's campaign, says so,
     * and writes the opening e-mail (D3, D4).
     */
    public function testOpeningByHandInOctoberOpensTheTargetYearsCampaign(): void
    {
        $plan = $this->plan(['is_open' => true], '2026-10-04 10:35');

        $this->assertSame(['campaign' => '2027-05-15', 'scheduled' => false], $plan->opening);
        $this->assertSame(
            [['type' => 'opening', 'campaign' => '2027-05-15', 'families' => 41, 'deferred' => false]],
            $plan->emails
        );
    }

    public function testReopeningAfterTheCloseWritesNothingWhenTheOpeningEmailLeft(): void
    {
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('opening'), '2027-05-15');

        $plan = $this->plan(['is_open' => true], '2027-06-12 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_ALREADY_SENT, $plan->noEmailReason);
    }

    public function testClosingWithTheEmailsOffWritesToNobody(): void
    {
        $this->campaign->open();

        $plan = $this->plan(['is_open' => false, 'emails_enabled' => false], '2027-04-20 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_EMAILS_DISABLED, $plan->noEmailReason);
    }

    public function testClosingAgainOnceTheClosingEmailLeftWritesToNobody(): void
    {
        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('closing'), '2027-05-15');

        $plan = $this->plan(['is_open' => false], '2027-04-20 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_ALREADY_SENT, $plan->noEmailReason);
    }

    // ── opening ───────────────────────────────────────────────────────

    public function testAnOpeningDateOfTodayOpensNowAndWritesToEveryFamily(): void
    {
        $plan = $this->plan(['open_at' => '02-20'], '2027-02-20 09:00');

        $this->assertSame(['campaign' => '2027-05-15', 'scheduled' => true], $plan->opening);
        $this->assertSame(
            [['type' => 'opening', 'campaign' => '2027-05-15', 'families' => 41, 'deferred' => false]],
            $plan->emails
        );
    }

    public function testOpeningByHandBeforeTheOpeningDateWritesTheOpeningEmail(): void
    {
        $plan = $this->plan(['is_open' => true], '2027-02-20 09:00');

        $this->assertSame(['campaign' => '2027-05-15', 'scheduled' => false], $plan->opening);
        $this->assertSame('opening', $plan->emails[0]['type'] ?? null);
    }

    public function testReopeningACampaignWhoseOpeningEmailLeftWritesToNobody(): void
    {
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('opening'), '2027-05-15');

        $plan = $this->plan(['is_open' => true], '2027-04-20 10:00');

        $this->assertNotNull($plan->opening);
        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_ALREADY_SENT, $plan->noEmailReason);
    }

    // ── settings only ─────────────────────────────────────────────────

    public function testChangingAReminderDelaySendsNothing(): void
    {
        $plan = $this->plan(['reminder_1_days' => '10'], '2027-04-20 10:00');

        $this->assertSame([['setting' => 'reminder_1_days', 'from' => '14', 'to' => '10']], $plan->changes);
        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_SETTINGS_ONLY, $plan->noEmailReason);
    }

    public function testSwitchingTheEmailsOffSendsNothing(): void
    {
        $plan = $this->plan(['emails_enabled' => false], '2027-04-20 10:00');

        $this->assertTrue($plan->hasChanges());
        $this->assertSame([], $plan->emails);
    }

    public function testASaveThatChangesNothingSaysSo(): void
    {
        $plan = $this->plan([], '2027-04-20 10:00');

        $this->assertFalse($plan->hasChanges());
        $this->assertSame(ReenrollmentSavePlan::REASON_NO_CHANGE, $plan->noEmailReason);
    }

    // ── what a save makes due at the next hourly pass ─────────────────

    /**
     * Not sent by the save itself, but by the clock an hour later because
     * of it: the plan announces it all the same.
     */
    public function testAReminderDelayThatLandsOnTodayAnnouncesADeferredReminder(): void
    {
        $this->campaign->open();

        // 2027-05-15 minus 25 days is 2027-04-20, today.
        $plan = $this->plan(['is_open' => true, 'reminder_1_days' => '25'], '2027-04-20 10:00');

        $this->assertSame(
            [['type' => 'reminder_1', 'campaign' => '2027-05-15', 'families' => 27, 'deferred' => true]],
            $plan->emails
        );
    }

    public function testADeferredReminderAlreadySentSaysSoRatherThanSettingsOnly(): void
    {
        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('reminder_1'), '2027-05-15');

        $plan = $this->plan(['is_open' => true, 'reminder_1_days' => '25'], '2027-04-20 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_ALREADY_SENT, $plan->noEmailReason);
    }

    public function testTurningEmailsOnADayAReminderIsDueAnnouncesIt(): void
    {
        $this->campaign->open();
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '25', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');

        $plan = $this->plan(['is_open' => true, 'emails_enabled' => true], '2027-04-20 10:00');

        $this->assertSame(
            [['type' => 'reminder_1', 'campaign' => '2027-05-15', 'families' => 27, 'deferred' => true]],
            $plan->emails
        );
    }

    public function testClosingACampaignOpenedEarlyByHandWritesToItsFamilies(): void
    {
        // Opened by hand on 2027-02-01, a month before its date: keyed to
        // the coming close, 2027-05-15 — and so is its closing.
        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15');

        $plan = $this->plan(['is_open' => false], '2027-02-05 10:00');

        $this->assertSame(
            [['type' => 'closing', 'campaign' => '2027-05-15', 'families' => 27, 'deferred' => false]],
            $plan->emails
        );
    }

    public function testClosingBeforeTheOpeningEmailHasFinishedStillWritesToItsFamilies(): void
    {
        // The switch just opened the campaign early: its opening e-mail is
        // queued and has not written its marker yet.
        $this->campaign->open();
        $this->inFlight = ['opening:2027-05-15'];

        $plan = $this->plan(['is_open' => false], '2027-02-05 10:00');

        $this->assertSame(
            [['type' => 'closing', 'campaign' => '2027-05-15', 'families' => 27, 'deferred' => false]],
            $plan->emails
        );
    }

    public function testASwitchLeftOnPastItsWindowWritesToNobodyWhateverTheDates(): void
    {
        // The next opening (2027-01-03) is nearer than the last close
        // (2026-05-15), yet nothing opened that campaign: it has not
        // started, so closing the switch tells nobody it has closed.
        $this->campaign->open();

        $plan = $this->plan(['is_open' => false, 'open_at' => '01-03'], '2026-10-04 10:00');

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_NOT_STARTED, $plan->noEmailReason);
    }

    public function testClosingWhileSavingAnOpeningDateThatPutsTheCampaignInTheFutureWritesToNobody(): void
    {
        // Stored 10-01: that campaign opened on 2026-10-01. The save moves
        // the window to 03-01 → 05-15 and closes the switch: by the dates
        // being saved the campaign has not begun, so nobody is told it is over.
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '10-01', 'registration');
        $this->campaign->open();

        $plan = $this->plan(
            ['is_open' => false, 'open_at' => '03-01', 'close_at' => '05-15'],
            '2027-01-10 10:00'
        );

        $this->assertSame([], $plan->emails);
        $this->assertSame(ReenrollmentSavePlan::REASON_NOT_STARTED, $plan->noEmailReason);
    }

    public function testAReminderAlreadyDueBeforeTheSaveIsNotThisSavesDoing(): void
    {
        $this->campaign->open();
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '25', 'registration');

        $plan = $this->plan(['is_open' => true, 'reminder_2_days' => '3'], '2027-04-20 10:00');

        $this->assertSame([], $plan->emails);
    }

    public function testMovingTheCloseDateToTodayAnnouncesADeferredClosing(): void
    {
        $this->campaign->open();

        $plan = $this->plan(['is_open' => true, 'close_at' => '04-20'], '2027-04-20 10:00');

        $this->assertSame(
            [['type' => 'closing', 'campaign' => '2027-04-20', 'families' => 27, 'deferred' => true]],
            $plan->emails
        );
    }

    public function testTheFingerprintFollowsWhatTheChiefWouldBeAskedAbout(): void
    {
        $this->campaign->open();
        $first = $this->plan(['is_open' => false], '2027-04-20 10:00');
        $same = $this->plan(['is_open' => false], '2027-04-20 10:00');
        $this->campaign->markDone(ReenrollmentCampaignService::emailMarker('closing'), '2027-05-15');
        $moved = $this->plan(['is_open' => false], '2027-04-20 10:00');

        $this->assertSame($first->fingerprint(), $same->fingerprint());
        $this->assertNotSame($first->fingerprint(), $moved->fingerprint());
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * The form as save() reads it: by default exactly what is stored.
     *
     * @param array<string, string|bool|null> $fields
     */
    private function plan(array $fields, string $now): ReenrollmentSavePlan
    {
        /** @var array{open_at: ?string, close_at: ?string, reminder_1_days: ?string, reminder_2_days: ?string, is_open: bool, emails_enabled: bool} $submitted */
        $submitted = $fields + [
            'open_at' => null,
            'close_at' => null,
            'reminder_1_days' => null,
            'reminder_2_days' => null,
            'is_open' => $this->campaign->isOpen(),
            'emails_enabled' => $this->campaign->emailsEnabled(),
        ];

        return $this->planner->plan($submitted, new \DateTimeImmutable($now));
    }
}
