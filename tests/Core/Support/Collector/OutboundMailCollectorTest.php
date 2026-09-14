<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Mail\MailPurpose;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\ProviderHealth;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Support\Collector\OutboundMailCollector;
use Core\Security\EncryptionService;
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
    private ProviderHealthRepository $health;
    private DeferredMailRepository $deferred;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->counters = new SendCounterRepository($this->pdo);
        $this->health = new ProviderHealthRepository($this->pdo);
        $this->deferred = new DeferredMailRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

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

    // ── ce que le coupe-circuit, la réserve et la file racontent ──────

    /**
     * The column worth having is `ouvertures`: a provider closed right
     * now that has opened eleven times this month is a relay on its way
     * out, and no screenshot taken between two outages shows that.
     */
    public function testItReportsWhatTheBreakerHasBeenDoing(): void
    {
        $relay = $this->addRelay('Relais fatigué', 'smtp.fatigue.test');
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->health->recordFailure($relay, 'SMTP connect() failed.');
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Coupe-circuit', $report);
        $this->assertMatchesRegularExpression('/Relais fatigué\s+ouvert\s+3\s+1/u', $report);
        $this->assertStringContainsString('SMTP connect() failed.', $report);
    }

    /**
     * The reserve is the one figure on this page that looks arbitrary, so
     * it travels with the sentence saying where it came from — a sentence
     * the reader can check against the counters printed further down.
     */
    public function testTheReserveTravelsWithItsProvenance(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test', dailyQuota: 1000);
        $this->chains->append(MailLane::Authentication, $relay, true);
        $this->chains->append(MailLane::Bulk, $relay, true);
        for ($i = 0; $i < 54; $i++) {
            $this->counters->increment($relay, MailLane::Transactional, date('Y-m-d'));
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Réserve pour les liens de connexion', $report);
        $this->assertStringContainsString('votre pointe hors publipostage', $report);
        $this->assertStringContainsString('(54)', $report);
    }

    /** « Un report n'est pas un silence » — so the depth is in the archive. */
    public function testItReportsTheQueueDepthByLane(): void
    {
        $this->queueOne(MailLane::Transactional);
        $this->queueOne(MailLane::Bulk);
        $abandoned = $this->queueOne(MailLane::Bulk);
        $this->deferred->abandon($abandoned, 3, 'délai de vie dépassé');

        $report = $this->collect();

        $this->assertStringContainsString('── Messages différés', $report);
        $this->assertMatchesRegularExpression('/Transactionnel\S*\s+1 en attente/u', $report);
        $this->assertStringContainsString('abandonnés     : 1', $report);
        $this->assertStringContainsString('de 6 à 24 h', $report, 'The bands are disjoint and say so.');
    }

    /**
     * **The assertion this whole file exists for, extended to the three
     * new sections.** A deferred message holds a subject and a recipient;
     * the archive goes to a third party, and neither may leave with it.
     */
    public function testTheQueueSectionCarriesNoMessage(): void
    {
        $this->queueOne(MailLane::Transactional);

        $report = $this->collect();

        $this->assertStringNotContainsString('parent@exemple.test', $report);
        $this->assertStringNotContainsString('Reçu de paiement', $report);
    }

    /**
     * A table that is not there yet — a support package collected
     * mid-migration — costs the section, not the file. The chains and the
     * counters are still the thing somebody asked for.
     */
    public function testAMissingTableCostsOnlyItsOwnSection(): void
    {
        $this->pdo->exec('DROP TABLE mail_deferred_messages');
        $this->pdo->exec('DROP TABLE mail_provider_health');

        $report = $this->collect();

        $this->assertStringNotContainsString('── Messages différés', $report);
        $this->assertStringNotContainsString('── Coupe-circuit', $report);
        $this->assertStringContainsString('── Chaînes, dans leur ordre', $report);
    }

    private function queueOne(MailLane $lane): int
    {
        return $this->deferred->add(
            $lane,
            MailPurpose::Ordinary,
            [
                'to' => 'parent@exemple.test',
                'subject' => 'Reçu de paiement',
                'bodyHtml' => '<p>Bonjour</p>',
                'bodyText' => 'Bonjour',
                'replyTo' => null,
                'fromAddressOverride' => null,
                'fromNameOverride' => null,
                'extraHeaders' => [],
                'attachments' => [],
            ],
            'quota épuisé',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600)
        );
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
            $this->chains,
            $this->health,
            new MailReserve($this->counters, $this->chains),
            $this->deferred,
            new DeferredMailQueue($this->deferred, $this->settings)
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
