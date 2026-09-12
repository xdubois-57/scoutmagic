<?php

declare(strict_types=1);

namespace Tests\Core\Statistics;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class InstallationIdentityServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $tempDir;
    private SecretManager $secretManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);
        // The service's own declaration, not a hand-copy of it. A second
        // copy here would keep passing on the day the real one changed —
        // and it is the real one that every caller depends on.
        InstallationIdentityService::register($this->settings);

        $this->tempDir = sys_get_temp_dir() . '/scoutmagic-identity-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/keys', 0700, true);
        mkdir($this->tempDir . '/config', 0700, true);
        $this->secretManager = new SecretManager(
            $this->tempDir . '/keys/master.key',
            $this->tempDir . '/config/secrets.enc'
        );
    }

    /**
     * Both rows, declared by the service that owns them.
     *
     * They are registered together because they are written together: a
     * portable restore mints a new identifier and records the old one in
     * the same breath, and the installation wizard has to make this same
     * declaration itself — the database it just restored is the origin's,
     * and an origin on an older ScoutMagic never had the second row.
     */
    public function testItDeclaresBothOfTheSettingsItOwns(): void
    {
        foreach ([
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            InstallationIdentityService::RESTORED_FROM_SETTING,
        ] as $key) {
            $row = $this->settingRepository->findByModuleAndKey(null, $key);
            $this->assertIsArray($row, $key . ' is not registered, so nothing can write to it.');
            $this->assertSame('', $row['setting_value'], $key . ' does not start empty.');
            // Neither is a per-unit choice, and neither belongs on the
            // generic Réglages page.
            $this->assertSame(0, (int) $row['editable'], $key . ' is offered for editing.');
        }
    }

    /** Registering twice is what every boot does, and must change nothing. */
    public function testRegisteringAgainDoesNotDisturbAValueAlreadyRecorded(): void
    {
        $this->settingRepository->updateValue(
            null,
            InstallationIdentityService::RESTORED_FROM_SETTING,
            'aaaabbbbccccddddeeeeffff00001111'
        );

        InstallationIdentityService::register($this->settings);
        $this->settings->clearCache();

        $this->assertSame(
            'aaaabbbbccccddddeeeeffff00001111',
            $this->settings->get(InstallationIdentityService::RESTORED_FROM_SETTING)
        );
    }

    protected function tearDown(): void
    {
        foreach (['/config/secrets.enc', '/keys/master.key'] as $file) {
            if (is_file($this->tempDir . $file)) {
                unlink($this->tempDir . $file);
            }
        }
        foreach (['/config', '/keys', ''] as $dir) {
            if (is_dir($this->tempDir . $dir)) {
                rmdir($this->tempDir . $dir);
            }
        }
    }

    private function initializeSecrets(): void
    {
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_host' => 'localhost']);
    }

    private function service(?JournalService $journal = null): InstallationIdentityService
    {
        return new InstallationIdentityService($this->settings, $this->secretManager, $journal);
    }

    public function testInstallationIdIs32HexCharactersAndIsPersisted(): void
    {
        $id = $this->service()->getInstallationId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);

        $row = $this->settingRepository->findByModuleAndKey(null, InstallationIdentityService::INSTALLATION_ID_SETTING);
        $this->assertNotNull($row);
        $this->assertSame($id, $row['setting_value']);
    }

    public function testInstallationIdIsStableAcrossCalls(): void
    {
        $service = $this->service();
        $this->assertSame($service->getInstallationId(), $service->getInstallationId());
    }

    public function testInstallationIdIsStableAcrossSeparateServiceInstances(): void
    {
        $first = $this->service()->getInstallationId();

        $otherSettings = new SettingService(new SettingRepository($this->pdo));
        $second = (new InstallationIdentityService($otherSettings, $this->secretManager))->getInstallationId();

        $this->assertSame($first, $second);
    }

    public function testAConcurrentWriterWinsAndIsAdopted(): void
    {
        // Simulates the losing side of the race: another request persisted an
        // identifier between this one reading "empty" and claiming its own.
        $winner = str_repeat('ab', 16);
        $this->settingRepository->updateValue(null, InstallationIdentityService::INSTALLATION_ID_SETTING, $winner);

        $this->assertSame($winner, $this->service()->getInstallationId());
    }

    public function testMissingSettingRowIsALoudError(): void
    {
        $this->pdo->exec('DELETE FROM settings');
        $this->settings->clearCache();

        $this->expectException(StatisticsException::class);
        $this->service()->getInstallationId();
    }

    public function testSecretIsNullWhileTheSiteIsNotInitialized(): void
    {
        $this->assertNull($this->service()->getSecret());
    }

    public function testSecretIs64HexCharactersAndIsStableAcrossCalls(): void
    {
        $this->initializeSecrets();
        $service = $this->service();

        $secret = $service->getSecret();
        $this->assertNotNull($secret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame($secret, $service->getSecret());
    }

    public function testSecretIsStoredOnlyInSecretsEncAndNeverInSettings(): void
    {
        $this->initializeSecrets();
        $secret = $this->service()->getSecret();
        $this->assertNotNull($secret);

        $this->assertSame($secret, $this->secretManager->readSecrets()[InstallationIdentityService::SECRET_NAME]);

        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $this->assertNotSame($secret, (string) $row['setting_value']);
            $this->assertStringNotContainsString('secret', (string) $row['setting_key']);
        }
    }

    public function testGeneratingTheSecretDoesNotDropOtherSecrets(): void
    {
        $this->initializeSecrets();
        $this->service()->getSecret();

        $this->assertSame('localhost', $this->secretManager->readSecrets()['db_host']);
    }

    public function testRegenerateReplacesBothAndJournalsAtSecurityLevel(): void
    {
        $this->initializeSecrets();
        $journal = new JournalService(new JournalRepository($this->pdo));
        $service = $this->service($journal);

        $idBefore = $service->getInstallationId();
        $secretBefore = $service->getSecret();

        $service->regenerate();
        $this->settings->clearCache();

        $this->assertNotSame($idBefore, $service->getInstallationId());
        $this->assertNotSame($secretBefore, $service->getSecret());

        $stmt = $this->pdo->query("SELECT event_type, level, description, context FROM event_log");
        $entries = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $this->assertCount(1, $entries);
        $this->assertSame('statistics_identity_regenerated', $entries[0]['event_type']);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertStringNotContainsString((string) $secretBefore, json_encode($entries[0]) ?: '');
    }

    /**
     * `getSecret()` returns null rather than throwing while `secrets.enc`
     * cannot be read — the first-run installer and the Support page's
     * payload preview both call it, and neither may fatal there. A corrupt
     * file is the same situation as a missing one from the caller's point
     * of view: no report can be sent yet.
     */
    public function testAnUnreadableSecretsFileYieldsNullRatherThanAFatal(): void
    {
        $this->initializeSecrets();
        file_put_contents($this->tempDir . '/config/secrets.enc', 'ceci n\'est pas un blob chiffré');

        $this->assertNull($this->service()->getSecret());
    }

    /**
     * A stored value that is not 32 hex characters is not an identifier —
     * it is corruption, and adopting it would register this installation on
     * the receiver under something meaningless. A fresh one is claimed.
     */
    public function testACorruptedStoredIdentifierIsReplacedRatherThanAdopted(): void
    {
        $this->settingRepository->updateValue(null, InstallationIdentityService::INSTALLATION_ID_SETTING, 'pas-un-identifiant');
        $this->settings->clearCache();

        $id = $this->service()->getInstallationId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testSurroundingWhitespaceOnAStoredIdentifierIsTolerated(): void
    {
        $stored = str_repeat('ab', 16);
        $this->settingRepository->updateValue(null, InstallationIdentityService::INSTALLATION_ID_SETTING, "  {$stored}\n");
        $this->settings->clearCache();

        $this->assertSame($stored, $this->service()->getInstallationId());
    }

    /**
     * Regenerating before the site has any secrets at all still gives the
     * installation a new identifier: the first run of `getSecret()` after
     * initialisation will mint the matching secret, and the receiver treats
     * the pair as a first registration either way.
     */
    public function testRegenerateWorksBeforeTheSiteIsInitialized(): void
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->service($journal)->regenerate();

        $this->settings->clearCache();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            (string) $this->settings->get(InstallationIdentityService::INSTALLATION_ID_SETTING)
        );
        $this->assertFalse($this->secretManager->isInitialized());
    }

    /**
     * The secret is a credential: whatever else regenerate() writes, it
     * must never write that.
     */
    public function testRegenerateNeverWritesTheSecretIntoSettingsOrTheJournal(): void
    {
        $this->initializeSecrets();
        $journal = new JournalService(new JournalRepository($this->pdo));

        $service = $this->service($journal);
        $service->getSecret();
        $service->regenerate();

        $secret = (string) $service->getSecret();
        $this->assertNotSame('', $secret);

        $settingsDump = (string) json_encode(
            $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $journalDump = (string) json_encode(
            $this->pdo->query('SELECT event_type, description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC)
        );

        $this->assertStringNotContainsString($secret, $settingsDump);
        $this->assertStringNotContainsString($secret, $journalDump);
    }
}
