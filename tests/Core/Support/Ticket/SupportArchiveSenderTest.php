<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\SupportPackageState;
use Core\Support\Ticket\ArchiveTransportInterface;
use Core\Support\Ticket\SupportArchiveSender;
use Core\Support\Ticket\TicketIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Transmitting the diagnostic archive (roadmap IT-26).
 *
 * This is the iteration that reverses a promise the codebase made in
 * four places, so what the tests pin is the shape of the exception: it
 * happens **only** on an explicit, server-verified acknowledgement, it
 * never costs the ticket, and it is written down at `security` level
 * because it is the moment server logs left the installation.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportArchiveSenderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private EncryptedFileStorageService $storage;
    private string $projectRoot;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-archive-sender-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $encryption,
            $this->projectRoot . '/storage'
        );

        foreach ([
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            SupportPackageState::FILE_ID,
            SupportArchiveSender::ARCHIVE_SENT_AT_SETTING,
            SupportArchiveSender::ARCHIVE_REFERENCE_SETTING,
        ] as $key) {
            $this->settings->register($key, '', 'text', 'L', 'D', null, null, null, false);
        }
        $this->settings->register('statistics_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');

        $this->fileId = $this->storage->store(
            'PK-des-octets-darchive',
            'application/zip',
            'support-package.zip',
            'core/support',
            'superadmin'
        );
        $this->settings->setInternal(SupportPackageState::FILE_ID, (string) $this->fileId);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectRoot . '/storage/core/support/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (['/storage/config/secrets.enc', '/storage/keys/master.key'] as $file) {
            @unlink($this->projectRoot . $file);
        }
        foreach (['/storage/core/support', '/storage/core', '/storage/config', '/storage/keys', '/storage', ''] as $dir) {
            @rmdir($this->projectRoot . $dir);
        }
    }

    /**
     * The whole consent, verified where it counts. A checkbox enforced
     * only in the browser is a decoration.
     */
    public function testWithoutTheAcknowledgementNothingLeavesAtAll(): void
    {
        $transport = $this->transport(200, ['status' => 'accepted']);

        $result = $this->sender($transport)->send('SUP-7KQ4F2', false);

        $this->assertFalse($result->sent);
        $this->assertSame(SupportArchiveSender::FAILURE_NOT_ACKNOWLEDGED, $result->failureReason);
        $this->assertSame([], $transport->calls);
        $this->assertSame(0, $this->journalCount());
    }

    public function testAnAcknowledgedArchiveTravelsToItsOwnTicketRoute(): void
    {
        $transport = $this->transport(200, ['status' => 'accepted']);

        $result = $this->sender($transport)->send('SUP-7KQ4F2', true);

        $this->assertTrue($result->sent);
        $this->assertSame(
            'https://www.scoutmagic.be/api/support/tickets/SUP-7KQ4F2/archive',
            $transport->calls[0]['url']
        );
        $this->assertSame('PK-des-octets-darchive', $transport->calls[0]['bytes']);
        $this->assertNotSame('', $transport->calls[0]['token']);
    }

    /**
     * The archive is a unit's server logs leaving its own installation.
     * An audit should find that beside decisions of that weight, not
     * among the routine ones.
     */
    public function testTheTransmissionIsJournaledAsASecurityEvent(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted']))->send('SUP-7KQ4F2', true);

        $row = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'support_archive_transmitted'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('security', $row['level']);
        $this->assertStringContainsString('SUP-7KQ4F2', (string) $row['description']);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'the receiver never answered' => [503, [], SupportArchiveSender::FAILURE_UNREACHABLE],
            'it refused' => [200, ['status' => 'refused', 'reason' => 'archive_too_large'], SupportArchiveSender::FAILURE_REFUSED],
        ];
    }

    /**
     * The reason the two calls are separate: a failed upload costs
     * nothing, and what is recorded is « not transmitted », never a
     * half-truth.
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testAFailedUploadRecordsNothingAndCanBeRetried(int $status, array $answer, string $expected): void
    {
        $failing = $this->transport($status, $answer);
        $result = $this->sender($failing)->send('SUP-7KQ4F2', true);

        $this->assertFalse($result->sent);
        $this->assertSame($expected, $result->failureReason);
        $this->assertFalse($this->sender($failing)->wasTransmittedFor('SUP-7KQ4F2'));
        $this->assertSame(0, $this->journalCount());

        // And the retry works, on the same archive, with nothing to clean
        // up in between.
        $this->assertTrue($this->sender($this->transport(200, ['status' => 'accepted']))->send('SUP-7KQ4F2', true)->sent);
    }

    public function testWithNoArchiveOnDiskNothingIsAttempted(): void
    {
        $this->settings->setInternal(SupportPackageState::FILE_ID, '');
        $transport = $this->transport(200, ['status' => 'accepted']);

        $result = $this->sender($transport)->send('SUP-7KQ4F2', true);

        $this->assertSame(SupportArchiveSender::FAILURE_NO_ARCHIVE, $result->failureReason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * The mark belongs to the ticket it was sent for: a later ticket has
     * had no archive, and must read that way.
     */
    public function testTheTransmittedMarkBelongsToOneTicket(): void
    {
        $sender = $this->sender($this->transport(200, ['status' => 'accepted']));
        $sender->send('SUP-7KQ4F2', true);

        $this->assertTrue($sender->wasTransmittedFor('SUP-7KQ4F2'));
        $this->assertFalse($sender->wasTransmittedFor('SUP-AUTRE1'));
    }

    /**
     * The report's own guards, called here too: an archive is exactly the
     * payload that must never leave over cleartext.
     */
    public function testACleartextDestinationStopsItBeforeAnyByteLeaves(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');
        $transport = $this->transport(200, ['status' => 'accepted']);

        $result = $this->sender($transport)->send('SUP-7KQ4F2', true);

        $this->assertSame(TicketIdentityService::GUARD_INSECURE_DESTINATION, $result->failureReason);
        $this->assertSame([], $transport->calls);
    }

    private function journalCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'support_archive_transmitted'"
        )->fetchColumn();
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function transport(int $status, array $answer): RecordingArchiveTransport
    {
        return new RecordingArchiveTransport(
            StatisticsTransportResponse::response($status, (string) json_encode($answer))
        );
    }

    private function sender(ArchiveTransportInterface $transport): SupportArchiveSender
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new SupportArchiveSender(
            $this->settings,
            new TicketIdentityService(
                $this->settings,
                new InstallationIdentityService($this->settings, $this->secretManager),
                $journal
            ),
            $this->storage,
            $transport,
            $journal,
            '1.0.33'
        );
    }
}

final class RecordingArchiveTransport implements ArchiveTransportInterface
{
    /** @var array<int, array{url: string, bytes: string, token: string}> */
    public array $calls = [];

    public function __construct(private StatisticsTransportResponse $response)
    {
    }

    public function postArchive(
        string $url,
        string $bytes,
        string $bearerToken,
        string $userAgent
    ): StatisticsTransportResponse {
        $this->calls[] = ['url' => $url, 'bytes' => $bytes, 'token' => $bearerToken];

        return $this->response;
    }
}
