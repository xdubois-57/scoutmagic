<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Support\Collector\OutboundMailCollector;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `outbound-mail.txt` — what a support archive may say about how this
 * installation's mail leaves, and above all what it may never say
 * (ARCHITECTURE.md §8.106, §8.48).
 *
 * The archive goes to a third party. So the assertion that matters most
 * here is the negative one: no credential, no recipient. It is asserted
 * against a fixture whose password and username are distinctive strings,
 * so a future edit that starts printing a provider's connection fails
 * this test rather than shipping quietly.
 *
 * @group database
 */
class OutboundMailCollectorTest extends TestCase
{
    private \PDO $pdo;
    private string $projectRoot;
    private string $storagePath;
    private Connection $connection;
    private SettingService $settings;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SendCounterRepository $counters;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->counters = new SendCounterRepository($this->pdo);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-outbound-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0700, true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    public function testItReportsEveryProviderTheChainsAndTheCounters(): void
    {
        $relay = $this->addRelay('Brevo', 'smtp-relay.brevo.test', dailyQuota: 300);
        $this->chains->append(MailLane::Bulk, $relay, true);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, false);
        $this->counters->increment($relay, MailLane::Bulk);

        $report = $this->collect();

        $this->assertStringContainsString('Brevo', $report);
        $this->assertStringContainsString('smtp-relay.brevo.test', $report, 'A host is a server, not a person.');
        $this->assertStringContainsString('300', $report, 'The declared quota.');
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $report);
        $this->assertStringContainsString('[actif]', $report);
        $this->assertStringContainsString('[désactivé]', $report);
        $this->assertStringContainsString('bulk', $report, 'The counter row names its lane.');
    }

    /**
     * The one assertion this file exists for.
     */
    public function testItNeverCarriesACredential(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->chains->append(MailLane::Authentication, $relay, true);

        $report = $this->collect();

        $this->assertStringNotContainsString('MOT-DE-PASSE-SECRET', $report);
        $this->assertStringNotContainsString('identifiant-secret@relais.test', $report);
        $this->assertStringContainsString('identifiant : configuré', $report, 'Whether one is set is diagnostic.');
    }

    public function testAnInstallationWithNothingConfiguredStillProducesTheFile(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $report);
        $this->assertStringContainsString('(vide — aucun message ne peut partir sur cette voie)', $report);
        $this->assertStringContainsString('(aucun envoi enregistré sur la période)', $report);
    }

    /**
     * A lane row pointing at a provider that no longer exists must read as
     * such rather than crash the collector: the archive's contract is that
     * it is always produced (§8.48).
     */
    public function testALaneEntryWhoseProviderIsGoneIsNamedRatherThanFatal(): void
    {
        $this->chains->append(MailLane::Transactional, 4242, true);

        $report = $this->collect();

        $this->assertStringContainsString('fournisseur #4242 (introuvable)', $report);
    }

    private function addRelay(string $name, string $host, ?int $dailyQuota = null): int
    {
        $id = $this->providers->create($name, $dailyQuota, 50, 10);
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = $host;
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_user'] = 'identifiant-secret@relais.test';
        $this->secrets[$prefix . '_password'] = 'MOT-DE-PASSE-SECRET';

        return $id;
    }

    private function collect(): string
    {
        $connections = new ProviderConnections($this->secrets);
        $collector = new OutboundMailCollector(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains
        );

        $archivePath = $this->storagePath . '/temp/outbound-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $collector->collect(new SupportCollectorContext(
            $archive,
            $this->connection,
            $this->settings,
            $this->projectRoot,
            $this->storagePath
        ));
        $archive->close();

        $read = new \ZipArchive();
        $this->assertTrue($read->open($archivePath) === true);
        $content = $read->getFromName('outbound-mail.txt');
        $read->close();

        $this->assertIsString($content, 'The collector must always produce its file.');

        return $content;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
