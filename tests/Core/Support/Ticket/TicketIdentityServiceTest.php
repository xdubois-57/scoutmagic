<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Support\Ticket\TicketIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Roadmap IT-24: opening a support ticket must not cost a unit its
 * telemetry decision.
 *
 * The three properties: an installation with no identity gets one and the
 * daily report stays off; the secret goes into `secrets.enc` and nowhere
 * a superadmin browsing Configuration > Réglages could read it; and the
 * destination has to satisfy the same guards a usage report satisfies,
 * by calling them rather than by copying them.
 */
class TicketIdentityServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private string $projectRoot;
    /** @var array<int, array{type: string, context: ?string}> */
    private array $journalEntries = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-ticket-identity-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->settings->register(
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            '',
            'text',
            'Identifiant',
            '',
            null,
            null,
            null,
            false
        );
        $this->settings->register('statistics_enabled', '0', 'boolean', 'Statistiques', '');
        $this->settings->register('statistics_destination', 'https://scoutmagic.be', 'text', 'Destination', '');
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

    public function testAnInstallationWithoutTelemetryGetsAnIdentityAndKeepsTheReportOff(): void
    {
        $service = $this->service();

        $this->assertFalse($service->telemetryEnabled());

        $identity = $service->ensureIdentity();

        $this->assertNotNull($identity);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $identity->installationId);
        $this->assertNotSame('', $identity->secret);

        // The whole point: nothing turned the daily report on.
        $this->assertFalse($service->telemetryEnabled());
        $this->assertSame('0', (string) $this->settings->get('statistics_enabled'));
    }

    /**
     * The secret is a credential. A `settings` row would be readable by
     * any superadmin on the generic Réglages page; `secrets.enc` is where
     * every other credential of this codebase lives.
     */
    public function testTheSecretGoesOnlyIntoTheEncryptedStore(): void
    {
        $identity = $this->service()->ensureIdentity();
        $this->assertNotNull($identity);

        $secrets = $this->secretManager->readSecrets();
        $this->assertSame($identity->secret, $secrets[InstallationIdentityService::SECRET_NAME]);

        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $this->assertNotSame(
                $identity->secret,
                (string) $row['setting_value'],
                'the secret must never reach a settings row: ' . $row['setting_key']
            );
        }
    }

    /**
     * An identity appearing on an installation that never reported is a
     * fact an operator should be able to find later — and the entry is
     * where "we did not switch telemetry on" is written down.
     */
    public function testProvisioningIsJournaledOnceAndNeverTheSecret(): void
    {
        $service = $this->service();

        $identity = $service->ensureIdentity();
        $this->assertNotNull($identity);
        $this->assertSame(['support_identity_provisioned'], array_column($this->journalEntries, 'type'));
        $this->assertStringNotContainsString($identity->secret, (string) $this->journalEntries[0]['context']);

        // A second ticket provisions nothing and says nothing.
        $service->ensureIdentity();
        $this->assertCount(1, $this->journalEntries);
    }

    public function testAnIdentityThatAlreadyExistsIsReusedAsIs(): void
    {
        $this->settings->setInternal(InstallationIdentityService::INSTALLATION_ID_SETTING, str_repeat('a', 32));
        $this->secretManager->writeSecrets([InstallationIdentityService::SECRET_NAME => 'un-secret-existant']);

        $identity = $this->service()->ensureIdentity();

        $this->assertNotNull($identity);
        $this->assertSame(str_repeat('a', 32), $identity->installationId);
        $this->assertSame('un-secret-existant', $identity->secret);
        $this->assertSame([], $this->journalEntries, 'nothing was provisioned, so nothing is announced');
    }

    // ── The destination guards, called rather than copied ───────────────

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function destinationProvider(): array
    {
        return [
            'a real receiver' => ['https://scoutmagic.be', null],
            'nothing configured' => ['', TicketIdentityService::GUARD_NO_DESTINATION],
            'cleartext' => ['http://scoutmagic.be', TicketIdentityService::GUARD_INSECURE_DESTINATION],
            'localhost' => ['https://localhost', TicketIdentityService::GUARD_NON_PUBLIC_DESTINATION],
            'a bare address' => ['https://10.0.0.5', TicketIdentityService::GUARD_NON_PUBLIC_DESTINATION],
            'a single label' => ['https://intranet', TicketIdentityService::GUARD_NON_PUBLIC_DESTINATION],
            'a reserved suffix' => ['https://support.test', TicketIdentityService::GUARD_NON_PUBLIC_DESTINATION],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('destinationProvider')]
    public function testTheDestinationMustSatisfyWhatAUsageReportSatisfies(string $destination, ?string $expected): void
    {
        $this->settings->setInternal('statistics_destination', $destination);

        $this->assertSame($expected, $this->service()->firstFailingGuard());
    }

    public function testTheEndpointIsTheReceiversTicketRouteOrNothingAtAll(): void
    {
        $this->assertSame('https://scoutmagic.be/api/support/tickets', $this->service()->endpoint());

        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');
        $this->assertNull($this->service()->endpoint(), 'a refused destination has no endpoint at all');
    }

    /** A trailing slash on the configured value must not double up. */
    public function testATrailingSlashOnTheDestinationIsHarmless(): void
    {
        $this->settings->setInternal('statistics_destination', 'https://scoutmagic.be/');

        $this->assertSame('https://scoutmagic.be/api/support/tickets', $this->service()->endpoint());
    }

    private function service(): TicketIdentityService
    {
        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{type: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
                $this->entries[] = compact('type', 'context');
            }
        };

        return new TicketIdentityService(
            $this->settings,
            new InstallationIdentityService($this->settings, $this->secretManager),
            new JournalService($journalRepository)
        );
    }
}
