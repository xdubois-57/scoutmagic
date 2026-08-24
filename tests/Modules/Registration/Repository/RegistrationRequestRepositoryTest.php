<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationRequestRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $repository;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /**
     * @return array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string
     * }
     */
    private function sampleFields(array $overrides = []): array
    {
        return array_merge([
            'parent_name' => 'Marie Dupont',
            'child_last_name' => 'Dupont',
            'child_first_name' => 'Léa',
            'gender' => 'F',
            'birth_date' => '2019-05-12',
            'street' => 'Rue de la Paix',
            'number' => '12',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'marie.dupont@example.com',
            'phone1' => '0470123456',
            'phone2' => null,
            'remarks' => null,
        ], $overrides);
    }

    public function testCreateEncryptsFieldsAndDefaultsToPending(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);

        $row = $this->pdo->query('SELECT * FROM registration_requests WHERE id = ' . $created['id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        // Encrypted at rest: the raw column must never contain the plaintext.
        $this->assertStringNotContainsString('Dupont', (string) $row['child_last_name_encrypted']);
        $this->assertStringNotContainsString('marie.dupont@example.com', (string) $row['email_encrypted']);
        $this->assertNotSame('', $row['tracking_token_hash']);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertSame('Léa', $found->childFirstName);
        $this->assertSame('Dupont', $found->childLastName);
        $this->assertSame('marie.dupont@example.com', $found->email);
        $this->assertSame(RegistrationRequestRepository::normalizeEmail($found->email), 'marie.dupont@example.com');
    }

    public function testCreatePersistsSiblingLinks(): void
    {
        $memberId1 = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $memberId2 = RegistrationTestHelper::insertMember($this->pdo, 'M2');

        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [$memberId1, $memberId2, $memberId1]);

        $siblingIds = $this->repository->findSiblingMemberIds($created['id']);
        sort($siblingIds);
        $this->assertSame([$memberId1, $memberId2], $siblingIds);
    }

    public function testVerifyTrackingTokenAcceptsCorrectTokenOnly(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);

        $this->assertTrue($this->repository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertFalse($this->repository->verifyTrackingToken($created['id'], 'wrong-token'));
        $this->assertFalse($this->repository->verifyTrackingToken(999999, $created['tracking_token']));
    }

    public function testFindAllByEmailBlindIndexMatchesOnlySameEmail(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(['email' => 'a@example.com']), null, []);
        $this->repository->create($this->scoutYearId, $this->sampleFields(['email' => 'b@example.com']), null, []);

        $blindIndex = $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail('a@example.com'), 'registration_email');
        $matches = $this->repository->findAllByEmailBlindIndex($blindIndex);

        $this->assertCount(1, $matches);
        $this->assertSame($created['id'], $matches[0]->id);
    }

    public function testFindAllForYearOnlyReturnsRequestsForThatYearOldestFirst(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $first = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Aa']), null, []);
        $this->repository->create($otherYearId, $this->sampleFields(), null, []);
        $second = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Bb']), null, []);

        $all = $this->repository->findAllForYear($this->scoutYearId);
        $this->assertCount(2, $all);
        $this->assertSame($first['id'], $all[0]->id);
        $this->assertSame($second['id'], $all[1]->id);
    }

    /**
     * Core\Member\Household\HouseholdService enumerates every household of
     * a scout year at once, so it asks for a batch of addresses in one
     * query rather than one query per household.
     */
    public function testCountHouseholdsAtAddressesCountsOnlyAcceptedAndEncodedRequestsOfThatYear(): void
    {
        $paix = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);
        $paixSecond = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Noé']), null, []);
        $paixPending = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Zoé']), null, []);
        $louise = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['street' => 'Avenue Louise', 'number' => '10']),
            null,
            []
        );
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $otherYear = $this->repository->create($otherYearId, $this->sampleFields(), null, []);

        $now = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->repository->updateStatus($paix['id'], 'accepted', null);
        $this->repository->updateStatus($paixSecond['id'], 'encoded', $now);
        $this->repository->updateStatus($louise['id'], 'accepted', null);
        $this->repository->updateStatus($otherYear['id'], 'accepted', null);
        // $paixPending stays pending — a request that may still be refused
        // must not inflate anyone's household.

        $paixIndex = $this->blindIndexFor($paix['id']);
        $louiseIndex = $this->blindIndexFor($louise['id']);

        $counts = $this->repository->countHouseholdsAtAddresses([$paixIndex, $louiseIndex], $this->scoutYearId);

        $this->assertSame(2, $counts[$paixIndex]);
        $this->assertSame(1, $counts[$louiseIndex]);
        $this->assertSame(
            $this->repository->countHouseholdAtAddress($paixIndex, $this->scoutYearId, null),
            $counts[$paixIndex]
        );
    }

    public function testCountHouseholdsAtAddressesLeavesAnAddressWithNoRequestOutRatherThanReportingZero(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);
        $this->repository->updateStatus($created['id'], 'accepted', null);

        $counts = $this->repository->countHouseholdsAtAddresses(
            [$this->blindIndexFor($created['id']), 'an-index-nobody-lives-at'],
            $this->scoutYearId
        );

        $this->assertArrayNotHasKey('an-index-nobody-lives-at', $counts);
        $this->assertCount(1, $counts);
    }

    public function testCountHouseholdsAtAddressesOnAnEmptyListRunsNoQuery(): void
    {
        $this->assertSame([], $this->repository->countHouseholdsAtAddresses([], $this->scoutYearId));
        $this->assertSame([], $this->repository->countHouseholdsAtAddresses([''], $this->scoutYearId));
    }

    private function blindIndexFor(int $requestId): string
    {
        $stmt = $this->pdo->prepare('SELECT address_normalized_blind_index FROM registration_requests WHERE id = ?');
        $stmt->execute([$requestId]);

        return (string) $stmt->fetchColumn();
    }

    public function testNameDobBlindIndexIsStableAcrossCaseAndWhitespace(): void
    {
        $normalizedA = RegistrationRequestRepository::normalizeForNameDobBlindIndex('Dupont', 'Léa', '2019-05-12');
        $normalizedB = RegistrationRequestRepository::normalizeForNameDobBlindIndex('  DUPONT ', ' léa', '2019-05-12');

        $this->assertSame($normalizedA, $normalizedB);
    }
}
