<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\TrackingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TrackingServiceTest extends TestCase
{
    private \PDO $pdo;
    private TrackingService $service;
    private RegistrationRequestRepository $requestRepository;
    private RegistrationSecondaryEmailRepository $secondaryEmailRepository;
    private EncryptionService $encryption;
    private array $created;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $this->encryption);
        $this->service = new TrackingService($this->requestRepository, $this->secondaryEmailRepository, $this->encryption);

        $scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->created = $this->requestRepository->create($scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'origin@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
    }

    public function testFindByTokenSucceedsWithCorrectTokenOnly(): void
    {
        $found = $this->service->findByToken($this->created['id'], $this->created['tracking_token']);
        $this->assertNotNull($found);
        $this->assertSame('Léa', $found->childFirstName);

        $this->assertNull($this->service->findByToken($this->created['id'], 'wrong-token'));
    }

    public function testIsLinkedByEmailTrueForOriginEmail(): void
    {
        $this->assertTrue($this->service->isLinkedByEmail($this->created['id'], 'origin@example.com'));
        $this->assertTrue($this->service->isLinkedByEmail($this->created['id'], 'ORIGIN@example.com'));
    }

    public function testIsLinkedByEmailFalseForUnrelatedEmail(): void
    {
        $this->assertFalse($this->service->isLinkedByEmail($this->created['id'], 'stranger@example.com'));
    }

    public function testIsLinkedByEmailFalseForPendingSecondaryEmail(): void
    {
        $this->secondaryEmailRepository->create($this->created['id'], 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));

        // Never linked until the secondary address is actually confirmed —
        // an unconfirmed row must not grant access to the full tracking view.
        $this->assertFalse($this->service->isLinkedByEmail($this->created['id'], 'secondary@example.com'));
    }

    public function testIsLinkedByEmailTrueOnceSecondaryEmailIsConfirmed(): void
    {
        $id = $this->secondaryEmailRepository->create($this->created['id'], 'secondary@example.com', 'hash', new \DateTimeImmutable('+48 hours'));
        $this->secondaryEmailRepository->markValid($id);

        $this->assertTrue($this->service->isLinkedByEmail($this->created['id'], 'secondary@example.com'));
    }

    public function testFindAllLinkedByEmailCombinesPrimaryAndConfirmedSecondaryWithoutDuplicates(): void
    {
        $id = $this->secondaryEmailRepository->create($this->created['id'], 'origin@example.com', 'hash', new \DateTimeImmutable('+48 hours'));
        $this->secondaryEmailRepository->markValid($id);

        $all = $this->service->findAllLinkedByEmail('origin@example.com');
        $this->assertCount(1, $all);
        $this->assertSame($this->created['id'], $all[0]->id);
    }
}
