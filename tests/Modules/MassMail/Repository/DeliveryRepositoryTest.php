<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\TargetRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * mass_mail_deliveries — one message, one address (issue #999).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeliveryRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DeliveryRepository $repository;
    private int $emailId;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new DeliveryRepository($this->pdo, $this->encryption);

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

    public function testCreateEncryptsTheAddressStoresItsBlindIndexAndRoundTrips(): void
    {
        $id = $this->repository->create($this->emailId, 'Member@Test.be');

        $stmt = $this->pdo->prepare('SELECT email_address_encrypted, email_blind_index FROM mass_mail_deliveries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('Member@Test.be', (string) $row['email_address_encrypted']);
        // Compared lowercased: the blind index of the same address in
        // another case is the same.
        $this->assertSame($this->repository->blindIndex('member@test.be'), $row['email_blind_index']);
        $this->assertSame(64, strlen((string) $row['email_blind_index']));

        $delivery = $this->repository->findById($id);
        $this->assertNotNull($delivery);
        $this->assertSame('Member@Test.be', $delivery->emailAddress);
        $this->assertSame(Delivery::STATUS_PENDING, $delivery->status);
    }

    public function testAMessageIsLinkedToEveryTargetItConcernsAndToNobodyElse(): void
    {
        $targets = new TargetRepository($this->pdo);
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_2')");
        $bob = (int) $this->pdo->lastInsertId();
        $aliceTarget = $targets->create($this->emailId, $this->memberId, $this->scoutYearId);
        $bobTarget = $targets->create($this->emailId, $bob, $this->scoutYearId);

        $family = $this->repository->create($this->emailId, 'famille@test.be');
        $this->repository->linkTargets($family, [$aliceTarget, $bobTarget, $aliceTarget]);
        $contact = $this->repository->create($this->emailId, 'commune@test.be');

        $this->assertSame([$aliceTarget, $bobTarget], $this->repository->findTargetIdsForDelivery($family));
        $this->assertSame([], $this->repository->findTargetIdsForDelivery($contact));
        $this->assertSame([$family => [$aliceTarget, $bobTarget]], $this->repository->findTargetIdsByEmail($this->emailId));
    }

    public function testTheUnsubscribeTokenIsVerifiedAgainstItsHashOnly(): void
    {
        $id = $this->repository->create($this->emailId, 'a@test.be');
        $this->assertNull($this->repository->verifyUnsubscribeToken($id, 'anything'), 'no token minted yet');

        $raw = CapabilityToken::generate();
        $this->repository->setUnsubscribeTokenHash($id, CapabilityToken::hash($raw));

        $this->assertSame($id, $this->repository->verifyUnsubscribeToken($id, $raw)?->id);
        $this->assertNull($this->repository->verifyUnsubscribeToken($id, $raw . 'x'));
        $this->assertNull($this->repository->verifyUnsubscribeToken($id + 1, $raw));
    }

    public function testFindOldestPendingRespectsLimitAndFifoOrder(): void
    {
        $first = $this->repository->create($this->emailId, 'a@test.be');
        $this->repository->create($this->emailId, 'b@test.be');

        $batch = $this->repository->findOldestPending(1);

        $this->assertCount(1, $batch);
        $this->assertSame($first, $batch[0]->id);
    }

    public function testFindOldestPendingIgnoresSentAndErrorRows(): void
    {
        $this->repository->create($this->emailId, 'a@test.be', Delivery::STATUS_SENT);
        $this->repository->create($this->emailId, 'b@test.be', Delivery::STATUS_ERROR, 'x');
        $pendingId = $this->repository->create($this->emailId, 'c@test.be');

        $batch = $this->repository->findOldestPending(10);

        $this->assertCount(1, $batch);
        $this->assertSame($pendingId, $batch[0]->id);
    }

    public function testRecordSendSuccessMarksSentAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, 'a@test.be');

        $this->repository->recordSendSuccess($id);

        $delivery = $this->repository->findById($id);
        $this->assertSame(Delivery::STATUS_SENT, $delivery?->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sentAt);
        $this->assertFalse($this->repository->hasPending($this->emailId));
    }

    public function testRecordSendFailureMarksErrorWithMessageAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, 'a@test.be');

        $this->repository->recordSendFailure($id, 'Erreur SMTP');

        $delivery = $this->repository->findById($id);
        $this->assertSame(Delivery::STATUS_ERROR, $delivery?->status);
        $this->assertSame('Erreur SMTP', $delivery->errorMessage);
        $this->assertSame(1, $delivery->attempts);
    }

    public function testResendResetsToPendingAndIncrementsAttempts(): void
    {
        $id = $this->repository->create($this->emailId, 'a@test.be', Delivery::STATUS_ERROR, 'Adresse invalide');

        $this->repository->resend($id);

        $delivery = $this->repository->findById($id);
        $this->assertSame(Delivery::STATUS_PENDING, $delivery?->status);
        $this->assertNull($delivery->errorMessage);
        $this->assertSame(1, $delivery->attempts);
        $this->assertTrue($this->repository->hasPending($this->emailId));
    }

    public function testCountGroupedByStatusCountsMessages(): void
    {
        $this->repository->create($this->emailId, 'a@test.be');
        $this->repository->create($this->emailId, 'b@test.be', Delivery::STATUS_SENT);
        $this->repository->create($this->emailId, 'c@test.be', Delivery::STATUS_ERROR, 'x');

        $counts = $this->repository->countGroupedByStatus($this->emailId);

        $this->assertSame(['pending' => 1, 'sent' => 1, 'error' => 1, 'total' => 3], $counts);
        $this->assertCount(3, $this->repository->findByEmailId($this->emailId));
    }
}
