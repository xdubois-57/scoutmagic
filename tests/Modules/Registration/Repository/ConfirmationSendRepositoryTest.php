<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\ConfirmationSendRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

#[Group('database')]
class ConfirmationSendRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ConfirmationSendRepository $repository;
    private int $requestId;
    private int $otherRequestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->requestId = RegistrationTestHelper::insertRequest($this->pdo, $yearId, 'a@example.com');
        $this->otherRequestId = RegistrationTestHelper::insertRequest($this->pdo, $yearId, 'b@example.com');
        $this->repository = new ConfirmationSendRepository($this->pdo);
    }

    public function testCountsOnlyThisRequestsRowsSinceTheGivenInstant(): void
    {
        $this->repository->record($this->requestId, '2026-11-13 09:00:00');
        $this->repository->record($this->requestId, '2026-11-13 10:00:00');
        $this->repository->record($this->requestId, '2026-11-13 10:30:00');
        $this->repository->record($this->otherRequestId, '2026-11-13 10:30:00');

        $this->assertSame(2, $this->repository->countSince($this->requestId, '2026-11-13 10:00:00'));
        $this->assertSame(1, $this->repository->countSince($this->otherRequestId, '2026-11-13 10:00:00'));
        $this->assertSame(0, $this->repository->countSince($this->requestId, '2026-11-13 11:00:00'));
    }

    public function testDeleteOlderThanRemovesOnlyOlderRows(): void
    {
        $this->repository->record($this->requestId, '2026-11-13 08:00:00');
        $this->repository->record($this->otherRequestId, '2026-11-13 08:59:59');
        $this->repository->record($this->requestId, '2026-11-13 09:00:00');

        $this->assertSame(2, $this->repository->deleteOlderThan('2026-11-13 09:00:00'));
        $this->assertSame(1, $this->repository->countSince($this->requestId, '2000-01-01 00:00:00'));
        $this->assertSame(0, $this->repository->countSince($this->otherRequestId, '2000-01-01 00:00:00'));
    }

    /**
     * The row says which request was sent a confirmation when, and nothing
     * else — no address.
     */
    public function testARowHoldsOnlyTheRequestAndTheInstant(): void
    {
        $this->repository->record($this->requestId, '2026-11-13 10:00:00');

        $row = $this->pdo->query('SELECT * FROM registration_confirmation_sends')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        unset($row['id']);
        $this->assertSame(['registration_request_id', 'created_at'], array_keys($row));
        $this->assertSame($this->requestId, (int) $row['registration_request_id']);
        $this->assertSame('2026-11-13 10:00:00', $row['created_at']);
    }
}
