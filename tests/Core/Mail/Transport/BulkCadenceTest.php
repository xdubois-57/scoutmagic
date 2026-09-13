<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Transport\BulkCadence;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * D7, written as a test: a lane that switches adopts the NEXT provider's
 * cadence, never the previous one's.
 *
 * This used to be a pair of settings on `mass_mail` — one global number
 * for the whole installation — which could not express the sentence
 * above at all. A cadence describes what a relay accepts, so it belongs
 * to the relay (D6), and the answer changes the moment the mailing lane
 * falls back.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BulkCadenceTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SendCounterRepository $counters;
    private SettingService $settings;
    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->counters = new SendCounterRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
    }

    public function testItReadsTheCadenceOfTheFirstEnabledProvider(): void
    {
        $first = $this->addRelay('Premier', batchSize: 30, interval: 7);
        $this->addRelay('Second', batchSize: 100, interval: 2);
        $this->chains->append(MailLane::Bulk, $first, true);

        $this->assertSame(['batch_size' => 30, 'interval_minutes' => 7], $this->cadence()->current());
    }

    public function testAfterAQuotaIsSpentItAdoptsTheNextProvidersCadence(): void
    {
        $first = $this->addRelay('Premier', batchSize: 30, interval: 7, dailyQuota: 2);
        $second = $this->addRelay('Second', batchSize: 100, interval: 2);
        $this->chains->append(MailLane::Bulk, $first, true);
        $this->chains->append(MailLane::Bulk, $second, true);

        $this->assertSame(30, $this->cadence()->current()['batch_size'], 'While the first has room.');

        $this->counters->increment($first, MailLane::Bulk);
        $this->counters->increment($first, MailLane::Bulk);

        $this->assertSame(
            ['batch_size' => 100, 'interval_minutes' => 2],
            $this->cadence()->current(),
            'The lane has switched, so it paces itself on the provider now carrying it.'
        );
    }

    public function testADisabledFirstEntryIsNotTheOneThatSetsTheCadence(): void
    {
        $disabled = $this->addRelay('Désactivé', batchSize: 1, interval: 120);
        $active = $this->addRelay('Actif', batchSize: 60, interval: 5);
        $this->chains->append(MailLane::Bulk, $disabled, false);
        $this->chains->append(MailLane::Bulk, $active, true);

        $this->assertSame(60, $this->cadence()->current()['batch_size']);
    }

    /**
     * The local send's cadence is two settings rather than a column,
     * because there is no row to carry one — which is the same fact that
     * makes it undeletable (D5). The default is prudent on purpose (D6).
     */
    public function testTheLocalSendFallsBackToItsPrudentDefault(): void
    {
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $this->assertSame(
            [
                'batch_size' => MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE,
                'interval_minutes' => MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL,
            ],
            $this->cadence()->current()
        );
    }

    public function testTheLocalSendsCadenceIsWhateverTheSettingSays(): void
    {
        $this->settings->register(
            MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE,
            '10',
            'number',
            'Envoi local — messages par lot',
            'Test'
        );
        $this->settings->set(MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE, '4');
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $this->assertSame(4, $this->cadence()->current()['batch_size']);
    }

    /**
     * Every enabled entry spent: nothing more will leave today, and what
     * happens to the messages is D9's business rather than the cadence's.
     * Answering with the first of them keeps the pacing sane instead of
     * inventing one.
     */
    public function testAnExhaustedLaneStillAnswersWithARealCadence(): void
    {
        $only = $this->addRelay('Unique', batchSize: 25, interval: 9, dailyQuota: 1);
        $this->chains->append(MailLane::Bulk, $only, true);
        $this->counters->increment($only, MailLane::Bulk);

        $this->assertSame(25, $this->cadence()->current()['batch_size']);
    }

    private function addRelay(string $name, int $batchSize, int $interval, ?int $dailyQuota = null): int
    {
        $id = $this->providers->create($name, $dailyQuota, $batchSize, $interval);
        $this->secrets[ProviderConnections::prefixFor($id) . '_host'] = 'smtp.' . strtolower($name) . '.test';

        return $id;
    }

    private function cadence(): BulkCadence
    {
        $connections = new ProviderConnections($this->secrets);

        return new BulkCadence(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains,
            $this->counters
        );
    }
}
