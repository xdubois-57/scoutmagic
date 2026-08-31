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
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $passageService = new PassageService(
            $this->pdo,
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
            $this->pdo,
            $this->encryption,
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

    public function testTheCampaignKeyIsTheCloseDateOfTheWindowInProgress(): void
    {
        $this->assertSame('2027-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-04-01')));
        // Just after closing, still the campaign that has just ended —
        // which is what makes a closing e-mail belong to it.
        $this->assertSame('2027-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-05-16')));
        // Before the first opening of the year, last year's.
        $this->assertSame('2026-05-15', $this->campaign->currentCampaignKey(new \DateTimeImmutable('2027-02-01')));
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
