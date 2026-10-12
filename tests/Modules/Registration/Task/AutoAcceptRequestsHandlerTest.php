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
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Task\AutoAcceptRequestsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The scheduled task of the automatic acceptance of the « Oui » requests
 * (issue #843, IT-10): the handler builds its own services from the task
 * context alone — the way the scheduler runs it, with no argument — and
 * re-arms itself whatever the pass did. What a pass accepts is
 * Tests\Modules\Registration\Service\AutomaticAcceptanceServiceTest's; this
 * one pins the wiring, which nothing else executes (PHPStan does not read
 * tests, and the composition here is the task's own).
 */
#[Group('database')]
class AutoAcceptRequestsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private RegistrationRequestRepository $requests;
    private TaskContext $context;
    private int $targetYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
        $this->settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false
        );
        $years = new ScoutYearService($this->pdo);
        $publicYearId = $years->ensureYear('2026-2027');
        $this->targetYearId = $years->ensureYear('2027-2028');
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $publicYearId);

        $louveteaux = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        (new SlotCapacityRepository($this->pdo))->upsert($louveteaux, 1, 15);

        $content = new EditableContentService(new EditableContentRepository($this->pdo));
        $content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $content->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24);

        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    /**
     * Built by the handler alone, the pass accepts a « Oui » request through
     * the decision service — its e-mail planned — and the chain is re-armed
     * an hour later. Nothing is ever sent by the pass itself.
     */
    public function testAPassAcceptsAOuiRequestPlansItsEmailAndRearmsAnHourLater(): void
    {
        RegistrationTestHelper::enableAutomaticAcceptance($this->settings, '2026-09-01');
        $id = $this->file('2026-10-08 10:00:00');
        $before = time();

        (new AutoAcceptRequestsHandler())->handle([], $this->context);

        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->requests->findById($id)?->status);
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM registration_decision_emails WHERE state = 'scheduled' AND decision = 'accepted'"
        )->fetchColumn());
        $runAt = $this->pendingRuns();
        $this->assertCount(1, $runAt);
        $this->assertGreaterThanOrEqual($before + AutoAcceptRequestsHandler::INTERVAL_SECONDS, strtotime($runAt[0]));
    }

    /** Off, it accepts nothing — and the chain lives on, to read the switch again in an hour. */
    public function testOffItAcceptsNothingAndStillRearms(): void
    {
        $id = $this->file('2026-10-08 10:00:00');

        (new AutoAcceptRequestsHandler())->handle([], $this->context);

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->requests->findById($id)?->status);
        $this->assertCount(1, $this->pendingRuns());
    }

    /** The first run of the chain, seeded once however often the entry points ask. */
    public function testTheChainIsSeededOnce(): void
    {
        $scheduler = SchedulerService::forPdo($this->pdo);

        AutoAcceptRequestsHandler::bootstrap($scheduler);
        AutoAcceptRequestsHandler::bootstrap($scheduler);

        $this->assertCount(1, $this->pendingRuns());
    }

    private function file(string $receivedAt): int
    {
        $id = $this->requests->create($this->targetYearId, [
            'parent_name' => 'Parent Vidal', 'child_last_name' => 'Vidal', 'child_first_name' => 'Emma',
            'gender' => 'F', 'birth_date' => '2019-05-04', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => 'emma@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $filed = $this->pdo->prepare('UPDATE registration_requests SET received_at = ? WHERE id = ?');
        $filed->execute([$receivedAt, $id]);

        return $id;
    }

    /**
     * @return array<int, string>
     */
    private function pendingRuns(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT run_at FROM scheduled_actions
             WHERE module_id = 'registration' AND task_key = ? AND reference = ? AND status = 'pending'"
        );
        $stmt->execute([AutoAcceptRequestsHandler::TASK_KEY, AutoAcceptRequestsHandler::REFERENCE]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
