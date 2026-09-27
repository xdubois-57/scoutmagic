<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\ImportedMemberRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The two reads issue #646 moved out of `Service\ReconciliationService`
 * and `Service\ExternalMailingListService`: active rows of one year only,
 * decrypted here and nowhere else.
 */
#[Group('database')]
class ImportedMemberRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ImportedMemberRepository $repository;
    private RegistrationRequestRepository $requestRepository;
    private int $yearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ImportedMemberRepository($this->pdo, $this->encryption);
        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
    }

    /**
     * The index is checked against the one a REQUEST stores, not against a
     * value computed in this test: comparing the two is the whole use of
     * it, and a purpose string or a normalisation that drifted on one side
     * only would still agree with a copy of itself.
     */
    public function testNameDobBlindIndexesMatchWhatAnAcceptedRequestForTheSameChildStores(): void
    {
        $requestId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $lea = $this->insertMemberYear($this->yearId, 'Dupont', 'Léa', '2019-01-01');
        $other = $this->insertMemberYear($this->yearId, 'Martin', 'Tom', '2018-02-03');

        $indexes = $this->repository->findNameDobBlindIndexesForYear($this->yearId);
        $byMember = array_column($indexes, 'name_dob_blind_index', 'member_id');
        ksort($byMember);

        $this->assertSame([$lea, $other], array_keys($byMember));
        $stored = $this->requestRepository->findAcceptedIdsAndBlindIndexForYear($this->yearId);
        $this->assertSame([['id' => $requestId, 'name_dob_blind_index' => $byMember[$lea]]], $stored);
        $this->assertNotSame($byMember[$lea], $byMember[$other]);
    }

    public function testNameDobBlindIndexesSkipMembersWithoutBirthDateInactiveRowsAndOtherYears(): void
    {
        $kept = $this->insertMemberYear($this->yearId, 'Dupont', 'Léa', '2019-01-01');
        $this->insertMemberYear($this->yearId, 'Sans', 'Date', null);
        $this->insertMemberYear($this->yearId, 'Parti', 'Inactif', '2019-01-01', active: false);
        $this->insertMemberYear($this->otherYearId, 'Autre', 'Année', '2019-01-01');

        $indexes = $this->repository->findNameDobBlindIndexesForYear($this->yearId);

        $this->assertSame([$kept], array_column($indexes, 'member_id'));
    }

    public function testEmailsForYearAreDecryptedAndAMissingOneStaysNull(): void
    {
        $withEmail = $this->insertMemberYear($this->yearId, 'Dupont', 'Léa', '2019-01-01', email: 'lea@example.com');
        $withoutEmail = $this->insertMemberYear($this->yearId, 'Martin', 'Tom', '2018-01-01');
        $inactive = $this->insertMemberYear($this->yearId, 'Parti', 'Inactif', '2018-01-01', 'x@example.com', active: false);
        $otherYear = $this->insertMemberYear($this->otherYearId, 'Autre', 'Année', '2018-01-01', email: 'y@example.com');
        $notAsked = $this->insertMemberYear($this->yearId, 'Pas', 'Demandé', '2018-01-01', email: 'z@example.com');

        $members = $this->repository->findEmailsForYear([$withEmail, $withoutEmail, $inactive, $otherYear], $this->yearId);
        usort($members, static fn (array $a, array $b): int => $a['member_id'] <=> $b['member_id']);

        $this->assertSame([
            ['member_id' => $withEmail, 'email' => 'lea@example.com'],
            ['member_id' => $withoutEmail, 'email' => null],
        ], $members);
        $this->assertNotContains($notAsked, array_column($members, 'member_id'));
    }

    public function testEmailsOfNobodyAreEmpty(): void
    {
        $this->insertMemberYear($this->yearId, 'Dupont', 'Léa', '2019-01-01', email: 'lea@example.com');

        $this->assertSame([], $this->repository->findEmailsForYear([], $this->yearId));
    }

    private function createAcceptedRequest(string $lastName, string $firstName, string $birthDate): int
    {
        $created = $this->requestRepository->create($this->yearId, [
            'parent_name' => 'Parent', 'child_last_name' => $lastName, 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'parent@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        return $created['id'];
    }

    /** @return int the member id */
    private function insertMemberYear(
        int $scoutYearId,
        string $lastName,
        string $firstName,
        ?string $birthDate,
        ?string $email = null,
        bool $active = true
    ): int {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-' . bin2hex(random_bytes(4)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                birth_date_encrypted, email_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $birthDate !== null ? $this->encryption->encrypt($birthDate, 'member_years.birth_date') : null,
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $active ? 1 : 0,
        ]);

        return $memberId;
    }
}
