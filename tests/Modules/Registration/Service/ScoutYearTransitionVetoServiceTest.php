<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\ScoutYearTransitionVetoService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearTransitionVetoServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $repository;
    private ScoutYearTransitionVetoService $service;
    private int $yearA;
    private int $yearB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->yearA = RegistrationTestHelper::insertScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $this->yearB = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $this->repository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->service = new ScoutYearTransitionVetoService($this->repository);
    }

    public function testZeroWhenNoRequestsExist(): void
    {
        $this->assertSame(0, $this->service->countBlockingRequests());
    }

    public function testCountsPendingAndAcceptedAcrossYears(): void
    {
        $this->createRequest($this->yearA, 'pending');
        $this->createRequest($this->yearB, 'accepted');
        $this->createRequest($this->yearB, 'pending');

        $this->assertSame(3, $this->service->countBlockingRequests());
    }

    public function testExcludesFinalStatuses(): void
    {
        $this->createRequest($this->yearA, 'encoded');
        $this->createRequest($this->yearA, 'refused');
        $this->createRequest($this->yearA, 'withdrawn');

        $this->assertSame(0, $this->service->countBlockingRequests());
    }

    /**
     * "Toutes années cibles confondues" — a stale request from a past
     * target year still blocks, not just ones for the year being activated.
     */
    public function testAPastYearsUnprocessedRequestStillBlocks(): void
    {
        $this->createRequest($this->yearA, 'pending');

        $this->assertSame(1, $this->service->countBlockingRequests());
    }

    private function createRequest(int $scoutYearId, string $status): void
    {
        $id = $this->repository->create($scoutYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Nom', 'child_first_name' => 'Prenom',
            'gender' => 'M', 'birth_date' => '2018-01-01', 'street' => 'Rue Test', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Bruxelles', 'email' => 'parent' . uniqid() . '@example.com',
            'phone1' => '0470 00 00 00', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];

        if ($status !== 'pending') {
            $finalAt = in_array($status, ['encoded', 'refused', 'withdrawn'], true) ? new \DateTimeImmutable() : null;
            $this->repository->updateStatus($id, $status, $finalAt);
        }
    }
}
