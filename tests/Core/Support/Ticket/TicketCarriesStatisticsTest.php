<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\Ticket\SupportTicketSender;
use Core\Support\Ticket\TicketIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A ticket carries the usage report that explains it.
 *
 * **Why it travels inside the ticket rather than beside it.** A report
 * sent as its own call arrives as its own event, and nothing ties it to
 * the ticket it was meant to explain — which is exactly what happened on
 * the live receiver: a report landed, a ticket landed, and the two could
 * not be matched. One body, one transmission, no window in which they
 * disagree.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TicketCarriesStatisticsTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-ticket-stats-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        foreach ([
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            SupportTicketSender::LAST_REFERENCE_SETTING,
            SupportTicketSender::LAST_SENT_AT_SETTING,
            SupportTicketSender::CATEGORIES_SETTING,
        ] as $key) {
            $this->settings->register($key, '', 'text', 'L', 'D', null, null, null, false);
        }
        $this->settings->register('statistics_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
    }

    protected function tearDown(): void
    {
        foreach (['/storage/config/secrets.enc', '/storage/keys/master.key'] as $file) {
            @unlink($this->projectRoot . $file);
        }
        foreach (['/storage/config', '/storage/keys', '/storage', ''] as $dir) {
            @rmdir($this->projectRoot . $dir);
        }
    }

    public function testTheReportTravelsInsideTheTicketBody(): void
    {
        $transport = new RecordingStatsTicketTransport(StatisticsTransportResponse::response(
            200,
            (string) json_encode(['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2'])
        ));

        $result = $this->sender($transport)->send('desk_import', 'Mon import ne passe plus.', 'chef@unite.be');

        $this->assertTrue($result->sent);

        $body = json_decode($transport->calls[0]['body'], true);
        $this->assertIsArray($body['statistics'] ?? null, 'the ticket must carry its own report');
        // The real document, not a stub: the receiver reads it through the
        // same denormalisation an ordinary report goes through, so the
        // nesting has to be the builder's own.
        $this->assertArrayHasKey('statistics_schema_version', $body['statistics']);
        $this->assertArrayHasKey('version', $body['statistics']['scoutmagic'] ?? []);
        $this->assertArrayHasKey('php_version', $body['statistics']['runtime'] ?? []);
    }

    /**
     * The whole reason this is defensible: the daily report stays off. The
     * report leaves because somebody pressed a button, not because a task
     * ran (roadmap IT-24's rule, unchanged).
     */
    public function testItDoesNotTurnTheDailyReportOn(): void
    {
        $this->sender($this->accepting())->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertSame('0', (string) $this->settings->get('statistics_enabled'));
    }

    /**
     * A sender built without a payload builder still sends a ticket: the
     * report is context, never the point.
     */
    public function testATicketStillGoesWithoutABuilder(): void
    {
        $transport = $this->accepting();

        $result = new SupportTicketSender(
            $this->settings,
            $this->identityService(),
            $transport,
            new JournalService(new JournalRepository($this->pdo)),
            '1.0.40'
        );

        $this->assertTrue($result->send('other', 'Bonjour', 'chef@unite.be')->sent);
        $body = json_decode($transport->calls[0]['body'], true);
        $this->assertNull($body['statistics']);
    }

    private function accepting(): RecordingStatsTicketTransport
    {
        return new RecordingStatsTicketTransport(StatisticsTransportResponse::response(
            200,
            (string) json_encode(['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2'])
        ));
    }

    private function identityService(): TicketIdentityService
    {
        $this->settings->setInternal(
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            'unite-de-test'
        );

        return new TicketIdentityService(
            $this->settings,
            new InstallationIdentityService($this->settings, $this->secretManager),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function sender(RecordingStatsTicketTransport $transport): SupportTicketSender
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new SupportTicketSender(
            $this->settings,
            $this->identityService(),
            $transport,
            $journal,
            '1.0.40',
            new StatisticsPayloadBuilder(
                $this->settings,
                $this->pdo,
                new InstallationIdentityService($this->settings, $this->secretManager),
                $this->projectRoot
            )
        );
    }
}

/**
 * Records what was sent and answers what the test told it to.
 */
final class RecordingStatsTicketTransport implements \Core\Statistics\StatisticsTransportInterface
{
    /** @var array<int, array{url: string, body: string, token: string}> */
    public array $calls = [];

    public function __construct(private StatisticsTransportResponse $response)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'token' => $bearerToken];

        return $this->response;
    }
}
