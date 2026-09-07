<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Net\DnsRecordReader;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\TicketIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Net\ScriptedDnsReader;
use Tests\DatabaseTestHelper;

/**
 * The zone of the reporting installation, read when the ticket lands.
 *
 * **Why at that moment and not when somebody reads the ticket.** « Le site
 * ne répond plus », « les e-mails n'arrivent pas », « le certificat est
 * invalide » — the answer to each is often a DNS record, and often one the
 * reporter has corrected by the time a maintainer opens the ticket three
 * days later. A zone read at reading time answers a question nobody asked.
 *
 * **And it can never cost the ticket.** The row is committed and answered
 * for before any of this runs: a resolver that is down, an installation
 * with no URL, a database that went away — every one of them is a ticket
 * without a snapshot, which is what every ticket written before this
 * existed is.
 */
final class TicketDnsSnapshotTest extends TestCase
{
    private const INSTALLATION_ID = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const SECRET = 'f1e2d3c4b5a6978869504132231405f6f1e2d3c4b5a6978869504132231405f6';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SupportTicketRepository $tickets;
    private SupportInstallationRepository $installations;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->installations = new SupportInstallationRepository($this->pdo, $this->encryption);
        $this->tickets = new SupportTicketRepository($this->pdo, $this->encryption);
    }

    public function testATicketCarriesTheZoneAsItStoodWhenItArrived(): void
    {
        $this->registerInstallation('https://unite.example.be');

        $this->receive(new ScriptedDnsReader([
            DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 300]],
            DNS_MX => [['type' => 'MX', 'pri' => 10, 'target' => 'mx.example.be', 'ttl' => 3600]],
        ]));

        $snapshot = $this->storedSnapshot();

        $this->assertSame('unite.example.be', $snapshot['host']);
        $this->assertSame(["300\t192.0.2.10"], $snapshot['records']['A']['values']);
        $this->assertSame(["3600\t10 mx.example.be"], $snapshot['records']['MX']['values']);
        $this->assertNotNull($this->column('dns_read_at'));
    }

    /**
     * A document about somebody else's installation, carrying its host and
     * every address that host resolves to — encrypted for exactly the
     * reason the statistics snapshot beside it is.
     */
    public function testTheSnapshotIsNotReadableFromTheColumn(): void
    {
        $this->registerInstallation('https://unite.example.be');
        $this->receive(new ScriptedDnsReader([DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 300]]]));

        $stored = (string) $this->column('dns_snapshot_encrypted');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('192.0.2.10', $stored);
        $this->assertStringNotContainsString('unite.example.be', $stored);
    }

    /**
     * `dns_snapshot_encrypted` is a `BLOB`: 65 535 bytes, and encryption
     * adds to whatever goes in. A zone answering near the DNS RDATA limit
     * on TXT alone would either be refused by the database or truncated
     * into ciphertext that never decrypts again — and the ticket would
     * lose its evidence to a column width. So the snapshot is capped
     * BEFORE encryption, and what was dropped is named: a snapshot
     * missing its TXT records reads as a domain that has none, which is a
     * diagnosis rather than a gap.
     */
    public function testAZoneTooBigForTheColumnIsCappedAndSaysSo(): void
    {
        $this->registerInstallation('https://unite.example.be');

        // Twenty-five kept records of 4 KiB each, on two types: well past
        // the cap, and enough that dropping one type is not enough either.
        $fat = static fn(string $value): array => array_fill(
            0,
            DnsRecordReader::MAX_RECORDS_PER_TYPE,
            ['type' => 'TXT', 'txt' => str_repeat($value, 4096), 'ttl' => 300]
        );

        $this->receive(new ScriptedDnsReader([
            DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 300]],
            DNS_TXT => $fat('t'),
            DNS_NS => $fat('n'),
        ]));

        $snapshot = $this->storedSnapshot();

        // What survived is the head of the reader's own order, so the
        // cheapest diagnosis is the one that is kept.
        $this->assertSame(["300\t192.0.2.10"], $snapshot['records']['A']['values']);
        $this->assertNotEmpty($snapshot['truncated'] ?? []);
        $this->assertContains('SOA', $snapshot['truncated']);
        $this->assertArrayNotHasKey('SOA', $snapshot['records']);

        // And the archive a human opens says it, rather than showing a
        // domain that looks like it has nothing.
        $this->assertStringContainsString(
            'Types retirés du relevé conservé',
            DnsRecordReader::asText($snapshot)
        );
    }

    /**
     * The ticket is the thing the person came to send. Everything after it
     * is bookkeeping, and bookkeeping does not get to refuse.
     */
    public function testAResolverThatIsDownStillLeavesATicket(): void
    {
        $this->registerInstallation('https://unite.example.be');

        $result = $this->receive(new ScriptedDnsReader([]), failing: true);

        $this->assertTrue($result);
        $this->assertSame(1, $this->countTickets());
        $this->assertNull($this->column('dns_snapshot_encrypted'));
        $this->assertContains('support_ticket_dns_failed', $this->journalTypes());
    }

    public function testAnInstallationWithNoUsableUrlIsSaidSoRatherThanGuessedAt(): void
    {
        // The row a first ticket provisions has no URL at all: it has
        // never sent a report. That is ordinary, and it is not a failure.
        $this->registerInstallation(null);

        $this->receive(new ScriptedDnsReader([DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 60]]]));

        $this->assertSame(1, $this->countTickets());
        $this->assertNull($this->column('dns_snapshot_encrypted'));
        $this->assertContains('support_ticket_dns_skipped', $this->journalTypes());
    }

    public function testAnIpLiteralIsNotAZoneToRead(): void
    {
        $this->registerInstallation('https://192.0.2.10/');

        $this->receive(new ScriptedDnsReader([DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 60]]]));

        $this->assertNull($this->column('dns_snapshot_encrypted'));
        $this->assertContains('support_ticket_dns_skipped', $this->journalTypes());
    }

    /**
     * Wiring nothing leaves the intake exactly as it was — the §7.5 shape
     * of a degradation, and what every receiver looked like before this.
     */
    public function testWithoutAReaderATicketIsStoredExactlyAsBefore(): void
    {
        $this->registerInstallation('https://unite.example.be');

        $this->receive(null);

        $this->assertSame(1, $this->countTickets());
        $this->assertNull($this->column('dns_snapshot_encrypted'));
        $this->assertNotContains('support_ticket_dns_skipped', $this->journalTypes());
        $this->assertNotContains('support_ticket_dns_failed', $this->journalTypes());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function receive(?DnsRecordReader $dns, bool $failing = false): bool
    {
        $service = new TicketIntakeService(
            $this->installations,
            $failing ? new BrokenTicketRepository($this->pdo, $this->encryption) : $this->tickets,
            new JournalService(new JournalRepository($this->pdo)),
            null,
            $dns
        );

        return $service->receive(
            (string) json_encode([
                'installation_id' => self::INSTALLATION_ID,
                'category' => 'desk_import',
                'description' => 'Mon import Desk ne passe plus depuis hier.',
                'contact_email' => 'chef@unite.be',
            ]),
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        )->accepted;
    }

    private function registerInstallation(?string $instanceUrl): void
    {
        $this->installations->register(
            self::INSTALLATION_ID,
            password_hash(self::SECRET, PASSWORD_DEFAULT),
            '{}',
            StatisticsIntakeService::denormalize(array_filter([
                'statistics_schema_version' => 1,
                'instance_url' => $instanceUrl,
            ], static fn(mixed $value): bool => $value !== null))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSnapshot(): array
    {
        $ticket = $this->tickets->find((int) $this->pdo->query('SELECT id FROM support_tickets')->fetchColumn());
        $snapshot = $ticket['dns_snapshot'] ?? null;
        $this->assertIsArray($snapshot, 'the ticket must carry the zone it arrived with');

        return $snapshot;
    }

    /**
     * One column of the single ticket these tests create.
     *
     * The statement is chosen from a fixed map rather than built from
     * `$name`: PDO cannot bind an identifier, so a column name reaching
     * SQL is always concatenation, and this repository's rule against it
     * does not carve out an exception for a caller that happens to pass a
     * literal today (AGENTS.md § Database).
     */
    private function column(string $name): mixed
    {
        $statements = [
            'dns_read_at' => 'SELECT dns_read_at FROM support_tickets LIMIT 1',
            'dns_snapshot_encrypted' => 'SELECT dns_snapshot_encrypted FROM support_tickets LIMIT 1',
        ];

        $statement = $statements[$name] ?? null;
        $this->assertNotNull($statement, 'unknown column ' . $name);

        $stmt = $this->pdo->prepare($statement);
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    private function countTickets(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
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

/**
 * A ticket repository that files a ticket and then refuses to write
 * anything else — the database that goes away between the insert and the
 * update, which is the one class of failure the snapshot's catch is for.
 */
final class BrokenTicketRepository extends SupportTicketRepository
{
    public function recordDnsSnapshot(string $reference, array $snapshot, \DateTimeImmutable $readAt): void
    {
        throw new \RuntimeException('the database went away');
    }
}
