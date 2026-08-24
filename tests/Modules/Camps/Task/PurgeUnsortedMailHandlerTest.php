<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Task;

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
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Task\PurgeUnsortedMailHandler;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Repository\InboundMessageRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The counterweight to a dedicated mailbox.
 *
 * A mailbox that claims everything is what makes « Courrier non classé »
 * possible, and also what would turn this module into an archive of the
 * unit's inbox if nothing removed what nobody ever attributed. This task
 * is that counterweight, and the retention it applies is stated on the
 * screen rather than only in a policy nobody reads — so it had better be
 * the retention the task actually applies.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeUnsortedMailHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMessageRepository $messages;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->messages = new InboundMessageRepository($this->pdo, $this->encryption);
    }

    public function testAMessageOlderThanTheRetentionGoes(): void
    {
        $old = $this->unsortedMessage('-8 months', 'vieux@mozet.be');

        $this->handle();

        $this->assertNull($this->find($old));
    }

    public function testAMessageInsideTheRetentionStays(): void
    {
        $recent = $this->unsortedMessage('-2 months', 'recent@mozet.be');

        $this->handle();

        $this->assertNotNull($this->find($recent));
    }

    /**
     * The boundary is the message's own date against "now minus N months",
     * so a message one day inside the window survives the run that removes
     * one a day outside it.
     */
    public function testTheCutoffIsRespectedToTheDay(): void
    {
        $justInside = $this->unsortedMessage('-6 months +2 days', 'inside@mozet.be');
        $justOutside = $this->unsortedMessage('-6 months -2 days', 'outside@mozet.be');

        $this->handle();

        $this->assertNotNull($this->find($justInside));
        $this->assertNull($this->find($justOutside));
    }

    public function testTheConfiguredRetentionIsTheOneApplied(): void
    {
        $this->setRetentionMonths(1);
        $twoMonthsOld = $this->unsortedMessage('-2 months', 'deux@mozet.be');

        $this->handle();

        $this->assertNull($this->find($twoMonthsOld), 'a one-month retention must remove a two-month-old message');
    }

    /**
     * A zero or negative retention would empty the screen on the next
     * tick, which is never what somebody meant to type.
     */
    public function testAnAbsurdRetentionFallsBackToOneMonthRatherThanErasingEverything(): void
    {
        $this->setRetentionMonths(0);
        $today = $this->unsortedMessage('now', 'aujourdhui@mozet.be');

        $this->handle();

        $this->assertNotNull($this->find($today));
    }

    /**
     * Only the unsorted pile. A message somebody filed under a stay is
     * that stay's correspondence and outlives this window entirely.
     */
    public function testAMessageFiledUnderAStayIsNeverTouched(): void
    {
        $filed = $this->message('-8 months', 'classe@mozet.be', CampsMessageConsumer::referenceFor(7));

        $this->handle();

        $this->assertNotNull($this->find($filed));
    }

    /**
     * Another consumer's mail is not this module's business, however old.
     */
    public function testAnotherConsumersMailIsLeftAlone(): void
    {
        $id = $this->messages->create(
            1, 'INBOX', 1, 500, 'rental', 'LOC-2020-0001', LinkOrigin::SENDER,
            '<other@mail>', null, 'Sujet', 'autre@example.org', null, 'Corps', '',
            new \DateTimeImmutable('-8 months')
        );

        $this->handle();

        $this->assertNotNull($this->find($id));
    }

    public function testTheTaskRearmsItselfForTomorrow(): void
    {
        $this->handle();

        $queued = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('camps', PurgeUnsortedMailHandler::TASK_KEY, PurgeUnsortedMailHandler::REFERENCE);

        $this->assertNotNull($queued);
        $this->assertSame(
            (new \DateTimeImmutable('tomorrow 04:00'))->format('Y-m-d H:i:s'),
            $queued['run_at']
        );
    }

    public function testRunningTwiceQueuesOnlyOneNextRun(): void
    {
        $this->handle();
        $this->handle();

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_unsorted_mail'"
            )->fetchColumn()
        );
    }

    /**
     * The journal records how many and after how long — never who wrote
     * them or what they said (SECURITY.md §5).
     */
    public function testThePurgeIsJournaledWithoutNamingAnybody(): void
    {
        $this->unsortedMessage('-8 months', 'vieux@mozet.be');

        $this->handle();

        $row = $this->pdo->query(
            "SELECT description FROM event_log WHERE event_type = 'unsorted_mail_purged'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('1 message', (string) $row['description']);
        $this->assertStringNotContainsString('mozet.be', (string) $row['description']);
    }

    public function testNothingToPurgeIsNotJournaled(): void
    {
        $this->unsortedMessage('-2 months', 'recent@mozet.be');

        $this->handle();

        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'unsorted_mail_purged'"
            )->fetchColumn()
        );
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function handle(): void
    {
        (new PurgeUnsortedMailHandler())->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        ));
    }

    private function setRetentionMonths(int $months): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES ('camps_unsorted_retention_months', ?, 'camps', 'number', 'x', 'x')"
        );
        $stmt->execute([(string) $months]);
    }

    private function unsortedMessage(string $sentAt, string $from): int
    {
        return $this->message($sentAt, $from, CampsMessageConsumer::UNSORTED_REFERENCE);
    }

    private function message(string $sentAt, string $from, string $reference): int
    {
        static $uid = 1000;

        return $this->messages->create(
            1,
            'INBOX',
            1,
            ++$uid,
            CampsMessageConsumer::CONSUMER_ID,
            $reference,
            LinkOrigin::SENDER,
            '<' . $uid . '@mail>',
            null,
            'Confirmation',
            $from,
            null,
            'Bonjour, nous vous confirmons le terrain.',
            '',
            new \DateTimeImmutable($sentAt)
        );
    }

    private function find(int $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT id FROM inbound_messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : (object) $row;
    }
}
