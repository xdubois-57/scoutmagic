<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationMenuHookServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private RegistrationMenuHookService $hookService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $trackingService = new TrackingService($this->requestRepository, $secondaryEmailRepository, $encryption);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_espace_animes_retention_months', '3', 'number', 'Rétention', 'desc', 'registration');
        $this->hookService = new RegistrationMenuHookService($trackingService, $settingService);

        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createRequest(string $email = 'marie@example.com'): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => $email,
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $created['id'];
    }

    public function testGetEntriesForEmailReturnsOneEntryPerLinkedPendingRequest(): void
    {
        $id = $this->createRequest();

        $entries = $this->hookService->getEntriesForEmail('marie@example.com');

        $this->assertCount(1, $entries);
        $this->assertSame('Léa Dupont', $entries[0]['label']);
        $this->assertSame('/inscriptions/suivi/demande/' . $id, $entries[0]['url']);
    }

    public function testGetEntriesForEmailReturnsEmptyWhenNoLinkedRequest(): void
    {
        $this->assertSame([], $this->hookService->getEntriesForEmail('stranger@example.com'));
    }

    public function testEncodedRequestNeverAppears(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $this->requestRepository->linkToMemberAndEncode($id, $memberId, new \DateTimeImmutable());

        $this->assertSame([], $this->hookService->getEntriesForEmail('marie@example.com'));
    }

    public function testRefusedRequestDisappearsAfterRetentionWindow(): void
    {
        $id = $this->createRequest();
        $recentFinalAt = new \DateTimeImmutable('-1 month');
        $this->requestRepository->updateStatus($id, 'refused', $recentFinalAt);

        $this->assertCount(1, $this->hookService->getEntriesForEmail('marie@example.com'));

        $oldFinalAt = new \DateTimeImmutable('-4 months');
        $this->requestRepository->updateStatus($id, 'refused', $oldFinalAt);

        $this->assertSame([], $this->hookService->getEntriesForEmail('marie@example.com'));
    }
}
