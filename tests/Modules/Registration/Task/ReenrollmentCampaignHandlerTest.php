<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Task\ReenrollmentCampaignHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The campaign's clock: it opens the campaign, closes it, and hands each
 * of the four e-mails to the sending task exactly once.
 *
 * **Exactly once is the whole point, and it is not free.** This handler
 * polls HOURLY and re-arms itself, because `poor_mans_cron` only advances
 * on page visits — so every decision below is asked up to twenty-four
 * times on the day it comes true, and each hand-over that is not guarded
 * is another copy of the same e-mail to every family in the unit.
 *
 * Openings and closings are guarded by their own markers, written the
 * moment the campaign changes state. The two reminders have no such
 * moment: nothing changes when a reminder falls due except that it is
 * owed, so their guard has to be the hand-over itself.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReenrollmentCampaignHandlerTest extends TestCase
{
    /** Close 05-15, first reminder 14 days earlier. */
    private const REMINDER_DAY = '2027-05-01';
    private const OPENING_DAY = '2027-03-01';
    private const CLOSING_DAY = '2027-05-15';

    private \PDO $pdo;
    private SettingService $settingService;
    private TaskContext $context;
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
        foreach ([
            ReenrollmentCampaignService::EMAIL_OPENING,
            ReenrollmentCampaignService::EMAIL_REMINDER_1,
            ReenrollmentCampaignService::EMAIL_REMINDER_2,
            ReenrollmentCampaignService::EMAIL_CLOSING,
        ] as $type) {
            $marker = ReenrollmentCampaignService::emailMarker($type);
            $this->settingService->register($marker, '', 'text', $marker, 'Test.', 'registration');
        }
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->currentYearId,
            'text',
            'Année publique',
            'Test.'
        );
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    // ── the reminders, and the hourly poll ────────────────────────────

    public function testAReminderFallingDueIsHandedOverOnce(): void
    {
        $this->openCampaign();

        $this->poll(self::REMINDER_DAY);

        $this->assertSame(
            [ReenrollmentCampaignService::EMAIL_REMINDER_1],
            $this->handedOverTypes()
        );
    }

    /**
     * The poll runs every hour. On a reminder's day it therefore asks the
     * same question up to twenty-four times, and every unguarded answer is
     * another copy of the same e-mail to every silent family in the unit.
     */
    public function testTwentyFourPollsOnTheSameDayHandOverOneReminder(): void
    {
        $this->openCampaign();

        for ($hour = 0; $hour < 24; $hour++) {
            $this->poll(self::REMINDER_DAY . sprintf(' %02d:30:00', $hour));
        }

        $this->assertCount(
            1,
            $this->handedOverTypes(),
            'A family must receive one reminder, not one per cron pass.'
        );
    }

    /**
     * The chain the sender builds for a unit of more than one batch: the
     * first row carries `reminder_1:<campaign>`, its continuations the
     * same plus a cursor. All of it is ONE hand-over, and a poll that
     * lands between two of its batches must not start a second.
     */
    public function testAPollBetweenTwoBatchesOfTheSameHandOverStartsNoSecondOne(): void
    {
        $this->openCampaign();
        $this->poll(self::REMINDER_DAY . ' 08:30:00');

        // The first batch has run and queued its continuation, exactly as
        // Task\SendReenrollmentEmailsHandler does for a full batch.
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'done'");
        (new SchedulerService(new SchedulerRepository($this->pdo)))->schedule(
            'registration',
            'send_reenrollment_emails',
            new \DateTimeImmutable(),
            ['type' => ReenrollmentCampaignService::EMAIL_REMINDER_1, 'campaign' => self::CLOSING_DAY, 'after_key' => 37],
            ReenrollmentCampaignService::EMAIL_REMINDER_1 . ':' . self::CLOSING_DAY . ':37'
        );

        $this->poll(self::REMINDER_DAY . ' 09:30:00');

        $this->assertCount(
            2,
            $this->queued(),
            'The continuation and its parent are one hand-over; a third row is a second reminder.'
        );
    }

    /**
     * The other half of the guard, and the one the chain check cannot
     * answer: once the sending chain has drained, no row of it is left to
     * see. What says "this e-mail has gone out" from then on is the
     * sender's marker — and the poll keeps running all day.
     */
    public function testAPollAfterTheReminderHasGoneOutHandsOverNothing(): void
    {
        $this->openCampaign();
        $this->poll(self::REMINDER_DAY . ' 08:30:00');

        // The chain has drained and the sender recorded the send.
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'done'");
        $this->settingService->setInternal(
            ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_REMINDER_1),
            self::CLOSING_DAY,
            'registration'
        );

        $this->poll(self::REMINDER_DAY . ' 09:30:00');

        $this->assertCount(
            1,
            $this->queued(),
            'A drained chain leaves no row behind; only the marker stops the next poll.'
        );
    }

    public function testAReminderIsNotHandedOverOnAClosedCampaign(): void
    {
        // The window exists, but the switch is off.
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN, '0', 'registration');

        $this->poll(self::REMINDER_DAY);

        $this->assertSame([], $this->handedOverTypes());
    }

    public function testNothingIsHandedOverOnAnOrdinaryDay(): void
    {
        $this->openCampaign();

        $this->poll('2027-04-02');

        $this->assertSame([], $this->handedOverTypes());
    }

    // ── opening and closing ───────────────────────────────────────────

    public function testTheOpeningIsHandedOverOnceHoweverOftenThePollRuns(): void
    {
        for ($hour = 0; $hour < 3; $hour++) {
            $this->poll(self::OPENING_DAY . sprintf(' %02d:30:00', $hour));
        }

        $this->assertSame([ReenrollmentCampaignService::EMAIL_OPENING], $this->handedOverTypes());
        $this->assertSame('1', (string) $this->settingService->get(
            ReenrollmentCampaignService::SETTING_OPEN,
            'registration',
            ''
        ));
    }

    public function testTheClosingIsHandedOverOnceHoweverOftenThePollRuns(): void
    {
        $this->openCampaign();

        for ($hour = 0; $hour < 3; $hour++) {
            $this->poll(self::CLOSING_DAY . sprintf(' %02d:30:00', $hour));
        }

        $this->assertSame([ReenrollmentCampaignService::EMAIL_CLOSING], $this->handedOverTypes());
        $this->assertSame('0', (string) $this->settingService->get(
            ReenrollmentCampaignService::SETTING_OPEN,
            'registration',
            ''
        ));
    }

    // ── the chain itself ──────────────────────────────────────────────

    public function testThePollAlwaysRearmsItselfEvenWhenItHasNothingToDo(): void
    {
        // Through handle(), not run(): the re-arm lives in its `finally`,
        // and that is precisely the part being asserted.
        (new ReenrollmentCampaignHandler())->handle([], $this->context);

        $rearmed = $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'reenrollment_campaign'")
            ->fetchColumn();

        $this->assertSame(1, (int) $rearmed, 'A campaign whose poll died once must not stop being a campaign.');
    }

    // ── harness ───────────────────────────────────────────────────────

    private function poll(string $now): void
    {
        $handler = new ReenrollmentCampaignHandler();
        $method = new \ReflectionMethod($handler, 'run');
        $method->invoke(
            $handler,
            $this->context,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new \DateTimeImmutable($now)
        );
    }

    private function openCampaign(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN, '1', 'registration');
        $this->settingService->setInternal(
            ReenrollmentCampaignService::MARKER_OPENED,
            self::CLOSING_DAY,
            'registration'
        );
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
     * @return array<int, string>
     */
    private function handedOverTypes(): array
    {
        return array_map(
            static function (array $row): string {
                $payload = json_decode((string) $row['payload'], true);

                return is_array($payload) ? (string) ($payload['type'] ?? '') : '';
            },
            $this->queued()
        );
    }
}
