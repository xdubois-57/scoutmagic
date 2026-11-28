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
use Core\Mail\Transport\MailReserve;
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

    /**
     * **A lane whose state cannot be read goes at the local send's
     * prudent pace** — slower, never stopped. Here the day's counters are
     * missing, as on a database the migration has not reached: a
     * mailing's pacing must not become an exception. Both halves, the
     * table being the only difference.
     */
    public function testALaneThatCannotBeReadFallsBackToTheLocalSendsPrudentCadence(): void
    {
        $first = $this->addRelay('Premier', batchSize: 30, interval: 7);
        $this->chains->append(MailLane::Bulk, $first, true);
        $this->assertSame(30, $this->cadence()->current()['batch_size'], 'The premise: the relay sets the pace.');

        $this->pdo->exec('DROP TABLE mail_send_counters');

        $this->assertSame(MailProvider::LOCAL_ID, $this->cadence()->activeProvider()->id);
        $this->assertSame(
            [
                'batch_size' => MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE,
                'interval_minutes' => MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL,
            ],
            $this->cadence()->current()
        );
    }

    // ── Whether the lane has room (issue #1454) ───────────────────────

    /**
     * The question a mailing asks before each batch: a lane whose every
     * enabled quota is spent suspends the mailing rather than having the
     * chain defer it message by message.
     */
    public function testALaneHasRoomUntilItsLastQuotaIsSpent(): void
    {
        $first = $this->addRelay('Premier', batchSize: 30, interval: 7, dailyQuota: 1);
        $second = $this->addRelay('Second', batchSize: 30, interval: 7, dailyQuota: 1);
        $this->chains->append(MailLane::Bulk, $first, true);
        $this->chains->append(MailLane::Bulk, $second, true);

        $this->assertTrue($this->cadence()->hasRoomLeft());

        $this->counters->increment($first, MailLane::Bulk);
        $this->assertTrue($this->cadence()->hasRoomLeft(), 'The second provider still has its quota.');

        $this->counters->increment($second, MailLane::Bulk);
        $this->assertFalse($this->cadence()->hasRoomLeft());
    }

    /** A provider with no known quota never runs out, and a disabled entry never counts. */
    public function testAProviderWithoutAQuotaAlwaysHasRoomAndADisabledOneNever(): void
    {
        $spent = $this->addRelay('Épuisé', batchSize: 30, interval: 7, dailyQuota: 1);
        $unbounded = $this->addRelay('Sans quota', batchSize: 30, interval: 7);
        $this->chains->append(MailLane::Bulk, $spent, true);
        $this->chains->append(MailLane::Bulk, $unbounded, false);
        $this->counters->increment($spent, MailLane::Bulk);

        $this->assertFalse($this->cadence()->hasRoomLeft(), 'The unbounded relay is disabled.');

        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);
        $this->assertTrue($this->cadence()->hasRoomLeft(), 'The local send has no known ceiling (D6).');
    }

    /**
     * **The chain's reading, reserve included** (D8): on the mailing lane
     * the share kept for sign-in links and transactional mail comes off
     * the quota first, so « room » here is what the chain would accept.
     */
    public function testTheReserveComesOffTheMailingLanesQuota(): void
    {
        $shared = $this->addRelay('Partagé', batchSize: 30, interval: 7, dailyQuota: 200);
        $this->chains->append(MailLane::Bulk, $shared, true);
        $this->chains->append(MailLane::Transactional, $shared, true);
        $reserve = new MailReserve($this->counters, $this->chains);
        $kept = $reserve->forProvider($this->directory()->all()[$shared])->messages;
        $this->assertGreaterThan(0, $kept, 'The premise: a shared relay keeps a reserve.');

        for ($i = 0; $i < 200 - $kept - 1; $i++) {
            $this->counters->increment($shared, MailLane::Bulk);
        }
        $this->assertTrue($this->cadence($reserve)->hasRoomLeft(), 'One message before the reserve.');

        $this->counters->increment($shared, MailLane::Bulk);
        $this->assertFalse($this->cadence($reserve)->hasRoomLeft());
        $this->assertTrue($this->cadence()->hasRoomLeft(), 'Without the reserve the quota is read whole.');
    }

    /**
     * No chain laid down means the configured relay carries the message,
     * and a chain that cannot be read must not stop a mailing: room, both.
     */
    public function testALaneWithNoChainOrNoReadableStateHasRoom(): void
    {
        $this->assertTrue($this->cadence()->hasRoomLeft());

        $only = $this->addRelay('Unique', batchSize: 25, interval: 9, dailyQuota: 1);
        $this->chains->append(MailLane::Bulk, $only, true);
        $this->counters->increment($only, MailLane::Bulk);
        $this->assertFalse($this->cadence()->hasRoomLeft(), 'The premise: this lane is full.');

        $this->pdo->exec('DROP TABLE mail_send_counters');

        $this->assertTrue($this->cadence()->hasRoomLeft());
    }

    private function directory(): MailProviderDirectory
    {
        return new MailProviderDirectory($this->providers, new ProviderConnections($this->secrets), $this->settings);
    }

    private function addRelay(string $name, int $batchSize, int $interval, ?int $dailyQuota = null): int
    {
        $id = $this->providers->create($name, $dailyQuota, $batchSize, $interval);
        $this->secrets[ProviderConnections::prefixFor($id) . '_host'] = 'smtp.' . strtolower($name) . '.test';

        return $id;
    }

    private function cadence(?MailReserve $reserve = null): BulkCadence
    {
        $connections = new ProviderConnections($this->secrets);

        return new BulkCadence(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains,
            $this->counters,
            $reserve
        );
    }
}
