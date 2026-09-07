<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Net\WhoisClient;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\DomainRegistrationRefresher;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Net\ScriptedWhoisClient;
use Tests\DatabaseTestHelper;

/**
 * Who registered each installation's domain, refreshed from the daily
 * report.
 *
 * **A report is not a query, and that is the whole design.** A WHOIS
 * lookup on every report would be one per installation per day, at
 * registries that rate-limit by source address and answer a blocked caller
 * with silence — so the diagnostic would stop working precisely on the
 * fleet large enough to need it. What happens on every report is a date
 * comparison; what happens once a month is a query.
 */
final class DomainRegistrationRefresherTest extends TestCase
{
    private const NOW = '2026-09-06 12:00:00';

    private \PDO $pdo;
    private SupportInstallationRepository $installations;

    /** The shape every registry in this fleet's TLDs actually answers with. */
    private const RECORD = "Domain:\tunite.example.be\nRegistered:\tTue Mar 4 2014\n"
        . "Registrar:\n\tName:\t Example Hosting SA\n";

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testTheFirstReportAsksTheRegistryAndKeepsWhatItSaid(): void
    {
        $id = $this->register('https://unite.example.be');

        $this->refresh($id, $this->answering());

        $row = $this->installations->findById($id);
        $this->assertSame(DomainRegistrationRefresher::STATUS_FOUND, $row['whois_status']);
        $this->assertSame('unite.example.be', $row['whois_domain']);
        $this->assertSame('whois.dnsbelgium.be', $row['whois_server']);

        $registration = json_decode((string) $row['whois_registration'], true);
        $this->assertSame('Example Hosting SA', $registration['registrar']);
        $this->assertSame('Tue Mar 4 2014', $registration['created_at']);
    }

    /**
     * The response verbatim, encrypted: it routinely names the volunteer
     * who registered the domain, with an address and a telephone number.
     */
    public function testTheRawResponseIsKeptAndIsNotReadableFromTheColumn(): void
    {
        $id = $this->register('https://unite.example.be');
        $whois = $this->answering(self::RECORD . "Registrant Name: Marie Dupont\n");

        $this->refresh($id, $whois);

        $stored = (string) $this->pdo->query('SELECT whois_raw_encrypted FROM support_installations')->fetchColumn();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('Marie Dupont', $stored);

        // And it comes back for the one file that may carry it.
        $this->assertStringContainsString('Marie Dupont', (string) $this->installations->findWhoisRaw($id));
        // While the parsed half, which a page renders, never learned it.
        $this->assertStringNotContainsString(
            'Marie Dupont',
            (string) $this->installations->findById($id)['whois_registration']
        );
    }

    /**
     * WHOIS declares no encoding and plenty of registries still answer in
     * Latin-1, so a byte that is not valid UTF-8 arrives sooner or later.
     * It used to make `json_encode()` return `false`, which PDO wrote as
     * an empty string: a registration that WAS read, lost on the way to
     * its column, and a dialog showing nothing about a domain the
     * registry had described.
     *
     * The name server is the way in. Every other field goes through
     * `mb_substr()`, which replaces a bad byte on the way past; the name
     * server list is built from `preg_split()` and keeps the bytes as the
     * registry sent them.
     */
    public function testABadByteInANameServerDoesNotCostTheWholeRegistration(): void
    {
        $id = $this->register('https://unite.example.be');

        // A 0xE9 where UTF-8 expects a lead byte — "société" in Latin-1.
        $whois = $this->answering(
            "Domain: unite.example.be\nRegistrar: Example Hosting SA\nName Server: ns1.soci\xE9te.example\n"
        );

        $this->refresh($id, $whois);

        $stored = (string) $this->installations->findById($id)['whois_registration'];
        $registration = json_decode($stored, true);

        $this->assertIsArray($registration, 'one bad byte must not empty the column');
        $this->assertSame('Example Hosting SA', $registration['registrar']);
        $this->assertCount(1, $registration['name_servers']);
    }

    public function testASecondReportTheSameDayAsksNobody(): void
    {
        $id = $this->register('https://unite.example.be');
        $whois = $this->answering();

        $this->refresh($id, $whois);
        $this->refresh($id, $whois, '2026-09-06 23:59:00');
        $this->refresh($id, $whois, '2026-09-20 12:00:00');

        $this->assertSame(
            1,
            $whois->timesAsked('whois.dnsbelgium.be', 'unite.example.be'),
            'a registration changes once a year; asking daily is how an address gets blocked'
        );
    }

    public function testAStaleRegistrationIsAskedAboutAgain(): void
    {
        $id = $this->register('https://unite.example.be');
        $whois = $this->answering();

        $this->refresh($id, $whois);
        $this->refresh($id, $whois, '2026-10-10 12:00:00');

        $this->assertSame(2, $whois->timesAsked('whois.dnsbelgium.be', 'unite.example.be'));
    }

    /**
     * A registry that was down this morning is a different thing from a
     * registration that has not changed. Freezing « indisponible » for a
     * month over one bad afternoon is how a diagnostic quietly stops
     * being one.
     */
    public function testAFailureIsRetriedTheNextDayRatherThanNextMonth(): void
    {
        $id = $this->register('https://unite.example.be');

        // Nobody answers: port 43 closed, which is the ordinary case on
        // shared hosting.
        $this->refresh($id, new ScriptedWhoisClient([]));
        $this->assertSame(
            DomainRegistrationRefresher::STATUS_UNAVAILABLE,
            $this->installations->findById($id)['whois_status']
        );

        $whois = $this->answering();
        $this->refresh($id, $whois, '2026-09-07 12:00:00');

        $this->assertSame(1, $whois->timesAsked('whois.dnsbelgium.be', 'unite.example.be'));
        $this->assertSame(
            DomainRegistrationRefresher::STATUS_FOUND,
            $this->installations->findById($id)['whois_status']
        );
    }

    /**
     * A unit that moved to a new domain is a new question. Waiting out the
     * month would show the previous owner's registrar beside the new URL.
     */
    public function testChangingDomainAsksAgainWithoutWaitingOutTheMonth(): void
    {
        $id = $this->register('https://unite.example.be');
        $this->refresh($id, $this->answering());

        $this->installations->recordReport(
            $id,
            '{}',
            StatisticsIntakeService::denormalize([
                'statistics_schema_version' => 1,
                'instance_url' => 'https://nouvelle-unite.example.org',
            ])
        );

        $whois = new ScriptedWhoisClient([
            'whois.iana.org' => ['org' => "whois: whois.publicinterestregistry.org\n"],
            'whois.publicinterestregistry.org' => [
                'nouvelle-unite.example.org' => "Registrar: Autre Registrar\nCreation Date: 2026\n",
            ],
        ]);
        $this->refresh($id, $whois, '2026-09-07 12:00:00');

        $this->assertSame('nouvelle-unite.example.org', $this->installations->findById($id)['whois_domain']);
    }

    /**
     * « Le registre a répondu, ce nom n'est enregistré nulle part » is a
     * real and interesting answer — a site answering on a domain nobody
     * has registered is a hijack or a lapse — and it is not the same as
     * « je n'ai pas pu demander ».
     */
    public function testARegistryThatSaysTheNameIsFreeIsNotTheSameAsSilence(): void
    {
        $id = $this->register('https://example.be');

        $this->refresh($id, new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "whois: whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => ['example.be' => "Domain:\texample.be\nStatus:\tAVAILABLE\n"],
        ]));

        // A registry ANSWERED, and said the name is registered nowhere.
        // That is a different row from « personne n'a répondu » — a site
        // answering on a name nobody has registered is a hijack or a
        // lapse, and it is one of the more interesting things this
        // receiver can learn.
        $this->assertSame(
            DomainRegistrationRefresher::STATUS_NOT_FOUND,
            $this->installations->findById($id)['whois_status']
        );
    }

    public function testAnInstallationWithNoUsableUrlIsNeverAskedAbout(): void
    {
        $id = $this->register(null);
        $whois = $this->answering();

        $this->refresh($id, $whois);

        $this->assertSame([], $whois->asked());
        // And nothing is written: a row with no URL is not one whose
        // registration went missing, so there is nothing to say about it.
        $this->assertNull($this->installations->findById($id)['whois_checked_at']);
    }

    public function testARegistryHavingABadDayNeverCostsTheReport(): void
    {
        $id = $this->register('https://unite.example.be');

        $refresher = new DomainRegistrationRefresher(
            $this->installations,
            new ThrowingWhoisClient(),
            new JournalService(new JournalRepository($this->pdo))
        );
        $refresher->refresh((array) $this->installations->findById($id), new \DateTimeImmutable(self::NOW));

        $this->assertContains('support_whois_failed', $this->journalTypes());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function answering(?string $record = null): ScriptedWhoisClient
    {
        return new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "domain: BE\nwhois: whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => ['unite.example.be' => $record ?? self::RECORD],
        ]);
    }

    private function refresh(int $id, WhoisClient $whois, string $now = self::NOW): void
    {
        $refresher = new DomainRegistrationRefresher(
            $this->installations,
            $whois,
            new JournalService(new JournalRepository($this->pdo))
        );

        $refresher->refresh((array) $this->installations->findById($id), new \DateTimeImmutable($now));
    }

    private function register(?string $instanceUrl): int
    {
        return $this->installations->register(
            '0a1b2c3d4e5f60718293a4b5c6d7e8f9',
            password_hash('secret', PASSWORD_DEFAULT),
            '{}',
            StatisticsIntakeService::denormalize(array_filter([
                'statistics_schema_version' => 1,
                'instance_url' => $instanceUrl,
            ], static fn(mixed $value): bool => $value !== null))
        );
    }

    /**
     * @return string[]
     */
    private function journalTypes(): array
    {
        return array_map(
            'strval',
            $this->pdo->query(
                "SELECT event_type FROM event_log WHERE category = 'support_dashboard' ORDER BY id ASC"
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }
}

/** A registry chain that does not merely fail to answer, but throws. */
final class ThrowingWhoisClient extends WhoisClient
{
    public function lookup(string $host): \Core\Net\WhoisLookup
    {
        throw new \RuntimeException('the socket layer is not having a good day');
    }
}
