<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\Ticket\SupportTicketSender;
use Core\Support\Ticket\TicketCategories;
use Core\Support\Ticket\TicketIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What leaves the installation when somebody opens a ticket, and what is
 * kept when they do (roadmap IT-25).
 *
 * The properties worth pinning are all about restraint: the secret travels
 * in a header and never in the body, the description never reaches the
 * journal, and nothing is recorded as « Envoyé » unless the receiver said
 * so.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportTicketSenderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private string $projectRoot;

    private const SECRET_LENGTH = 64;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-ticket-sender-' . bin2hex(random_bytes(6));
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

    public function testWhatLeavesIsTheTicketAndTheIdentityAndNothingElse(): void
    {
        $transport = $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']);

        $result = $this->sender($transport)->send('desk_import', 'Mon import ne passe plus.', 'chef@unite.be');

        $this->assertTrue($result->sent);
        $this->assertSame('SUP-7KQ4F2', $result->reference);

        $call = $transport->calls[0];
        $this->assertSame('https://www.scoutmagic.be/api/support/tickets', $call['url']);

        $body = json_decode($call['body'], true);
        $this->assertSame(
            ['installation_id', 'category', 'description', 'contact_email', 'site_version', 'php_version'],
            array_keys($body)
        );
        $this->assertSame('desk_import', $body['category']);
        $this->assertSame('1.0.33', $body['site_version']);

        // The secret authenticates the call and never travels in the body.
        $this->assertNotSame('', $call['token']);
        $this->assertStringNotContainsString($call['token'], $call['body']);
    }

    public function testTheReferenceAndTheDateAreKeptForTheOnlyLocalStatusThereIs(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertSame('SUP-7KQ4F2', (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING));
        $this->assertNotSame('', (string) $this->settings->get(SupportTicketSender::LAST_SENT_AT_SETTING));

        $sender = $this->sender($this->transport(200, []));
        $last = $sender->lastSent();
        $this->assertNotNull($last);
        $this->assertSame('SUP-7KQ4F2', $last['reference']);
    }

    /**
     * @return array<string, array{int, array<string, mixed>|string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'the receiver never answered' => [503, 'oops', SupportTicketSender::FAILURE_UNREACHABLE],
            'it answered something unreadable' => [200, 'pas du json', SupportTicketSender::FAILURE_MALFORMED_ANSWER],
            'it refused' => [200, ['status' => 'refused', 'reason' => 'unknown_category'], SupportTicketSender::FAILURE_REFUSED],
            'it accepted without saying which ticket' => [200, ['status' => 'accepted'], SupportTicketSender::FAILURE_MALFORMED_ANSWER],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testNothingIsRecordedAsSentUnlessTheReceiverSaidSo(
        int $status,
        array|string $body,
        string $expectedReason
    ): void {
        $transport = is_string($body)
            ? new RecordingTicketTransport(StatisticsTransportResponse::response($status, $body))
            : $this->transport($status, $body);

        $result = $this->sender($transport)->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertFalse($result->sent);
        $this->assertSame($expectedReason, $result->failureReason);
        $this->assertSame('', (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING));
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'support_ticket_sent'")->fetchColumn()
        );
    }

    /**
     * A refusal still teaches the instance the vocabulary it should have
     * used — which is the whole point of publishing the list on every
     * answer.
     */
    public function testARefusalStillTeachesTheCategoryList(): void
    {
        $this->sender($this->transport(200, [
            'status' => 'refused',
            'reason' => 'unknown_category',
            'categories' => [['value' => 'nouvelle', 'label' => 'Nouvelle']],
        ]))->send('inexistante', 'Bonjour', 'chef@unite.be');

        $this->assertSame(
            [['value' => 'nouvelle', 'label' => 'Nouvelle']],
            $this->sender($this->transport(200, []))->categories()
        );
    }

    public function testTheShippedListIsWhatAnInstallationOffersBeforeItHasHeardOne(): void
    {
        $this->assertSame(TicketCategories::shipped(), $this->sender($this->transport(200, []))->categories());
    }

    /**
     * One malformed entry discredits the stored list: a picker half-built
     * from a corrupted setting is worse than the shipped one.
     */
    public function testAStoredListThatIsNotOneFallsBackToWhatWasShipped(): void
    {
        $this->settings->setInternal(SupportTicketSender::CATEGORIES_SETTING, '[{"value":"x"},{"nope":1}]');

        $this->assertSame(TicketCategories::shipped(), $this->sender($this->transport(200, []))->categories());
    }

    public function testTheJournalEntryCarriesTheReferenceAndNotAWordOfTheDescription(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('desk_import', 'Une phrase que personne ne doit relire ici.', 'chef@unite.be');

        $row = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'support_ticket_sent'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('info', $row['level']);
        $this->assertStringContainsString('SUP-7KQ4F2', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['description']);
        $this->assertStringNotContainsString('chef@unite.be', (string) $row['context']);
    }

    /**
     * The guards belong to the report and are called, not copied: a
     * destination that is not HTTPS stops the send before a socket is
     * opened.
     */
    public function testAGuardStopsTheSendBeforeAnythingLeaves(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');
        $transport = $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-1']);

        $result = $this->sender($transport)->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertFalse($result->sent);
        $this->assertSame(TicketIdentityService::GUARD_INSECURE_DESTINATION, $result->failureReason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * Sending a ticket provisions the identity and leaves the daily report
     * exactly as it found it (roadmap IT-24).
     */
    public function testSendingATicketNeverEnablesTheDailyReport(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertSame('0', (string) $this->settings->get('statistics_enabled'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            (string) $this->settings->get(InstallationIdentityService::INSTALLATION_ID_SETTING)
        );
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function transport(int $status, array $answer): RecordingTicketTransport
    {
        return new RecordingTicketTransport(
            StatisticsTransportResponse::response($status, (string) json_encode($answer))
        );
    }

    private function sender(StatisticsTransportInterface $transport): SupportTicketSender
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new SupportTicketSender(
            $this->settings,
            new TicketIdentityService(
                $this->settings,
                new InstallationIdentityService($this->settings, $this->secretManager),
                $journal
            ),
            $transport,
            $journal,
            '1.0.33'
        );
    }
}

/**
 * Records what was sent and answers what the test told it to.
 */
final class RecordingTicketTransport implements StatisticsTransportInterface
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
