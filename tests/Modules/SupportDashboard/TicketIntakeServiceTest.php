<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\TicketIntakeResult;
use Modules\SupportDashboard\Service\TicketIntakeService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The receiver's ticket intake (roadmap IT-23).
 *
 * Three properties carry the design: an unauthenticated caller learns
 * nothing and gets a 403 with a `security` entry behind it; everything
 * else answers **200 with a refusal**, because a client that receives a
 * non-2xx retries and a ticket retried is a ticket filed twice; and what
 * a person wrote is a `BLOB` on disk and never appears in the journal.
 */
class TicketIntakeServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private TicketIntakeService $service;
    private SupportTicketRepository $tickets;
    private NotificationRepository $notifications;

    private const INSTALLATION_ID = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const SECRET = 'f1e2d3c4b5a6978869504132231405f6f1e2d3c4b5a6978869504132231405f6';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $installations = new SupportInstallationRepository($this->pdo);
        $this->tickets = new SupportTicketRepository($this->pdo, $this->encryption);

        $this->notifications = new NotificationRepository($this->pdo, $this->encryption);
        $notificationService = new NotificationService(
            $this->notifications,
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            null,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption)
        );
        $notificationService->registerModuleTypes('support_dashboard', [[
            'id' => TicketIntakeService::NOTIFICATION_TICKET_RECEIVED,
            'label' => 'Nouveau ticket de support',
            'description' => 'desc',
            'group' => 'Supervision',
            'role_min' => 'superadmin',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        $this->service = new TicketIntakeService(
            $installations,
            $this->tickets,
            new JournalService(new JournalRepository($this->pdo)),
            $notificationService
        );

        $installations->register(
            self::INSTALLATION_ID,
            password_hash(self::SECRET, PASSWORD_DEFAULT),
            '{}',
            StatisticsIntakeService::denormalize(['statistics_schema_version' => 1])
        );
    }

    // ── Authentication ──────────────────────────────────────────────────

    public function testAMissingSignatureIsRefusedWithoutSayingWhy(): void
    {
        $result = $this->service->receive($this->body(), '', '203.0.113.1', true);

        $this->assertFalse($result->accepted);
        $this->assertSame(403, $result->statusCode);
        $this->assertSame(TicketIntakeResult::REJECT_UNAUTHENTICATED, $result->rejectionReason);
        $this->assertSame(0, $this->countTickets());
        $this->assertSame(
            [['support_ticket_unauthenticated', 'security']],
            $this->journalTypesAndLevels()
        );
    }

    public function testAWrongSecretIsRefusedTheSameWay(): void
    {
        $result = $this->receive(secret: 'pas-le-bon-secret');

        $this->assertSame(403, $result->statusCode);
        $this->assertSame(TicketIntakeResult::REJECT_UNAUTHENTICATED, $result->rejectionReason);
        $this->assertSame(
            [['support_ticket_unauthenticated', 'security']],
            $this->journalTypesAndLevels()
        );
    }

    /**
     * Roadmap IT-24: an installation nobody has heard of is registered on
     * the spot, on the same trust-on-first-use rule the statistics intake
     * applies. A unit that refused telemetry has no row here, and
     * requiring one would mean buying support with data.
     *
     * The row it creates is marked as having no telemetry — see
     * testAFirstTicketRegistersTheInstallationWithoutTelemetry().
     */
    public function testAnUnknownInstallationIsRegisteredOnItsFirstTicket(): void
    {
        $result = $this->service->receive(
            $this->body(['installation_id' => 'ffffffffffffffffffffffffffffffff']),
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        );

        $this->assertTrue($result->accepted);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM support_installations')->fetchColumn());
    }

    public function testAFirstTicketRegistersTheInstallationWithoutTelemetry(): void
    {
        $this->service->receive(
            $this->body(['installation_id' => 'ffffffffffffffffffffffffffffffff']),
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        );

        $row = $this->pdo->query(
            "SELECT * FROM support_installations WHERE installation_id = 'ffffffffffffffffffffffffffffffff'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame(0, (int) $row['telemetry_enabled'], 'it never agreed to report');
        $this->assertNotSame('', (string) $row['secret_hash']);
        $this->assertSame(
            ['support_installation_provisioned', 'support_ticket_received'],
            array_column($this->journalRows(), 'type')
        );
    }

    /**
     * The secret presented on that first ticket is the one it must present
     * from then on: registering is trusting once, not forever.
     */
    public function testTheSecretOfAFirstTicketIsTheOneKept(): void
    {
        $body = $this->body(['installation_id' => 'ffffffffffffffffffffffffffffffff']);
        $this->service->receive($body, 'Bearer premier-secret', '203.0.113.1', true);

        $this->assertSame(
            403,
            $this->service->receive($body, 'Bearer un-autre-secret', '203.0.113.1', true)->statusCode
        );
        $this->assertTrue(
            $this->service->receive($body, 'Bearer premier-secret', '203.0.113.1', true)->accepted
        );
    }

    /**
     * An id that is not shaped like one never creates a row: the intake
     * would otherwise register an installation under arbitrary text.
     */
    public function testAnIdThatIsNotShapedLikeOneIsRefusedRatherThanRegistered(): void
    {
        $result = $this->service->receive(
            $this->body(['installation_id' => 'nope']),
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        );

        $this->assertSame(403, $result->statusCode);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM support_installations')->fetchColumn());
    }

    /**
     * The `security` entry says where it came from and nothing else — not
     * the installation id somebody TRIED, which would file a failed
     * attempt under a unit that may have had nothing to do with it.
     */
    public function testTheSecurityEntryNamesTheSourceAndNoInstallation(): void
    {
        $this->receive(secret: 'pas-le-bon-secret');

        $context = json_decode((string) $this->journalRows()[0]['context'], true);
        $this->assertSame(['source_ip' => '203.0.113.1'], $context);
    }

    public function testAValidSignatureIsAccepted(): void
    {
        $result = $this->receive();

        $this->assertTrue($result->accepted);
        $this->assertSame(200, $result->statusCode);
        $this->assertNotNull($result->ticketReference);
        $this->assertMatchesRegularExpression('/^SUP-[A-Z2-9]{6}$/', $result->ticketReference);
        $this->assertSame(1, $this->countTickets());
    }

    // ── What the ticket carries ─────────────────────────────────────────

    public function testTheDescriptionAndTheAddressAreOnlyReadableThroughTheRepository(): void
    {
        $this->receive();

        $row = $this->pdo->query('SELECT * FROM support_tickets')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('Mon import Desk', (string) $row['description_encrypted']);
        $this->assertStringNotContainsString('chef@unite.be', (string) $row['contact_email_encrypted']);
        $this->assertNotSame('chef@unite.be', (string) $row['contact_email_blind_index']);

        $ticket = $this->tickets->find((int) $row['id']);
        $this->assertNotNull($ticket);
        $this->assertSame('Mon import Desk ne passe plus depuis hier.', $ticket['description']);
        $this->assertSame('chef@unite.be', $ticket['contact_email']);
        $this->assertSame(TicketCategory::of('desk_import'), $ticket['category']);
        $this->assertSame(SupportTicketRepository::STATUS_OPEN, $ticket['status']);
        $this->assertSame('1.0.33', $ticket['site_version']);
        $this->assertSame('8.4.3', $ticket['php_version']);
    }

    /**
     * The journal is read by whoever runs the receiver, and a support
     * ticket is somebody describing their own installation in their own
     * words. The entry counts and categorises; it never quotes.
     */
    public function testTheJournalNeverCarriesWhatSomebodyWrote(): void
    {
        $this->receive();

        $row = $this->journalRows()[0];
        $this->assertSame('support_ticket_received', $row['type']);
        $this->assertSame('info', $row['level']);
        $this->assertStringNotContainsString('Mon import Desk', (string) $row['context']);
        $this->assertStringNotContainsString('chef@unite.be', (string) $row['context']);
        $this->assertStringContainsString('desk_import', (string) $row['context']);
    }

    // ── Refusals that are not rejections ────────────────────────────────

    /**
     * A category outside the closed list is refused — with a 200, because
     * retrying will not invent the category, and the answer carries the
     * list the caller should have used.
     */
    public function testACategoryOutsideTheListIsRefusedWithoutARetryableStatus(): void
    {
        $result = $this->receive(overrides: ['category' => 'quelque_chose']);

        $this->assertFalse($result->accepted);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame(TicketIntakeResult::REJECT_UNKNOWN_CATEGORY, $result->rejectionReason);
        $this->assertSame(0, $this->countTickets());
    }

    public function testAnEmptyDescriptionOrAMalformedAddressIsRefused(): void
    {
        $this->assertSame(
            TicketIntakeResult::REJECT_MALFORMED,
            $this->receive(overrides: ['description' => '   '])->rejectionReason
        );
        $this->assertSame(
            TicketIntakeResult::REJECT_MALFORMED,
            $this->receive(overrides: ['contact_email' => 'pas-une-adresse'])->rejectionReason
        );
        $this->assertSame(0, $this->countTickets());
    }

    public function testCleartextTransportIsRefusedBeforeAnythingElse(): void
    {
        $result = $this->service->receive($this->body(), 'Bearer ' . self::SECRET, '203.0.113.1', false);

        $this->assertSame(TicketIntakeResult::REJECT_INSECURE_TRANSPORT, $result->rejectionReason);
        $this->assertSame(0, $this->countTickets());
    }

    public function testAnOversizedBodyIsRefusedWithoutBeingParsed(): void
    {
        $huge = str_repeat('a', TicketIntakeService::MAX_BODY_BYTES + 1);

        $result = $this->service->receive($huge, 'Bearer ' . self::SECRET, '203.0.113.1', true);

        $this->assertSame(TicketIntakeResult::REJECT_PAYLOAD_TOO_LARGE, $result->rejectionReason);
    }

    /**
     * A description longer than the cap is kept, truncated — a ticket that
     * says most of what somebody wrote beats one that was thrown away for
     * being wordy.
     */
    public function testAnOverlongDescriptionIsTruncatedRatherThanRefused(): void
    {
        $result = $this->receive(overrides: [
            // ASCII on purpose: json_encode escapes « é » to six bytes,
            // so five thousand of them would trip the body cap instead and
            // this test would pass for the wrong reason.
            'description' => str_repeat('a', TicketIntakeService::MAX_DESCRIPTION_LENGTH + 500),
        ]);

        $this->assertTrue($result->accepted);
        $ticket = $this->tickets->find((int) $this->pdo->query('SELECT id FROM support_tickets')->fetchColumn());
        $this->assertNotNull($ticket);
        $this->assertSame(
            TicketIntakeService::MAX_DESCRIPTION_LENGTH,
            mb_strlen($ticket['description'])
        );
    }

    // ── Rate limiting ───────────────────────────────────────────────────

    public function testAnInstallationCannotFileMoreThanItsHourlyQuota(): void
    {
        for ($i = 0; $i < TicketIntakeService::RATE_LIMIT_MAX_TICKETS; $i++) {
            $this->assertTrue($this->receive()->accepted, 'ticket ' . $i . ' should be accepted');
        }

        $result = $this->receive();

        $this->assertFalse($result->accepted);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame(TicketIntakeResult::REJECT_RATE_LIMITED, $result->rejectionReason);
        $this->assertSame(TicketIntakeService::RATE_LIMIT_MAX_TICKETS, $this->countTickets());
    }

    /**
     * The window is what makes it a rate limit rather than a ceiling: a
     * ticket from before it no longer counts.
     */
    public function testTicketsOlderThanTheWindowNoLongerCount(): void
    {
        for ($i = 0; $i < TicketIntakeService::RATE_LIMIT_MAX_TICKETS; $i++) {
            $this->receive();
        }

        $this->pdo->exec(
            "UPDATE support_tickets SET created_at = '"
            . (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s') . "'"
        );

        $this->assertTrue($this->receive()->accepted);
    }

    // ── The notification the receiver gets (« un super admin doit être
    //    prévenu ») ───────────────────────────────────────────────────

    /**
     * The queue is not a mailbox anybody watches: without this, a ticket
     * sent on a Friday evening waited for somebody to think of opening
     * /support-dashboard/tickets.
     */
    public function testATicketTellsTheSuperadminsItArrived(): void
    {
        $userId = $this->seedUserAccount();

        $this->receive();

        $received = $this->notifications->findByUserAccountId($userId);

        $this->assertCount(1, $received);
        $this->assertSame('Nouveau ticket de support', $received[0]->title);
        $this->assertStringContainsString('Import Desk', $received[0]->body);
        $this->assertStringStartsWith('/support-dashboard/tickets/', (string) $received[0]->url);
    }

    /**
     * A notification becomes a push payload on a phone and, for whoever
     * switched that channel on, an e-mail. The description and the
     * contact address are exactly what it may never carry — the same rule
     * the journal entry beside it already obeys (SECURITY.md §11).
     */
    public function testTheNotificationCarriesNothingAnybodyWrote(): void
    {
        $userId = $this->seedUserAccount();

        $this->receive([
            'description' => 'Mon import Desk ne passe plus depuis hier.',
            'contact_email' => 'chef@unite.be',
        ]);

        $received = $this->notifications->findByUserAccountId($userId);
        $everything = $received[0]->title . ' ' . $received[0]->body . ' ' . (string) $received[0]->url;

        $this->assertStringNotContainsString('import Desk ne passe plus', $everything);
        $this->assertStringNotContainsString('chef@unite.be', $everything);
    }

    /**
     * Nothing wired a notification service: the ticket is stored and
     * journaled exactly as before rather than refused.
     */
    public function testWithoutANotificationServiceTheTicketStillLands(): void
    {
        $service = new TicketIntakeService(
            new SupportInstallationRepository($this->pdo),
            $this->tickets,
            new JournalService(new JournalRepository($this->pdo))
        );

        $result = $service->receive($this->body(), 'Bearer ' . self::SECRET, '203.0.113.1', true);

        $this->assertTrue($result->accepted);
        $this->assertSame(1, $this->countTickets());
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function seedUserAccount(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)'
        );
        $stmt->execute([
            $this->encryption->encrypt('chef@unite.be', 'user_accounts.email'),
            $this->encryption->blindIndex(EncryptionService::normalizeEmailForIndex('chef@unite.be'), 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function body(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'installation_id' => self::INSTALLATION_ID,
            'category' => 'desk_import',
            'description' => 'Mon import Desk ne passe plus depuis hier.',
            'contact_email' => 'chef@unite.be',
            'site_version' => '1.0.33',
            'php_version' => '8.4.3',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function receive(array $overrides = [], string $secret = self::SECRET): TicketIntakeResult
    {
        return $this->service->receive($this->body($overrides), 'Bearer ' . $secret, '203.0.113.1', true);
    }

    private function countTickets(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalRows(): array
    {
        return $this->pdo->query(
            "SELECT event_type AS type, level, context FROM event_log WHERE category = 'support_dashboard' ORDER BY id ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function journalTypesAndLevels(): array
    {
        return array_map(
            static fn(array $row): array => [(string) $row['type'], (string) $row['level']],
            $this->journalRows()
        );
    }
}
