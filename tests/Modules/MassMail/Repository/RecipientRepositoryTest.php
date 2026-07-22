<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
class RecipientRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RecipientRepository $repository;
    private int $emailId;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RecipientRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>Corps</p>', {$sectionId}, 'default_active_members', 'sending')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateEncryptsAddressAndRoundTripsOnRead(): void
    {
        $id = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'member@test.be', Recipient::STATUS_PENDING, null);

        $stmt = $this->pdo->prepare('SELECT email_address_encrypted FROM mass_mail_recipients WHERE id = ?');
        $stmt->execute([$id]);
        $raw = (string) $stmt->fetchColumn();
        $this->assertStringNotContainsString('member@test.be', $raw);

        $recipient = $this->repository->findById($id);
        $this->assertNotNull($recipient);
        $this->assertSame('member@test.be', $recipient->emailAddress);
    }

    public function testCreateWithNullAddressAndErrorStatusForInvalidAddress(): void
    {
        $id = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, null, Recipient::STATUS_ERROR, 'Adresse invalide');

        $recipient = $this->repository->findById($id);
        $this->assertNotNull($recipient);
        $this->assertNull($recipient->emailAddress);
        $this->assertSame(Recipient::STATUS_ERROR, $recipient->status);
        $this->assertSame('Adresse invalide', $recipient->errorMessage);
    }

    public function testFindOldestPendingRespectsLimitAndFifoOrder(): void
    {
        // created_at has second resolution, so ordering within the same
        // second relies on the id tiebreaker (see Repository\
        // RecipientRepository::findOldestPending()'s "created_at ASC, id ASC").
        $first = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_PENDING, null);
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'b@test.be', Recipient::STATUS_PENDING, null);

        $batch = $this->repository->findOldestPending(1);

        $this->assertCount(1, $batch);
        $this->assertSame($first, $batch[0]->id);
    }

    public function testFindOldestPendingIgnoresSentAndErrorRows(): void
    {
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_SENT, null);
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'b@test.be', Recipient::STATUS_ERROR, 'x');
        $pendingId = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'c@test.be', Recipient::STATUS_PENDING, null);

        $batch = $this->repository->findOldestPending(10);

        $this->assertCount(1, $batch);
        $this->assertSame($pendingId, $batch[0]->id);
    }

    public function testRecordSendSuccessMarksSentAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_PENDING, null);

        $this->repository->recordSendSuccess($id);

        $recipient = $this->repository->findById($id);
        $this->assertSame(Recipient::STATUS_SENT, $recipient->status);
        $this->assertSame(1, $recipient->attempts);
        $this->assertNotNull($recipient->sentAt);
    }

    public function testRecordSendFailureMarksErrorWithMessageAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_PENDING, null);

        $this->repository->recordSendFailure($id, 'Erreur SMTP');

        $recipient = $this->repository->findById($id);
        $this->assertSame(Recipient::STATUS_ERROR, $recipient->status);
        $this->assertSame('Erreur SMTP', $recipient->errorMessage);
        $this->assertSame(1, $recipient->attempts);
    }

    public function testResendResetsToPendingAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_ERROR, 'Adresse invalide');

        $this->repository->resend($id);

        $recipient = $this->repository->findById($id);
        $this->assertSame(Recipient::STATUS_PENDING, $recipient->status);
        $this->assertNull($recipient->errorMessage);
        $this->assertSame(1, $recipient->attempts);
    }

    public function testCountGroupedByStatus(): void
    {
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_PENDING, null);
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'b@test.be', Recipient::STATUS_SENT, null);
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'c@test.be', Recipient::STATUS_ERROR, 'x');

        $counts = $this->repository->countGroupedByStatus($this->emailId);

        $this->assertSame(['pending' => 1, 'sent' => 1, 'error' => 1, 'total' => 3], $counts);
    }

    public function testFindRecentSentForMemberOnlyReturnsSentRows(): void
    {
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'a@test.be', Recipient::STATUS_SENT, null);
        $this->repository->create($this->emailId, $this->memberId, $this->scoutYearId, 'b@test.be', Recipient::STATUS_PENDING, null);

        $recent = $this->repository->findRecentSentForMember($this->memberId, 10);

        $this->assertCount(1, $recent);
        $this->assertSame('Sujet', $recent[0]['subject']);
    }
}
