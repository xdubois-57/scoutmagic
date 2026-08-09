<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class DepartureServiceTest extends TestCase
{
    private \PDO $pdo;
    private DepartureService $service;
    private int $scoutYearId;
    private int $memberId;
    private int $memberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repository = new DepartureRepository($this->pdo, $encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->service = new DepartureService($repository, $journal);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $this->scoutYearId, 'enc', 'enc']);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
    }

    public function testMarkingLeavingSetsTheStatus(): void
    {
        $this->service->markLeaving($this->memberYearId, 'Conflit avec un animateur');

        $status = $this->service->getStatus($this->memberYearId);

        $this->assertTrue($status->leaving);
        $this->assertNotNull($status->markedAt);
        $this->assertSame('Conflit avec un animateur', $status->comment);
    }

    public function testUnmarkingClearsTheStatusAndTheComment(): void
    {
        $this->service->markLeaving($this->memberYearId, 'Raison sensible');

        $this->service->unmarkLeaving($this->memberYearId);

        $status = $this->service->getStatus($this->memberYearId);
        $this->assertFalse($status->leaving);
        $this->assertNull($status->markedAt);
        $this->assertNull($status->comment);
    }

    public function testCommentIsStoredEncryptedAndUnreadableInClear(): void
    {
        $this->service->markLeaving($this->memberYearId, 'Difficultés familiales du jeune');

        $raw = $this->pdo->query('SELECT leaving_comment_encrypted FROM member_years WHERE id = ' . $this->memberYearId)
            ->fetchColumn();

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('Difficultés familiales', (string) $raw);
        $this->assertStringNotContainsString('familiales', (string) $raw);
    }

    public function testJournalNeverContainsTheComment(): void
    {
        $this->service->markLeaving($this->memberYearId, 'Un motif très privé et identifiable');

        $rows = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'member_leaving_marked'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('motif', (string) $row['description']);
            $this->assertStringNotContainsString('privé', (string) $row['description']);
            $this->assertStringNotContainsString('motif', (string) $row['context']);
            $this->assertStringNotContainsString('privé', (string) $row['context']);
        }
    }

    public function testJournalEntryCarriesOnlyTheMemberId(): void
    {
        $this->service->markLeaving($this->memberYearId, null);

        $row = $this->pdo->query("SELECT context FROM event_log WHERE event_type = 'member_leaving_marked'")
            ->fetch(\PDO::FETCH_ASSOC);

        $context = json_decode((string) $row['context'], true);
        $this->assertSame(['member_id' => $this->memberId], $context);
    }

    public function testUnmarkingAlsoJournalsWithoutTheComment(): void
    {
        $this->service->markLeaving($this->memberYearId, 'Secret');
        $this->service->unmarkLeaving($this->memberYearId);

        $rows = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'member_leaving_unmarked'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('Secret', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('Secret', (string) $rows[0]['context']);
    }

    public function testMarkingOneYearDoesNotAffectAnotherYearOfTheSameMember(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $otherYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $otherYearId, 'enc', 'enc']);
        $otherMemberYearId = (int) $this->pdo->lastInsertId();

        $this->service->markLeaving($this->memberYearId, 'Départ cette année');

        $thisYearStatus = $this->service->getStatus($this->memberYearId);
        $otherYearStatus = $this->service->getStatus($otherMemberYearId);

        $this->assertTrue($thisYearStatus->leaving);
        $this->assertFalse($otherYearStatus->leaving);
        $this->assertNull($otherYearStatus->comment);
    }
}
