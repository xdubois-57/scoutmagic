<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\RegistrationMenuHookService;
use Modules\Registration\Service\TrackingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
class RegistrationMenuHookServiceTest extends TestCase
{
    public function testGetEntriesForEmailReturnsOneEntryPerLinkedRequest(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $requestRepository = new RegistrationRequestRepository($pdo, $encryption);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($pdo, $encryption);
        $trackingService = new TrackingService($requestRepository, $secondaryEmailRepository, $encryption);
        $hookService = new RegistrationMenuHookService($trackingService);

        $scoutYearId = RegistrationTestHelper::insertScoutYear($pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $created = $requestRepository->create($scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $entries = $hookService->getEntriesForEmail('marie@example.com');

        $this->assertCount(1, $entries);
        $this->assertSame('Léa Dupont', $entries[0]['label']);
        $this->assertSame('/inscriptions/suivi/demande/' . $created['id'], $entries[0]['url']);
    }

    public function testGetEntriesForEmailReturnsEmptyWhenNoLinkedRequest(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $requestRepository = new RegistrationRequestRepository($pdo, $encryption);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($pdo, $encryption);
        $trackingService = new TrackingService($requestRepository, $secondaryEmailRepository, $encryption);
        $hookService = new RegistrationMenuHookService($trackingService);

        $this->assertSame([], $hookService->getEntriesForEmail('stranger@example.com'));
    }
}
