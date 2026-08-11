<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\ExternalMailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The registration module's own mailing list, contributed to mass_mail:
 * only 'encoded' requests qualify (a real member_id to send to), never
 * 'accepted'/'pending'/'refused'/'withdrawn' ones, and it's always named
 * after the target scout year regardless of which year is "current".
 *
 * @group database
 */
class ExternalMailingListServiceTest extends TestCase
{
    private \PDO $pdo;
    private ExternalMailingListService $service;
    private RegistrationRequestRepository $requestRepository;
    private EncryptionService $encryption;
    private int $targetYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->service = new ExternalMailingListService(
            $this->pdo, $this->encryption, $scoutYearResolver, $scoutYearService, $this->requestRepository
        );
    }

    private function createRequest(string $firstName, string $email): int
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => $email,
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $created['id'];
    }

    private function encodeRequest(int $requestId, string $memberEmail): int
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK_' . uniqid());
        $this->requestRepository->updateStatus($requestId, 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($requestId, $memberId, new \DateTimeImmutable());

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->targetYearId, $this->encryption->encrypt('X'), $this->encryption->encrypt('Y'), $this->encryption->encrypt($memberEmail)]);

        return $memberId;
    }

    public function testDescribeMailingListNamesTheTargetYear(): void
    {
        $description = $this->service->describeMailingList();

        $this->assertSame('Inscriptions 2027-2028', $description['label']);
        $this->assertStringContainsString('2027-2028', $description['description']);
    }

    public function testResolveMembersIncludesOnlyEncoded(): void
    {
        $pendingId = $this->createRequest('Pending', 'pending@example.com');

        $acceptedId = $this->createRequest('Accepted', 'accepted@example.com');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        $refusedId = $this->createRequest('Refused', 'refused@example.com');
        $this->requestRepository->updateStatus($refusedId, 'refused', new \DateTimeImmutable());

        $encodedId = $this->createRequest('Encoded', 'encoded@example.com');
        $memberId = $this->encodeRequest($encodedId, 'member@example.com');

        $members = $this->service->resolveMailingListMembers();

        $this->assertCount(1, $members);
        $this->assertSame($memberId, $members[0]['member_id']);
        $this->assertSame('member@example.com', $members[0]['email']);
    }

    public function testResolveMembersIsEmptyWhenNoneEncoded(): void
    {
        $this->createRequest('Pending', 'pending@example.com');

        $this->assertSame([], $this->service->resolveMailingListMembers());
    }
}
