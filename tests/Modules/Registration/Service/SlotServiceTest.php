<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
class SlotServiceTest extends TestCase
{
    private \PDO $pdo;
    private SlotService $service;
    private AgeBracketRepository $bracketRepository;
    private SlotCapacityRepository $capacityRepository;
    private int $baladinsId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'Dispo', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'Limité', 'desc', 'registration');

        $this->bracketRepository = new AgeBracketRepository($this->pdo);
        $this->capacityRepository = new SlotCapacityRepository($this->pdo);
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);

        $this->service = new SlotService(
            $this->pdo, $encryption, $settingService, $this->bracketRepository, $this->capacityRepository, $requestRepository
        );

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
    }

    public function testBirthYearsByBranchListsOneYearPerYearInBranch(): void
    {
        $result = $this->service->birthYearsByBranch('2026-2027');

        $this->assertArrayHasKey($this->baladinsId, $result);
        $this->assertSame('Baladins', $result[$this->baladinsId]['branch_label']);
        $this->assertSame(
            [SlotMath::birthYearForSlot($this->bracketRepository->findForBranch($this->baladinsId), 1, 2026),
             SlotMath::birthYearForSlot($this->bracketRepository->findForBranch($this->baladinsId), 2, 2026)],
            $result[$this->baladinsId]['birth_years']
        );
    }

    public function testWaitlistTiersByBirthYearWithNoMembersAndNoAcceptedRequestsIsFullyAvailable(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 20);
        $this->capacityRepository->upsert($this->baladinsId, 2, 20);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $tiers = $this->service->waitlistTiersByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $expectedYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $this->assertContains($expectedYear1, $tiers[SlotMath::TIER_AVAILABLE]);
    }

    public function testWaitlistTiersByBirthYearReflectsAcceptedRequestsNotPendingOnes(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 2);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        // Two accepted requests against a capacity-2 slot exhausts it
        // entirely — a third, still-pending one must NOT count (accepted
        // requests are real commitments, undecided ones aren't).
        for ($i = 0; $i < 3; $i++) {
            $created = $requestRepository->create($targetYearId, [
                'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . $i,
                'gender' => 'F', 'birth_date' => $birthYear1 . '-06-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'p' . $i . '@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ], null, []);
            if ($i < 2) {
                $requestRepository->updateStatus($created['id'], 'accepted', null);
            }
        }

        $tiers = $this->service->waitlistTiersByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $this->assertContains($birthYear1, $tiers[SlotMath::TIER_HEAVY]);
        $this->assertNotContains($birthYear1, $tiers[SlotMath::TIER_AVAILABLE]);
    }

    public function testBirthYearSlotsForPublicListsYearInBranchWithoutTierWhenWaitlistDisabled(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $rows = $this->service->birthYearSlotsForPublic($targetYearId, '2027-2028', $currentYearId, false);

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);
        $birthYear2 = SlotMath::birthYearForSlot($bracket, 2, 2027);

        $row1 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear1));
        $this->assertNotFalse($row1);
        $this->assertSame('Baladins', $row1['branch_label']);
        $this->assertSame(1, $row1['year_in_branch']);
        $this->assertNull($row1['tier']);

        $row2 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear2));
        $this->assertNotFalse($row2);
        $this->assertSame(2, $row2['year_in_branch']);
    }

    public function testBirthYearSlotsForPublicIncludesTierWhenWaitlistEnabled(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 2);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        for ($i = 0; $i < 2; $i++) {
            $created = $requestRepository->create($targetYearId, [
                'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . $i,
                'gender' => 'F', 'birth_date' => $birthYear1 . '-06-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'p' . $i . '@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ], null, []);
            $requestRepository->updateStatus($created['id'], 'accepted', null);
        }

        $rows = $this->service->birthYearSlotsForPublic($targetYearId, '2027-2028', $currentYearId, true);

        $row1 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear1));
        $this->assertNotFalse($row1);
        $this->assertSame(SlotMath::TIER_HEAVY, $row1['tier']);
    }
}
