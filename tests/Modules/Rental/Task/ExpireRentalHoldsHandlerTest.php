<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Task\ExpireRentalHoldsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The hourly hold-expiry task, run through the real SchedulerRunner.
 *
 * What the service test cannot cover is the scheduling itself, and one trap
 * in particular: SchedulerRunner marks a task done only *after* handle()
 * returns, so a self-rescheduling handler that guards on "is one already
 * pending?" finds itself, skips, and dies after a single run. That is the
 * bug this file exists to keep dead.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ExpireRentalHoldsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private RentalBookingRepository $bookingRepository;
    private SchedulerService $schedulerService;
    private SchedulerRunner $runner;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);

        $repository = new SchedulerRepository($this->pdo);
        $this->schedulerService = new SchedulerService($repository);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->runner = new SchedulerRunner($repository, $journalService);
        $this->runner->registerHandler('rental', ExpireRentalHoldsHandler::TASK_KEY, new ExpireRentalHoldsHandler());
        $this->runner->setTaskContext(new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        ));
    }

    private function createBooking(
        string $reference,
        ?\DateTimeImmutable $holdUntil,
        ?HoldOrigin $holdOrigin
    ): int {
        $created = $this->bookingRepository->create(
            1,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            ['name' => 'Jeanne', 'email' => 'jeanne@example.be', 'phone' => null, 'organisation' => null, 'purpose' => null, 'comment' => null],
            null,
            $holdUntil,
            $holdOrigin,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        return $created['id'];
    }

    /** Runs the pass as a real cron would, with the clock already past. */
    private function runDueTasks(): int
    {
        return $this->runner->processOverdue();
    }

    public function testBootstrapQueuesTheFirstRun(): void
    {
        ExpireRentalHoldsHandler::bootstrap($this->schedulerService);

        $this->assertNotNull($this->schedulerService->find('rental', ExpireRentalHoldsHandler::TASK_KEY, 'hourly'));
    }

    public function testBootstrapIsIdempotentSoEveryRequestCanCallIt(): void
    {
        ExpireRentalHoldsHandler::bootstrap($this->schedulerService);
        ExpireRentalHoldsHandler::bootstrap($this->schedulerService);
        ExpireRentalHoldsHandler::bootstrap($this->schedulerService);

        $pending = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'expire_rental_holds' AND status = 'pending'"
        )->fetchColumn();

        $this->assertSame(1, $pending);
    }

    public function testARunQueuesItsSuccessorSoTheChainNeverStops(): void
    {
        // The trap: the running task is still `pending` while handle() is
        // executing. A guarded reschedule would find it and skip, and this
        // assertion is what catches that.
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->assertSame(1, $this->runDueTasks());
        $this->assertNotNull($this->schedulerService->find('rental', ExpireRentalHoldsHandler::TASK_KEY, 'hourly'));
    }

    public function testTheSuccessorIsScheduledAnHourOutNotImmediately(): void
    {
        // An immediately-due successor would make every cron pass loop on
        // this task forever.
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');
        $this->runDueTasks();

        $next = $this->schedulerService->find('rental', ExpireRentalHoldsHandler::TASK_KEY, 'hourly');
        $this->assertNotNull($next);
        $this->assertGreaterThan(
            (new \DateTimeImmutable('+50 minutes'))->format('Y-m-d H:i:s'),
            (string) $next['run_at']
        );
    }

    public function testASecondPassDoesNotRunTheTaskAgain(): void
    {
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->assertSame(1, $this->runDueTasks());
        $this->assertSame(0, $this->runDueTasks());
    }

    public function testALapsedAutomaticHoldIsClearedByTheRun(): void
    {
        $bookingId = $this->createBooking('LOC-2027-0001', new \DateTimeImmutable('-1 hour'), HoldOrigin::AUTOMATIC);
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->runDueTasks();

        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);
        $this->assertNull($booking->holdUntil);
        // The request itself is untouched: nobody promised anything, so it
        // goes back to waiting rather than being refused.
        $this->assertSame(BookingStatus::RECEIVED, $booking->status);
    }

    public function testALapsedManagerOptionExpiresTheBooking(): void
    {
        $bookingId = $this->createBooking('LOC-2027-0002', new \DateTimeImmutable('-1 hour'), HoldOrigin::MANAGER);
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->runDueTasks();

        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);
        $this->assertSame(BookingStatus::EXPIRED, $booking->status);
        $this->assertNull($booking->holdUntil);
    }

    public function testAHoldStillRunningIsLeftAloneByTheRun(): void
    {
        $bookingId = $this->createBooking('LOC-2027-0003', new \DateTimeImmutable('+2 days'), HoldOrigin::AUTOMATIC);
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->runDueTasks();

        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->holdUntil);
    }

    public function testTheRunNeverFailsWhenThereIsNothingToExpire(): void
    {
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->assertSame(1, $this->runDueTasks());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE status = 'failed'")->fetchColumn()
        );
    }

    public function testNoRenterIdentityReachesTheJournalWhenAHoldExpires(): void
    {
        $this->createBooking('LOC-2027-0004', new \DateTimeImmutable('-1 hour'), HoldOrigin::MANAGER);
        $this->schedulerService->schedule('rental', ExpireRentalHoldsHandler::TASK_KEY, new \DateTimeImmutable('-1 minute'), [], 'hourly');

        $this->runDueTasks();

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('LOC-2027-0004', $journal);
        $this->assertStringNotContainsString('jeanne@example.be', $journal);
        $this->assertStringNotContainsString('Jeanne', $journal);
    }
}
