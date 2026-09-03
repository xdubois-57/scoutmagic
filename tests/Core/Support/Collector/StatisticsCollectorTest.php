<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Support\Collector\StatisticsCollector;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Two properties of `statistics.json`, both of which are behaviours the
 * codebase already had and neither of which anything asserted — which is
 * how a behaviour becomes an accident.
 *
 * 1. **The opt-out stops the automatic send, and only that.** Somebody who
 *    asks for help wants precisely that the maintainer can see what is
 *    running at their place, and they trigger the transmission themselves
 *    by attaching the archive. Emptying the package because the daily
 *    report is off would answer a question nobody asked.
 * 2. **The package's document is the report's document.** The collector
 *    calls the same builder the daily send calls, so the two cannot say
 *    different things — a second collector would diverge at the first
 *    change.
 */
class StatisticsCollectorTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private string $projectRoot;
    private SecretManager $secretManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-collector-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);
    }

    protected function tearDown(): void
    {
        foreach (['/storage/config/secrets.enc', '/storage/keys/master.key', '/VERSION'] as $file) {
            @unlink($this->projectRoot . $file);
        }
        @rmdir($this->projectRoot . '/storage/config');
        @rmdir($this->projectRoot . '/storage/keys');
        @rmdir($this->projectRoot . '/storage');
        @rmdir($this->projectRoot);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('optOutStates')]
    public function testTheReportIsWrittenWhateverTheDailySendSettingSays(string $enabled): void
    {
        $this->settings->register('statistics_enabled', $enabled, 'boolean', 'Envoi', 'Envoi quotidien');

        $entries = $this->collect();

        $this->assertArrayHasKey('statistics.json', $entries);

        $decoded = json_decode($entries['statistics.json'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('installation_id', $decoded);
        $this->assertArrayHasKey('module_usage', $decoded);
    }

    /** @return array<string, array{0: string}> */
    public static function optOutStates(): array
    {
        return ['reporting on' => ['1'], 'reporting off' => ['0']];
    }

    /**
     * @return array<string, string> archive entry name => content
     */
    private function collect(): array
    {
        $zipPath = sys_get_temp_dir() . '/scoutmagic-collector-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zipPath, \ZipArchive::CREATE) === true);

        $context = new SupportCollectorContext(
            $archive,
            Connection::withPdo($this->pdo),
            $this->settings,
            $this->projectRoot,
            $this->projectRoot . '/storage'
        );

        (new StatisticsCollector(new StatisticsPayloadBuilder(
            $this->settings,
            $this->pdo,
            new InstallationIdentityService($this->settings, $this->secretManager),
            $this->projectRoot
        )))->collect($context);

        $archive->close();

        $read = new \ZipArchive();
        $this->assertTrue($read->open($zipPath) === true);
        $entries = [];
        for ($i = 0; $i < $read->numFiles; $i++) {
            $name = (string) $read->getNameIndex($i);
            $entries[$name] = (string) $read->getFromName($name);
        }
        $read->close();
        @unlink($zipPath);

        return $entries;
    }
}
