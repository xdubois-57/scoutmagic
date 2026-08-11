<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Task\PurgeRegistrationRequestsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Retention purge (module spec): two years by default from `final_at`
 * (never `received_at`), a non-final request is never touched regardless
 * of age, and the handler self-reschedules daily.
 *
 * @group database
 */
class PurgeRegistrationRequestsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private SchedulerService $schedulerService;
    private TaskContext $context;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_purge_retention_years', '2', 'number', 'Rétention', 'desc', 'registration');

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settingService,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );

        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createRequest(string $status, ?\DateTimeImmutable $finalAt, string $firstName = 'Léa'): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], $status, $finalAt);

        return $created['id'];
    }

    public function testDeletesFinalRequestsPastRetention(): void
    {
        $id = $this->createRequest('refused', new \DateTimeImmutable('-3 years'));

        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $this->assertNull($this->requestRepository->findById($id));
    }

    public function testKeepsFinalRequestsWithinRetention(): void
    {
        $id = $this->createRequest('refused', new \DateTimeImmutable('-1 year'));

        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $this->assertNotNull($this->requestRepository->findById($id));
    }

    public function testNeverPurgesANonFinalRequestRegardlessOfAge(): void
    {
        // pending/accepted requests have no final_at at all — this
        // asserts findFinalIdsOlderThan() (and thus this handler) never
        // touches them no matter how old received_at is.
        $pendingId = $this->createRequest('pending', null);
        $acceptedId = $this->createRequest('accepted', null, 'Noa');

        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $this->assertNotNull($this->requestRepository->findById($pendingId));
        $this->assertNotNull($this->requestRepository->findById($acceptedId));
    }

    public function testCountsFromFinalAtNeverFromReceivedAt(): void
    {
        // final_at is recent even though the request could theoretically
        // be old — must survive.
        $id = $this->createRequest('withdrawn', new \DateTimeImmutable('-1 month'));

        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $this->assertNotNull($this->requestRepository->findById($id));
    }

    public function testReschedulesItselfDaily(): void
    {
        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('registration', 'purge_registration_requests', 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testEmptyRunLogsNoJournalEntry(): void
    {
        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_requests_purged'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPurgeJournalNeverContainsPersonalData(): void
    {
        $this->createRequest('encoded', new \DateTimeImmutable('-3 years'));

        (new PurgeRegistrationRequestsHandler())->handle([], $this->context);

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Léa', (string) $row['description']);
            $this->assertStringNotContainsString('Dupont', (string) $row['description']);
            $this->assertStringNotContainsString('Léa', (string) $row['context']);
            $this->assertStringNotContainsString('Dupont', (string) $row['context']);
        }
    }
}
