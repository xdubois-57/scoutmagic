<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentRecipientService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * The campaign's clock and its address book.
 *
 * `poor_mans_cron` only advances on page visits, so every one of these
 * decisions may be asked many times in a day or not at all. What is
 * pinned here is therefore idempotence first, and the four rules the
 * roadmap singles out:
 *
 * - two runs on the same day open the campaign once;
 * - a reminder whose computed date falls before the opening is skipped
 *   outright, never sent late;
 * - a family who has answered for ALL their children is owed nothing;
 * - a family of three receives ONE e-mail, not three.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReenrollmentCampaignServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private ReenrollmentCampaignService $campaign;
    private ReenrollmentRecipientService $recipients;
    private ReenrollmentRepository $repository;
    private int $currentYearId;
    private int $targetYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
        $stmt->execute(['LOUV1', $branchId, 'Louveteaux A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        // **From the manifest, never by hand.** Registering the settings
        // this test happens to need is what hid the bug it now catches:
        // `setInternal()` refuses a key no manifest declares, and the two
        // campaign markers were declared nowhere — so every automatic
        // opening, closing and email batch threw at the moment it tried
        // to record that it had run, in production only, while this
        // fixture quietly supplied the rows. Reading `module.json` is what
        // makes the fixture unable to lie about it.
        RegistrationTestHelper::registerManifestSettings($this->settingService);

        // Only the campaign's own dates are set here, since the manifest
        // ships them empty.
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
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
);
        $scoutYearService = new ScoutYearService($this->pdo);
        $requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $passageService = new PassageService(
            new \Modules\Registration\Repository\PassageRosterRepository($this->pdo, $this->encryption),
            $this->encryption,
            $sectionService,
            new SectionTransferRepository($this->pdo),
            $requestRepository,
            new AgeBracketRepository($this->pdo)
        );

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->campaign = new ReenrollmentCampaignService(
            $this->settingService,
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            $scoutYearService,
            $this->repository,
            $passageService
        );
        $this->recipients = new ReenrollmentRecipientService(
            new \Modules\Registration\Repository\PassageRosterRepository($this->pdo, $this->encryption),
            $this->repository,
            $passageService
        );
    }

    // ── the window ────────────────────────────────────────────────────

    public function testTheOpeningIsDueOnItsDayAndNotTheDayAfter(): void
    {
        $this->assertSame('2027-05-15', $this->campaign->openingDueToday(new \DateTimeImmutable('2027-03-01')));
        $this->assertNull(
            $this->campaign->openingDueToday(new \DateTimeImmutable('2027-03-02')),
            'A missed date is missed: a campaign that opened four days late would announce a deadline closer than it says.'
        );
    }

    /**
     * **Every marker this campaign writes is declared in `module.json`.**
     *
     * `SettingService::setInternal()` refuses a key no manifest declares,
     * and `ModuleManager::loadModule()` registers a module's settings from
     * its manifest and from nowhere else. The six markers below were
     * declared nowhere, so on any real installation:
     *
     *  - the automatic opening set the campaign open, then threw recording
     *    that it had — leaving the next scheduler tick to open it again,
     *    log it again and queue the opening emails again;
     *  - a batch of emails went out in full, then threw before marking the
     *    type done — so the next run wrote to every silent family a second
     *    time, and a third.
     *
     * Nothing caught it because every test registered by hand the keys it
     * needed. The fixture now reads the manifest
     * (`RegistrationTestHelper::registerManifestSettings()`), which is why
     * this assertion can be about writing rather than about a list.
     *
     * The keys are exercised, not compared to a hard-coded list: four of
     * the six are built at runtime by `emailMarker()` and so are invisible
     * to any grep over the source (#443).
     */
    public function testEveryCampaignMarkerCanActuallyBeWritten(): void
    {
        $markers = [
            ReenrollmentCampaignService::MARKER_OPENED,
            ReenrollmentCampaignService::MARKER_CLOSED,
        ];
        foreach ([
            ReenrollmentCampaignService::EMAIL_OPENING,
            ReenrollmentCampaignService::EMAIL_REMINDER_1,
            ReenrollmentCampaignService::EMAIL_REMINDER_2,
            ReenrollmentCampaignService::EMAIL_CLOSING,
        ] as $type) {
            $markers[] = ReenrollmentCampaignService::emailMarker($type);
        }

        foreach ($markers as $marker) {
            $this->campaign->markDone($marker, '2027-05-15');

            $this->assertTrue(
                $this->campaign->alreadyDone($marker, '2027-05-15'),
                $marker . ' was written but does not read back'
            );
            $this->assertNotNull(
                $this->campaign->doneAt($marker, '2027-05-15'),
                $marker . ' recorded no moment'
            );
        }
    }

    /**
     * And the moment is the moment, not the campaign.
     *
     * The marker holds a campaign key — the campaign's CLOSING date — under
     * a setting named `..._sent_on`, which reads like a send date and is
     * not one: a reminder that goes out in March for a campaign closing in
     * May stores `2027-05-15`. Showing that under the word « envoyé » is a
     * wrong answer, which is worse than none, so the moment lives in its
     * own setting and only `markDone()` writes it.
     */
    public function testTheMomentIsWhenItRanAndNotWhenTheCampaignCloses(): void
    {
        $ranAt = new \DateTimeImmutable('2027-03-02 09:14:00');
        $marker = ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1);

        $this->campaign->markDone($marker, '2027-05-15', $ranAt);

        $this->assertSame(
            '2027-03-02 09:14:00',
            $this->campaign->doneAt($marker, '2027-05-15')?->format('Y-m-d H:i:s')
        );
    }

    public function testAMarkerThatNeverRanHasNoMoment(): void
    {
        $this->assertNull(
            $this->campaign->doneAt(ReenrollmentCampaignService::MARKER_CLOSED, '2027-05-15')
        );
    }

    /**
     * **Last year's send date is not this year's news.**
     *
     * `markDone()` overwrites the moment and never clears it, so a marker
     * left from the previous campaign still carries its timestamp. Reading
     * it unconditionally put « Envoyé le … » under an email that had not
     * gone out for the campaign now open — the wrong answer this setting
     * was added to avoid, one campaign further along.
     *
     * So the moment is gated on exactly what `alreadyDone()` is gated on.
     */
    public function testAMomentFromAnotherCampaignIsNotThisCampaignsMoment(): void
    {
        $marker = ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1);

        $this->campaign->markDone($marker, '2027-05-15', new \DateTimeImmutable('2027-03-02 09:14:00'));

        $this->assertNotNull($this->campaign->doneAt($marker, '2027-05-15'));
        $this->assertNull(
            $this->campaign->doneAt($marker, '2028-05-15'),
            "the next campaign's page must not show the previous one's send date"
        );
    }

    /**
     * And the marker and its moment are one decision: a half-written pair
     * would let the next pass read the campaign as finished with no date
     * to show for it.
     */
    public function testTheMarkerAndItsMomentAreWrittenTogether(): void
    {
        $marker = ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_CLOSING);

        $this->campaign->markDone($marker, '2027-05-15');

        $this->assertTrue($this->campaign->alreadyDone($marker, '2027-05-15'));
        $this->assertNotNull($this->campaign->doneAt($marker, '2027-05-15'));
    }

    public function testOpeningTwiceOnTheSameDayOpensOnce(): void
    {
        $day = new \DateTimeImmutable('2027-03-01');

        $key = $this->campaign->openingDueToday($day);
        $this->assertNotNull($key);
        $this->assertFalse($this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_OPENED, $key));

        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, $key);

        // The second run of the same day asks the same question and gets
        // the answer that stops it.
        $this->assertSame($key, $this->campaign->openingDueToday($day));
        $this->assertTrue($this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_OPENED, $key));
    }

    public function testTheClosingIsDueOnItsOwnDay(): void
    {
        $this->assertSame('2027-05-15', $this->campaign->closingDueToday(new \DateTimeImmutable('2027-05-15')));
        $this->assertNull($this->campaign->closingDueToday(new \DateTimeImmutable('2027-05-14')));
    }

    /**
     * **One campaign: the target year's** (issue #796, D3). The public year
     * is 2026-2027, so the families are asked about 2027-2028, whose
     * campaign closes on 2027-05-15 — before its dates, during them and
     * after them, whatever today is.
     *
     * @return array<string, array{0: string}>
     */
    public static function datesOfTheYear(): array
    {
        return [
            'the day the public year is 2026-2027' => ['2026-09-01'],
            'in October, between two campaigns' => ['2026-10-04'],
            'before the opening' => ['2027-02-20'],
            'during the campaign' => ['2027-04-20'],
            'the day it closes' => ['2027-05-15'],
            'after the close' => ['2027-06-12'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('datesOfTheYear')]
    public function testTheCampaignIsTheTargetYearsWhateverTheDay(string $today): void
    {
        $this->assertSame('2027-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable($today)));
    }

    public function testTheNextPublicYearMovesToTheNextCampaign(): void
    {
        $this->usePublicYear('2027-2028');

        $this->assertSame('2028-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-08-20')));
    }

    /**
     * **The day the public year changes** moves the campaign with it, even a
     * running one: everything that reads the families' answers follows the
     * same target year, so the campaign cannot outlive its own.
     */
    public function testAPublicYearChangedMidCampaignMovesOnToTheNextCampaign(): void
    {
        $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15');
        $this->usePublicYear('2027-2028');

        $this->assertSame('2028-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-04-20')));
        $this->assertSame('2028-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-05-16')));
    }

    public function testACampaignIsJudgedStartedByTheOpeningDateBeingSaved(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '10-01', 'registration');
        $now = new \DateTimeImmutable('2027-01-10');

        // Stored 10-01: opened since 2026-10-01. Being saved as 03-01: not yet.
        $this->assertTrue($this->campaign->hasStarted('2027-05-15', $now));
        $this->assertFalse($this->campaign->hasStarted('2027-05-15', $now, '03-01'));
    }

    /**
     * A window that straddles new year (November → February): the campaign
     * of 2027-2028 closes in February 2027 and opens in November 2026.
     */
    public function testAWindowAcrossNewYearOpensTheYearBeforeItCloses(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '11-01', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '02-15', 'registration');

        $this->assertSame('2027-02-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2026-10-04')));
        $this->assertSame('2027-02-15', $this->campaign->openingDueToday(new \DateTimeImmutable('2026-11-01')));
        $this->assertNull($this->campaign->openingDueToday(new \DateTimeImmutable('2027-11-01')));
        $this->assertSame('2027-02-15', $this->campaign->closingDueToday(new \DateTimeImmutable('2027-02-15')));
        $this->assertSame(
            '2027-02-01',
            $this->campaign->reminderDate(ReenrollmentCampaignService::EMAIL_REMINDER_1, new \DateTimeImmutable('2026-12-01'))
                ?->format('Y-m-d')
        );
    }

    /**
     * A window wholly in autumn (open 10-01, close 12-15) closes before the
     * year it asks about begins: the autumn before, never a year ahead.
     */
    public function testAnAutumnWindowOpensAndClosesInTheAutumnBeforeTheTargetYear(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '10-01', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '12-15', 'registration');

        $this->usePublicYear('2026-2027');
        $this->assertSame('2026-12-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2026-10-01')));
        $this->assertSame('2026-12-15', $this->campaign->openingDueToday(new \DateTimeImmutable('2026-10-01')));
        $this->assertSame('2026-12-15', $this->campaign->closingDueToday(new \DateTimeImmutable('2026-12-15')));
        $this->assertSame('2027-2028', $this->campaign->targetLabelOf('2026-12-15'));

        // The year after, once the public year has moved on.
        $this->usePublicYear('2027-2028');
        $this->assertSame('2027-12-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-10-01')));
        $this->assertSame('2027-12-15', $this->campaign->openingDueToday(new \DateTimeImmutable('2027-10-01')));
    }

    public function testTheYearACampaignAsksAboutIsTheOneStartingTheYearItCloses(): void
    {
        $this->assertSame('2027-2028', $this->campaign->targetLabelOf('2027-05-15'));
        // A February close belongs to a window opened the November before.
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '11-01', 'registration');
        $this->assertSame('2027-2028', $this->campaign->targetLabelOf('2027-02-15'));
    }

    private function usePublicYear(string $label): void
    {
        $id = (new ScoutYearService($this->pdo))->ensureYear($label);
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $id);
    }

    public function testAReminderOfAWindowStraddlingNewYearIsNotSkipped(): void
    {
        $close = new \DateTimeImmutable('2027-02-15');

        // Open 2026-11-01, close 2027-02-15: 14 days before is 2027-02-01.
        $due = ReenrollmentCampaignService::reminderDueOn($close, '11-01', '14');
        $this->assertSame('2027-02-01', $due?->format('Y-m-d'));

        // 120 days before the close falls on 2026-10-18, before the opening:
        // skipped, never sent late.
        $this->assertNull(ReenrollmentCampaignService::reminderDueOn($close, '11-01', '120'));
    }

    public function testTheManualSwitchWorksBothWaysAndTouchesNoMarker(): void
    {
        $this->campaign->open();
        $this->assertTrue($this->campaign->isOpen());
        $this->assertFalse($this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15'));

        $this->campaign->close();
        $this->assertFalse($this->campaign->isOpen());
        $this->assertFalse($this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_CLOSED, '2027-05-15'));
    }

    // ── what a save would set off (issue #732) ───────────────────────

    public function testTheEmailsAreOnUnlessSwitchedOff(): void
    {
        $this->assertTrue($this->campaign->emailsEnabled(), 'on by default');

        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');

        $this->assertFalse($this->campaign->emailsEnabled());
    }

    public function testAnOpeningDateOfTodayOpensTheCampaignOnSave(): void
    {
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => true],
            $this->campaign->openingOnSave('03-01', '05-15', false, new \DateTimeImmutable('2027-03-01 10:00'))
        );
    }

    public function testAnOpeningDateInThePastOpensNothing(): void
    {
        $this->assertNull(
            $this->campaign->openingOnSave('02-20', '05-15', false, new \DateTimeImmutable('2027-03-01')),
            'a missed date is missed, on save as on the clock'
        );
    }

    public function testAnOpeningAlreadyAppliedIsNotAppliedAgain(): void
    {
        $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15');

        $this->assertNull(
            $this->campaign->openingOnSave('03-01', '05-15', false, new \DateTimeImmutable('2027-03-01'))
        );
    }

    public function testNothingOpensWhatIsAlreadyOpen(): void
    {
        $this->campaign->open();

        $this->assertNull($this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2027-03-01')));
    }

    public function testTheSwitchOpensTheCampaignInProgress(): void
    {
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => false],
            $this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2027-04-10'))
        );
    }

    public function testTheSwitchShortlyBeforeTheOpeningDateOpensTheNextCampaignEarly(): void
    {
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => false],
            $this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2027-02-20'))
        );
    }

    /**
     * No distance rule (issue #796, D3): after its close, the switch reopens
     * the same campaign — for a late family. Whether that writes to anybody
     * is Service\ReenrollmentSavePlanner's answer.
     */
    public function testTheSwitchAfterTheCloseReopensTheSameCampaign(): void
    {
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => false],
            $this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2027-05-20'))
        );
    }

    /**
     * And in October it opens the target year's campaign — never the one
     * that ended in May, whichever date is nearer (D4).
     */
    public function testTheSwitchInOctoberOpensTheTargetYearsCampaign(): void
    {
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => false],
            $this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2026-10-04'))
        );
        $this->assertSame(
            ['key' => '2027-05-15', 'scheduled' => false],
            $this->campaign->openingOnSave('03-01', '05-15', true, new \DateTimeImmutable('2026-10-08'))
        );
    }

    // ── where the automatic reminders stand (issue #732) ─────────────

    public function testBeforeAnyReminderTheNextOneIsTheFirst(): void
    {
        $reminders = $this->campaign->automaticReminders(new \DateTimeImmutable('2027-04-01'));

        $this->assertFalse($reminders['last_sent']);
        $this->assertNull($reminders['last_at']);
        $this->assertSame('2027-05-01', $reminders['next']?->format('Y-m-d'));
    }

    public function testAfterTheFirstReminderTheLastIsItAndTheNextIsTheSecond(): void
    {
        $this->campaign->markDone(
            ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1),
            '2027-05-15',
            new \DateTimeImmutable('2027-05-01 08:00')
        );

        $reminders = $this->campaign->automaticReminders(new \DateTimeImmutable('2027-05-05'));

        $this->assertTrue($reminders['last_sent']);
        $this->assertSame('2027-05-01', $reminders['last_at']?->format('Y-m-d'));
        $this->assertSame('2027-05-13', $reminders['next']?->format('Y-m-d'));
    }

    public function testWithTheEmailsOffNoReminderIsAnnouncedAsComing(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED, '0', 'registration');

        $this->assertNull($this->campaign->automaticReminders(new \DateTimeImmutable('2027-04-01'))['next']);
    }

    // ── the reminders ─────────────────────────────────────────────────

    public function testAReminderIsDueItsConfiguredNumberOfDaysBeforeTheClose(): void
    {
        $now = new \DateTimeImmutable('2027-04-01');

        $this->assertSame(
            '2027-05-01',
            $this->campaign->reminderDate(ReenrollmentCampaignService::EMAIL_REMINDER_1, $now)?->format('Y-m-d')
        );
        $this->assertSame(
            '2027-05-13',
            $this->campaign->reminderDate(ReenrollmentCampaignService::EMAIL_REMINDER_2, $now)?->format('Y-m-d')
        );
    }

    public function testAReminderThatWouldFallBeforeTheOpeningIsSkippedRatherThanSentLate(): void
    {
        // 90 days before a 15 May close is 14 February — before the 1 March
        // opening, so nobody could have answered yet.
        $this->settingService->set(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS, '90', 'registration');

        $this->assertNull(
            $this->campaign->reminderDate(ReenrollmentCampaignService::EMAIL_REMINDER_1, new \DateTimeImmutable('2027-04-01')),
            'A reminder due before anybody could answer is skipped outright, never sent late.'
        );
    }

    // ── who is written to ─────────────────────────────────────────────

    public function testAFamilyOfThreeReceivesOneEmailListingThree(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');
        $this->createAnime('Cléo', 'famille@example.be');

        $families = $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: true);

        $this->assertCount(1, $families, 'Three messages to one address would read as a mistake and would be one.');
        $this->assertSame('famille@example.be', $families[0]['email']);
        $this->assertCount(3, $families[0]['member_names']);
    }

    public function testAFamilyWhoHasAnsweredForEveryChildIsOwedNothing(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $bo = $this->createAnime('Bo', 'famille@example.be');

        $this->repository->saveAnswer($alix, $this->targetYearId, 'reenrolled', null, null, null, []);
        $this->assertCount(
            1,
            $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: true),
            'Two children out of three answered is still an answer owed.'
        );

        $this->repository->saveAnswer($bo, $this->targetYearId, 'leaving', null, null, null, []);
        $this->assertSame(
            [],
            $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: true)
        );
    }

    public function testAReminderNamesOnlyTheChildStillMissing(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');
        $this->repository->saveAnswer($alix, $this->targetYearId, 'reenrolled', null, null, null, []);

        $families = $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: true);

        $this->assertCount(1, $families);
        $this->assertSame(
            ['Bo Dupont'],
            $families[0]['member_names'],
            'Telling a parent about a form they already filled in for their other child is how a reminder gets ignored.'
        );
    }

    public function testTheOpeningEmailGoesToEverybodyIncludingWhoHasAlreadyAnswered(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $this->repository->saveAnswer($alix, $this->targetYearId, 'reenrolled', null, null, null, []);

        $this->assertCount(
            1,
            $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: false)
        );
    }

    public function testAFamilyWithNoUsableAddressIsSimplyAbsent(): void
    {
        $this->createAnime('Alix', null);

        $this->assertSame(
            [],
            $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, silentOnly: true)
        );
    }

    public function testABatchNeverSplitsAFamilyInTwo(): void
    {
        // Two families of two, one address each, batched one family at a
        // time: the cursor is the family's own smallest member id, so
        // neither family is cut in half.
        $this->createAnime('Alix', 'un@example.be');
        $this->createAnime('Bo', 'un@example.be');
        $this->createAnime('Cléo', 'deux@example.be');
        $this->createAnime('Dan', 'deux@example.be');

        $first = $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, true, 0, 1);
        $this->assertCount(1, $first);
        $this->assertCount(2, $first[0]['member_names']);

        $second = $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, true, $first[0]['key'], 1);
        $this->assertCount(1, $second);
        $this->assertCount(2, $second[0]['member_names']);
        $this->assertNotSame($first[0]['email'], $second[0]['email']);

        $this->assertSame(
            [],
            $this->recipients->pendingFamilies($this->currentYearId, $this->targetYearId, true, $second[0]['key'], 1)
        );
    }

    // ── the tracking ──────────────────────────────────────────────────

    public function testTheTrackingCountsAndNeverNames(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $bo = $this->createAnime('Bo', 'famille@example.be');
        $this->createAnime('Cléo', 'autre@example.be');

        $this->repository->saveAnswer($alix, $this->targetYearId, 'reenrolled', null, null, null, []);
        $this->repository->saveAnswer($bo, $this->targetYearId, 'leaving', null, null, null, []);

        $tracking = $this->campaign->tracking();

        $this->assertSame(3, $tracking['total']);
        $this->assertSame(2, $tracking['answered']);
        $this->assertSame(1, $tracking['leaving']);
        $this->assertSame(1, $tracking['silent']);
        $this->assertSame('2027-2028', $tracking['target_year_label']);
    }

    /**
     * IT-16 ticks the departure box from a « quitte » answer, and the
     * anime roster otherwise excludes leaving members — so a total read
     * naively would go 3 the day the campaign opens and 2 the moment one
     * family answered, with their answer discarded as « no longer an
     * animé ». « 1 réponse sur 2 » after two families answered, one of
     * them a departure, is a number nobody can reconcile.
     */
    public function testADepartureAnswerDoesNotShrinkTheTotalItIsCountedIn(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');
        $this->createAnime('Cléo', 'autre@example.be');

        $this->repository->saveAnswer($alix, $this->targetYearId, 'leaving', null, null, null, []);
        // What the answer does to the box, done here directly: this test
        // is about the count, not about the link that moves it.
        $this->pdo->exec('UPDATE member_years SET leaving = 1 WHERE member_id = ' . $alix);

        $tracking = $this->campaign->tracking();

        $this->assertSame(3, $tracking['total']);
        $this->assertSame(1, $tracking['answered']);
        $this->assertSame(1, $tracking['leaving']);
        $this->assertSame(2, $tracking['silent']);
    }

    // ── fixture ───────────────────────────────────────────────────────

    private function createAnime(string $firstName, ?string $email): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, email_encrypted, email_blind_index, leaving, scout_year_offset, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt('2017-06-01', 'member_years.birth_date'),
            $this->encryption->encrypt('M', 'member_years.gender'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex($email, 'email') : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->sectionId)->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId, $branchId]);

        return $memberId;
    }
}
