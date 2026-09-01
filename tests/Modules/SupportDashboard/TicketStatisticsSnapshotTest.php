<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\SupportTicketService;
use Modules\SupportDashboard\Service\TicketIntakeService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The report a ticket brings with it, on the receiver.
 *
 * Two things have to be true at once and they pull in opposite
 * directions: the snapshot must **never move** — it is what was running
 * when the problem was reported — while the installation row must be
 * **brought up to date** by the same arrival, since it is the only place
 * « what are they running now » lives. One body does both.
 */
class TicketStatisticsSnapshotTest extends TestCase
{
    private \PDO $pdo;
    private SupportTicketRepository $tickets;
    private SupportInstallationRepository $installations;
    private TicketIntakeService $intake;

    private const SECRET = 'a-secret-nobody-else-holds';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);
        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->intake = new TicketIntakeService(
            $this->installations,
            $this->tickets,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return array_replace_recursive([
            'statistics_schema_version' => 1,
            'installation_id' => 'unite-de-test',
            'instance_url' => 'https://unite-de-test.be',
            'scoutmagic' => ['version' => '1.0.40', 'is_dev_build' => false],
            'usage' => ['active_members' => 118, 'active_sections' => 6],
            'installation' => ['method' => 'archive'],
            'runtime' => ['php_version' => '8.4.1'],
            'database' => ['engine' => 'mysql', 'version' => '10.11'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed>|null $statistics
     */
    private function sendTicket(?array $statistics, string $description = 'Rien ne marche.'): string
    {
        $body = [
            'installation_id' => 'unite-de-test',
            'category' => TicketCategory::OTHER,
            'description' => $description,
            'contact_email' => 'chef@unite.be',
            'site_version' => '1.0.40',
            'php_version' => '8.4.1',
        ];
        if ($statistics !== null) {
            $body['statistics'] = $statistics;
        }

        $result = $this->intake->receive(
            (string) json_encode($body),
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        );

        $this->assertTrue($result->accepted, 'the ticket should have been accepted');

        return (string) $result->ticketReference;
    }

    public function testTheTicketFreezesTheReportItArrivedWith(): void
    {
        $reference = $this->sendTicket($this->report());

        $ticket = $this->tickets->findByReference($reference);

        $this->assertIsArray($ticket['statistics_snapshot']);
        $this->assertSame('1.0.40', $ticket['statistics_snapshot']['scoutmagic']['version']);
        $this->assertSame(118, $ticket['statistics_snapshot']['usage']['active_members']);
    }

    /**
     * The same arrival that froze the snapshot also refreshed the
     * installation — which is what makes the two columns comparable at
     * all, and what a separately-sent report could not guarantee.
     */
    public function testTheSameArrivalAlsoUpdatesTheInstallationRow(): void
    {
        $this->sendTicket($this->report());

        $installation = $this->installations->findByInstallationId('unite-de-test');

        $this->assertNotNull($installation);
        $this->assertSame('1.0.40', $installation['scoutmagic_version']);
        $this->assertSame(118, (int) $installation['active_members']);
        // Trust-on-first-use created this row for a ticket; a report
        // arriving on it clears the « sans télémétrie » mark.
        $this->assertSame(1, (int) $installation['telemetry_enabled']);
    }

    /**
     * The point of freezing it: the installation moves on, the ticket
     * does not.
     */
    public function testTheSnapshotDoesNotFollowLaterReports(): void
    {
        $reference = $this->sendTicket($this->report());

        // A later ticket from the same installation, on a newer version.
        $this->sendTicket($this->report([
            'scoutmagic' => ['version' => '1.0.41'],
            'usage' => ['active_members' => 130],
        ]), 'Autre chose.');

        $ticket = $this->tickets->findWithInstallation(
            (int) $this->tickets->findByReference($reference)['id']
        );

        $this->assertSame('1.0.40', $ticket['statistics_snapshot']['scoutmagic']['version']);
        $this->assertSame('1.0.41', $ticket['installation']['scoutmagic_version']);

        $comparison = SupportTicketService::statisticsComparison($ticket);
        $version = array_values(array_filter(
            $comparison,
            static fn(array $row): bool => $row['label'] === 'Version du site'
        ))[0];

        $this->assertSame('1.0.40', $version['at_ticket']);
        $this->assertSame('1.0.41', $version['latest']);
        $this->assertTrue($version['changed']);
        $this->assertTrue(SupportTicketService::statisticsDrifted($ticket));
    }

    public function testNothingIsFlaggedAsChangedWhenNothingMoved(): void
    {
        $reference = $this->sendTicket($this->report());
        $ticket = $this->tickets->findWithInstallation(
            (int) $this->tickets->findByReference($reference)['id']
        );

        $this->assertFalse(SupportTicketService::statisticsDrifted($ticket));
    }

    /**
     * A ticket from an instance too old to send one is an ordinary case,
     * not a broken row: the left column is simply absent.
     */
    public function testATicketWithoutAReportStillReads(): void
    {
        $this->installations->register(
            'unite-de-test',
            password_hash(self::SECRET, PASSWORD_DEFAULT),
            (string) json_encode($this->report()),
            []
        );

        $reference = $this->sendTicket(null);
        $ticket = $this->tickets->findWithInstallation(
            (int) $this->tickets->findByReference($reference)['id']
        );

        $this->assertNull($ticket['statistics_snapshot']);
        $this->assertFalse(SupportTicketService::statisticsDrifted($ticket));

        foreach (SupportTicketService::statisticsComparison($ticket) as $row) {
            $this->assertNull($row['at_ticket']);
            $this->assertFalse($row['changed']);
        }
    }
}
