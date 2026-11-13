<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Task;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\Repository\UnitStaffSectionRepository;
use Core\Member\UnitStaffSectionService;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskCapabilities;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Api\ProviderException;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Task\ApplyRedirectHandler;
use Modules\SosStaff\Task\ReconcileRedirectHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * Issue #1102, parts 2 and 3: the line checked against the planning by the
 * scheduler itself — the real service graph SosTaskServices builds, the
 * real scheduler chains; only the operator's line and SMTP are replaced.
 *
 * Nobody is on duty in these tests, so the line is due on the default
 * number (+32470000099): what is checked is the comparison, the correction,
 * the retries and the alert, not the roster rule OnCallServiceTest covers.
 */
#[Group('database')]
final class ReconcileRedirectHandlerTest extends TestCase
{
    private const DEFAULT_NUMBER = '+32470000099';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SchedulerRepository $tasks;
    private SchedulerService $scheduler;
    private SchedulerRunner $runner;
    private SettingService $settings;
    private int $year;
    private ForwardingState $forwarding;
    private bool $refuse = false;
    private bool $unreadable = false;
    /** @var list<string> */
    private array $changes = [];
    /** @var list<string> */
    private array $mailedTo = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $this->settings->register('email_notifications_enabled', '0', 'boolean', 'Emails', 'desc', 'sos_staff');
        // Where the outage is remembered, as module.json declares it: the
        // attention point reads it, and it is what makes one alert per outage.
        $this->settings->register('redirect_last_failure_at', '', 'text', 'Échec', 'desc', 'sos_staff');
        $this->year = (new ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->tasks = new SchedulerRepository($this->pdo);
        $this->scheduler = new SchedulerService($this->tasks);

        $this->forwarding = new ForwardingState(true, '+32470000001');
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnCallback(function () {
            if ($this->unreadable) {
                throw new ProviderException('Identifiants expirés.');
            }
            return $this->forwarding;
        });
        $provider->method('setForwarding')->willReturnCallback(function (string $number): void {
            if ($this->refuse) {
                throw new ProviderException('Opérateur indisponible.');
            }
            $this->changes[] = $number;
            $this->forwarding = new ForwardingState(true, $number);
        });
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('getEnabledModuleIds')->willReturn(['test_tools']);
        $capabilities = new TaskCapabilities($modules);
        $capabilities->register(PhoneProviderInterface::class, 'test_tools', fn() => $provider);

        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(function (string $to): void {
            $this->mailedTo[] = $to;
        });

        $this->runner = new SchedulerRunner($this->tasks, $journal);
        $this->runner->setTaskContext(new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mail,
            $journal,
            $this->settings,
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir(),
            null,
            $capabilities
        ));
        $this->runner->registerHandler('sos_staff', 'apply_redirect', new ApplyRedirectHandler());
        $this->runner->registerHandler('sos_staff', 'reconcile_redirect', new ReconcileRedirectHandler());

        $default = $this->member('default', self::DEFAULT_NUMBER, 'chef@example.test');
        (new SosSettingsRepository($this->pdo))->saveDefaultNumberMember($default);
        (new UserAccountRepository($this->pdo, $this->encryption))->create('chef@example.test');
    }

    /**
     * The line rings somewhere the planning does not say — a transition
     * that failed, a change made at the operator: the hourly check puts it
     * back, and the chain carries on an hour later.
     */
    public function testTheHourlyCheckPutsADriftedLineBackAndRearmsItself(): void
    {
        $this->queueNow(ReconcileRedirectHandler::REFERENCE_HOURLY, []);

        $this->runner->processOverdue();

        self::assertSame([self::DEFAULT_NUMBER], $this->changes);
        self::assertSame(['redirect_drift_detected' => 'warning'], $this->journal('redirect_drift_detected'));
        $next = $this->pending(ReconcileRedirectHandler::REFERENCE_HOURLY);
        self::assertCount(1, $next);
        self::assertGreaterThan(new \DateTimeImmutable('+50 minutes'), new \DateTimeImmutable($next[0]['run_at']));
        self::assertSame([], $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY));
    }

    public function testALineAlreadyWhereItShouldBeIsLeftAlone(): void
    {
        $this->forwarding = new ForwardingState(true, self::DEFAULT_NUMBER);
        $this->queueNow(ReconcileRedirectHandler::REFERENCE_HOURLY, []);

        $this->runner->processOverdue();

        self::assertSame([], $this->changes);
        self::assertSame([], $this->journal('redirect_drift_detected'));
    }

    /**
     * Issue #1125: the operator answers in its own spelling (« 0032… ») — the
     * line is where it should be, and the hourly check leaves it alone
     * rather than « correcting » it every hour.
     */
    public function testALineOnTheRightNumberInTheOperatorsSpellingIsLeftAlone(): void
    {
        $this->forwarding = new ForwardingState(true, '0032470000099');
        $this->queueNow(ReconcileRedirectHandler::REFERENCE_HOURLY, []);

        $this->runner->processOverdue();

        self::assertSame([], $this->changes);
        self::assertSame([], $this->journal('redirect_drift_detected'));
    }

    /**
     * Part 2: a scheduled transition the operator refused used to stay
     * failed until the next handover. It still shows as failed, and now
     * arms a retry 5 minutes later; the chefs d'unité are alerted once.
     */
    public function testAFailedTransitionArmsARetryThatPutsTheLineRight(): void
    {
        $this->refuse = true;
        $id = $this->tasks->create(
            'sos_staff',
            'apply_redirect',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            json_encode([
                'date' => date('Y-m-d'), 'member_id' => null, 'previous_member_id' => null,
                'scout_year_id' => $this->year,
            ]),
            date('Y-m-d')
        );

        $this->runner->processOverdue();

        self::assertSame('failed', $this->tasks->findById($id)['status']);
        $retry = $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY);
        self::assertCount(1, $retry);
        self::assertSame(['attempt' => 1], json_decode((string) $retry[0]['payload'], true));
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+5 minutes'))->getTimestamp(),
            (new \DateTimeImmutable($retry[0]['run_at']))->getTimestamp(),
            60
        );
        self::assertSame(['chef@example.test'], $this->mailedTo);
        // What keeps the urgent attention point up meanwhile.
        self::assertNotSame('', $this->settings->get('redirect_last_failure_at', 'sos_staff', ''));

        // The operator answers again by the time the retry runs.
        $this->refuse = false;
        $this->makeDue((int) $retry[0]['id']);
        $this->runner->processOverdue();

        self::assertSame([self::DEFAULT_NUMBER], $this->changes);
        self::assertSame([], $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY));
        self::assertSame('', $this->settings->get('redirect_last_failure_at', 'sos_staff', ''));
        // A retry is not a link of the hourly chain.
        self::assertSame([], $this->pending(ReconcileRedirectHandler::REFERENCE_HOURLY));
    }

    /**
     * 5, 15, 60 minutes, then the hourly check alone — and one alert for
     * the whole outage, not one per attempt.
     */
    public function testRetriesBackOffThenStop(): void
    {
        $this->refuse = true;
        ReconcileRedirectHandler::scheduleRetry($this->scheduler);
        $delays = [];

        for ($i = 0; $i < 4; $i++) {
            $retry = $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY);
            if ($retry === []) {
                break;
            }
            $payload = json_decode((string) $retry[0]['payload'], true);
            $delays[] = $payload['attempt'];
            $this->makeDue((int) $retry[0]['id']);
            $this->runner->processOverdue();
        }

        self::assertSame([1, 2, 3], $delays);
        self::assertSame([], $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY));
        self::assertSame(['chef@example.test'], $this->mailedTo);
    }

    /**
     * An operator that cannot even be read is an outage too: urgent
     * attention point, one alert, quick retries — and it clears once the
     * line reads back right (review of #1135).
     */
    public function testAnUnreadableOperatorIsReportedOnceAndClearsWhenReadableAgain(): void
    {
        $this->unreadable = true;
        $this->queueNow(ReconcileRedirectHandler::REFERENCE_HOURLY, []);
        $this->runner->processOverdue();
        $retry = $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY);
        $this->makeDue((int) $retry[0]['id']);
        $this->runner->processOverdue();

        self::assertSame(['chef@example.test'], $this->mailedTo);
        self::assertNotSame('', $this->settings->get('redirect_last_failure_at', 'sos_staff', ''));

        $this->unreadable = false;
        $this->forwarding = new ForwardingState(true, self::DEFAULT_NUMBER);
        $retry = $this->pending(ReconcileRedirectHandler::REFERENCE_RETRY);
        $this->makeDue((int) $retry[0]['id']);
        $this->runner->processOverdue();

        self::assertSame('', $this->settings->get('redirect_last_failure_at', 'sos_staff', ''));
    }

    public function testBootstrapSeedsTheHourlyChainOnce(): void
    {
        ReconcileRedirectHandler::bootstrap($this->scheduler);
        ReconcileRedirectHandler::bootstrap($this->scheduler);

        self::assertCount(1, $this->pending(ReconcileRedirectHandler::REFERENCE_HOURLY));
    }

    /** @param array<string, mixed> $payload */
    private function queueNow(string $reference, array $payload): void
    {
        $this->tasks->create(
            'sos_staff',
            'reconcile_redirect',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            $payload === [] ? null : json_encode($payload),
            $reference
        );
    }

    private function makeDue(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE scheduled_actions SET run_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $id]);
    }

    /** @return list<array<string, mixed>> */
    private function pending(string $reference): array
    {
        return array_values(array_filter(
            $this->scheduler->findAllForTask('sos_staff', 'reconcile_redirect', 100),
            static fn(array $row): bool => $row['status'] === 'pending' && $row['reference'] === $reference
        ));
    }

    /** @return array<string, string> event type => level */
    private function journal(string $type): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT event_type, level FROM event_log WHERE category = 'sos_staff' AND event_type = ?"
        );
        $stmt->execute([$type]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function member(string $code, string $mobile, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$code]);
        $id = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             mobile_encrypted, email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $this->year,
            $this->encryption->encrypt('Membre', 'member_years.first_name'),
            $this->encryption->encrypt('Test', 'member_years.last_name'),
            $this->encryption->encrypt($mobile, 'member_years.mobile'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYear = (int) $this->pdo->lastInsertId();
        // A chef d'unité: the alert's recipient — and a member of the Staff
        // d'U, since only one of those may hold the default number (#1125).
        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) "
                . "VALUES ('CU', 'Chef d''unité', 'admin', 1)"
        );
        $function = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'CU'")->fetchColumn();
        $staffdu = (new UnitStaffSectionService(new UnitStaffSectionRepository($this->pdo)))->ensureSection();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYear, $function, $staffdu]);

        return $id;
    }
}
