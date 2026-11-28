<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TicketIntakeService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1486: the usage report a ticket carries is frozen into
 * `statistics_snapshot_encrypted`, a `BLOB` of 65 535 bytes, while the
 * ticket body it arrives in may weigh 65 536. Re-encoded with PHP's
 * defaults, every accented character of the report grew from two bytes to
 * six (`é`) and every slash to two, then encryption added 28: a ticket
 * at the accepted ceiling was refused by the engine. SQLite bounds nothing,
 * so this is judged where the bound exists.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class TicketStatisticsSnapshotOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const SECRET = 'a-secret-nobody-else-holds';

    private \PDO $pdo;
    private SupportTicketRepository $tickets;
    private TicketIntakeService $intake;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = $this->productionEngine();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);
        $this->intake = new TicketIntakeService(
            new SupportInstallationRepository($this->pdo),
            $this->tickets,
            new JournalService(new JournalRepository($this->pdo)),
            SupportDashboardTestHelper::intakeGuard($this->pdo, $encryption)
        );
    }

    /**
     * A ticket body of exactly TicketIntakeService::MAX_BODY_BYTES, whose
     * report is padded with $padding repeated — sent the way a sender
     * writes JSON, accents and slashes as they are.
     */
    private function bodyAtTheCeiling(string $padding): string
    {
        $body = [
            'installation_id' => 'unite-de-test',
            'category' => TicketCategory::OTHER,
            'description' => 'Le site affiche une erreur à la connexion.',
            'contact_email' => 'chef@unite.be',
            'site_version' => '1.0.40',
            'php_version' => '8.4.1',
            'statistics' => [
                'statistics_schema_version' => 1,
                'installation_id' => 'unite-de-test',
                'scoutmagic' => ['version' => '1.0.40'],
                'usage' => ['active_members' => 118, 'active_sections' => 6],
                'runtime' => ['php_version' => '8.4.1'],
                'notes' => '',
            ],
        ];
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $room = TicketIntakeService::MAX_BODY_BYTES - strlen((string) json_encode($body, $flags));
        $body['statistics']['notes'] = str_repeat($padding, intdiv($room, strlen($padding)))
            . str_repeat('a', $room % strlen($padding));

        $encoded = (string) json_encode($body, $flags);
        $this->assertSame(TicketIntakeService::MAX_BODY_BYTES, strlen($encoded), 'the body is at the ceiling');

        return $encoded;
    }

    private function send(string $body): string
    {
        $result = $this->intake->receive($body, 'Bearer ' . self::SECRET, '203.0.113.1', true);
        $this->assertTrue($result->accepted, 'a ticket at the accepted ceiling must be filed');

        return (string) $result->ticketReference;
    }

    /** The premise, asserted rather than assumed: this engine refuses a value wider than the column. */
    public function testThisEngineRefusesAValueWiderThanTheColumn(): void
    {
        $reference = $this->send($this->bodyAtTheCeiling('a'));

        try {
            $this->pdo->prepare('UPDATE support_tickets SET statistics_snapshot_encrypted = ? WHERE reference = ?')
                ->execute([str_repeat('a', 65536), $reference]);
            $this->fail('the column accepted 65 536 bytes; the bound below would be judged against nothing');
        } catch (\PDOException $e) {
            $this->assertSame(1406, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
        }
    }

    /**
     * The worst a sender can do within the ceiling: a report of accents
     * and slashes. The ticket is filed, the facts the detail page compares
     * survive, and what was dropped is named — in the snapshot and in the
     * journal.
     */
    public function testATicketAtTheCeilingIsFiledWithItsSnapshotBoundedAndTheTruncationDeclared(): void
    {
        $reference = $this->send($this->bodyAtTheCeiling('é/'));

        $snapshot = $this->tickets->findByReference($reference)['statistics_snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('1.0.40', $snapshot['scoutmagic']['version']);
        $this->assertSame(118, $snapshot['usage']['active_members']);
        $this->assertArrayNotHasKey('notes', $snapshot);
        $truncation = $snapshot[SupportTicketRepository::STATISTICS_TRUNCATION_KEY];
        $this->assertSame(['notes'], $truncation['dropped']);
        $this->assertGreaterThan(SupportTicketRepository::MAX_STATISTICS_SNAPSHOT_BYTES, $truncation['original_bytes']);

        $stored = $this->pdo->prepare(
            'SELECT LENGTH(statistics_snapshot_encrypted) FROM support_tickets WHERE reference = ?'
        );
        $stored->execute([$reference]);
        $this->assertLessThanOrEqual(
            SupportTicketRepository::MAX_STATISTICS_SNAPSHOT_BYTES + 28,
            (int) $stored->fetchColumn(),
            'the snapshot plus its nonce and tag'
        );

        $line = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE category = 'support_dashboard' "
                . "AND event_type = 'ticket_statistics_truncated'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($line, 'the truncation is journaled');
        $this->assertSame('info', $line['level']);
        // assertEquals: a JSON column on MySQL 8 hands keys back in its
        // own order, MariaDB in the order written.
        $context = json_decode((string) $line['context'], true);
        $this->assertEquals(
            ['ticket_reference' => $reference, 'original_bytes' => $truncation['original_bytes']],
            $context
        );
    }

    /** A report that fits is stored whole and journals nothing about its size. */
    public function testAReportThatFitsIsStoredWholeWithNoTruncationLine(): void
    {
        $body = json_decode($this->bodyAtTheCeiling('a'), true);
        $body['statistics']['notes'] = 'Hébergement mutualisé / PHP 8.4';
        $reference = $this->send((string) json_encode($body));

        $snapshot = $this->tickets->findByReference($reference)['statistics_snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('Hébergement mutualisé / PHP 8.4', $snapshot['notes']);
        $this->assertArrayNotHasKey(SupportTicketRepository::STATISTICS_TRUNCATION_KEY, $snapshot);
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'ticket_statistics_truncated'"
        )->fetchColumn());
    }
}
