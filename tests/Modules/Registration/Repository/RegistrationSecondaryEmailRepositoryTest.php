<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmail;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationSecondaryEmailRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationSecondaryEmailRepository $repository;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);

        $scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $created = $requestRepository->create($scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'origin@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestId = $created['id'];
    }

    public function testCreateStartsPendingAndFindByRequestAndEmailRoundTrips(): void
    {
        $id = $this->repository->create($this->requestId, 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));

        $row = $this->repository->findById($id);
        $this->assertNotNull($row);
        $this->assertTrue($row->isPending());
        $this->assertSame('secondary@example.com', $row->email);

        $byEmail = $this->repository->findByRequestAndEmail($this->requestId, 'SECONDARY@example.com');
        $this->assertNotNull($byEmail);
        $this->assertSame($id, $byEmail->id);
    }

    public function testMarkValidClearsTokenAndSetsConfirmedAt(): void
    {
        $id = $this->repository->create($this->requestId, 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));

        $this->repository->markValid($id);

        $row = $this->repository->findById($id);
        $this->assertTrue($row->isValid());
        $this->assertNull($row->confirmationTokenHash);
        $this->assertNotNull($row->confirmedAt);
    }

    public function testFindRequestIdsByValidBlindIndexOnlyReturnsValidRows(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $blindIndex = $encryption->blindIndex(RegistrationRequestRepository::normalizeEmail('secondary@example.com'), 'registration_email');

        $pendingId = $this->repository->create($this->requestId, 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));
        $this->assertSame([], $this->repository->findRequestIdsByValidBlindIndex($blindIndex));

        $this->repository->markValid($pendingId);
        $this->assertSame([$this->requestId], $this->repository->findRequestIdsByValidBlindIndex($blindIndex));
    }

    public function testRefreshConfirmationKeepsSameRowId(): void
    {
        $id = $this->repository->create($this->requestId, 'secondary@example.com', 'hash-1', new \DateTimeImmutable('+48 hours'));

        $this->repository->refreshConfirmation($id, 'hash-2', new \DateTimeImmutable('+72 hours'));

        $row = $this->repository->findById($id);
        $this->assertSame($id, $row->id);
        $this->assertSame(RegistrationSecondaryEmail::STATUS_PENDING, $row->status);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create($this->requestId, 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));
        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
