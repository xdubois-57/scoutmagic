<?php

declare(strict_types=1);

namespace Tests\Core\Alert\Check;

use Core\Alert\AlertSurfaces;
use Core\Alert\Check\MailDnsRecordsCheck;
use Core\Mail\DkimManager;
use Core\Mail\DnsCheckMemory;
use Core\Mail\DnsVerifier;
use Core\Mail\MailIdentity;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;

/**
 * Issue #718 § C point 15: the daily pass reads the SPF, DKIM and DMARC
 * records the way the « Vérifier le DNS » button does, keeps the reading
 * where the Authentification page shows it, and triggers on a definite
 * « absent » only — never on a resolver that could not be asked.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailDnsRecordsCheckTest extends TestCase
{
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private InMemorySettingService $settings;
    private DkimManager $dkim;
    private string $keys;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($pdo);
        $this->chains = new LaneChainRepository($pdo);
        $this->settings = new InMemorySettingService([
            MailIdentity::SETTING_FROM_ADDRESS => 'unite@unite.be',
            MailIdentity::SETTING_DMARC_REPORT => 'dmarc@unite.be',
            'dkim_selector' => 'scoutmagic',
        ]);
        $this->keys = sys_get_temp_dir() . '/sm-dns-check-' . bin2hex(random_bytes(4));
        mkdir($this->keys);
        $this->dkim = new DkimManager($this->keys);
        $this->dkim->generateKey();

        $id = $this->providers->create('Relais', null, 50, 10);
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = 'smtp.relais.be';
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_user'] = 'user@relais.be';
        $this->chains->append(MailLane::Transactional, $id, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->keys . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->keys);
    }

    /**
     * @param array<string, list<string>|null> $zone host => TXT records, null for a lookup that failed
     */
    private function check(array $zone): MailDnsRecordsCheck
    {
        $dns = new class ($zone) extends DnsVerifier {
            /** @param array<string, list<string>|null> $zone */
            public function __construct(private array $zone)
            {
            }

            protected function readTxtRecords(string $host): ?array
            {
                return array_key_exists($host, $this->zone) ? $this->zone[$host] : [];
            }
        };

        return new MailDnsRecordsCheck(
            $this->settings,
            $dns,
            $this->dkim,
            $this->chains,
            new MailProviderDirectory($this->providers, new ProviderConnections($this->secrets), $this->settings)
        );
    }

    /** @return array<string, list<string>|null> */
    private function completeZone(): array
    {
        return [
            'unite.be' => ['v=spf1 a:smtp.relais.be -all'],
            'scoutmagic._domainkey.unite.be' => ['v=DKIM1; k=rsa; p=' . $this->publicKey()],
            '_dmarc.unite.be' => ['v=DMARC1; p=none; rua=mailto:dmarc@unite.be'],
        ];
    }

    private function publicKey(): string
    {
        return (string) preg_replace('/\s+|-----[^-]+-----/', '', $this->dkim->getPublicKey());
    }

    public function testEveryRecordPublishedRearmsAndIsKeptForThePage(): void
    {
        $reading = $this->check($this->completeZone())->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $memory = DnsCheckMemory::read($this->settings);
        $this->assertNotNull($memory, 'the Authentification page reads the same reading');
        $this->assertTrue($memory->state(DnsCheckMemory::SPF));
        $this->assertTrue($memory->state(DnsCheckMemory::DKIM));
        $this->assertTrue($memory->state(DnsCheckMemory::DMARC));
    }

    public function testAMissingSpfRecordTriggers(): void
    {
        $zone = $this->completeZone();
        $zone['unite.be'] = [];

        $reading = $this->check($zone)->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertSame('SPF absent', $reading->value);
        $this->assertSame("L'enregistrement SPF du domaine unite.be manque ou est incomplet.", $reading->title);
        $this->assertSame(MailDnsRecordsCheck::AUTHENTICATION_PATH, $reading->actionUrl);
    }

    /** A relay added without its `a:` mechanism is the incomplete case. */
    public function testAnSpfRecordMissingARelayTriggers(): void
    {
        $zone = $this->completeZone();
        $zone['unite.be'] = ['v=spf1 a:autre.be -all'];

        $this->assertTrue($this->check($zone)->read()->overTrigger);
    }

    public function testSeveralMissingRecordsAreNamedTogether(): void
    {
        $zone = $this->completeZone();
        $zone['unite.be'] = [];
        $zone['_dmarc.unite.be'] = [];

        $reading = $this->check($zone)->read();

        $this->assertSame('SPF et DMARC absents', $reading->value);
        $this->assertStringStartsWith('Les enregistrements SPF et DMARC du domaine unite.be', $reading->title);
    }

    /** « Je n'ai pas pu demander » is never « absent »: no alarm, and the stored reading stays. */
    public function testAResolverThatCouldNotBeAskedIsInconclusive(): void
    {
        $this->check($this->completeZone())->read();
        $before = $this->settings->values[DnsCheckMemory::SETTING_KEY] ?? null;

        $zone = $this->completeZone();
        $zone['_dmarc.unite.be'] = null;
        $reading = $this->check($zone)->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm, 'a standing alert must not clear on a failed lookup');
        $this->assertSame($before, $this->settings->values[DnsCheckMemory::SETTING_KEY] ?? null);
    }

    /**
     * The probe and the verdict share one lookup per host: a resolver that
     * answers once and fails a moment later must not turn into « absent ».
     */
    public function testAResolverThatFailsAfterTheProbeCannotProduceAFalseAbsent(): void
    {
        $zone = $this->completeZone();
        $dns = new class ($zone) extends DnsVerifier {
            /** @var array<string, int> */
            public array $asked = [];

            /** @param array<string, list<string>|null> $zone */
            public function __construct(private array $zone)
            {
            }

            protected function readTxtRecords(string $host): ?array
            {
                $this->asked[$host] = ($this->asked[$host] ?? 0) + 1;

                // Answers the first question about a host, then goes down.
                return $this->asked[$host] === 1 ? ($this->zone[$host] ?? []) : null;
            }
        };
        $check = new MailDnsRecordsCheck(
            $this->settings,
            $dns,
            $this->dkim,
            $this->chains,
            new MailProviderDirectory($this->providers, new ProviderConnections($this->secrets), $this->settings)
        );

        $reading = $check->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame([1], array_values(array_unique($dns->asked)), 'each host asked exactly once');
    }

    /** A record the site has nothing to publish for is not missing. */
    public function testNoDmarcReportAskedForNeedsNoDmarcRecord(): void
    {
        $this->settings->values[MailIdentity::SETTING_DMARC_REPORT] = '';
        $zone = $this->completeZone();
        unset($zone['_dmarc.unite.be']);

        $this->assertTrue($this->check($zone)->read()->underRearm);
    }

    public function testNoDkimKeyYetIsNotAMissingRecord(): void
    {
        $zone = $this->completeZone();
        unset($zone['scoutmagic._domainkey.unite.be']);
        $this->dkim->deleteKey();

        $this->assertTrue($this->check($zone)->read()->underRearm);
    }

    /** Nothing to check before the sender is set: the setup's business, not this alert's. */
    public function testASiteWithoutASenderRearmsWithoutLookingAnythingUp(): void
    {
        $this->settings->values[MailIdentity::SETTING_FROM_ADDRESS] = '';

        $reading = $this->check(['unite.be' => null])->read();

        $this->assertTrue($reading->underRearm);
        $this->assertArrayNotHasKey(DnsCheckMemory::SETTING_KEY, $this->settings->values);
    }

    /** The attention point sends the reader where the records to publish are. */
    public function testTheAttentionPointLeadsToTheAuthenticationPage(): void
    {
        $this->assertSame(
            MailDnsRecordsCheck::AUTHENTICATION_PATH,
            AlertSurfaces::destinations()[MailDnsRecordsCheck::KEY]['path']
        );
    }
}
