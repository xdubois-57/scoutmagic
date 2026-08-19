<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionRosterEntry;
use Core\Member\SectionRosterRepository;
use Core\Member\SectionRosterService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class SectionRosterServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SectionRosterService $service;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $memberEmailRepository = new MemberEmailRepository($this->pdo, $this->encryption);
        $scoutYearService = new ScoutYearService($this->pdo);
        $movementClassifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYearService);
        $this->service = new SectionRosterService(
            new SectionRosterRepository($this->pdo),
            $this->encryption,
            $memberEmailRepository,
            $movementClassifier
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
    }

    public function testMembersAreClassifiedIntoTheThreeBuckets(): void
    {
        $this->createMember('Alice', 'chief');
        $this->createMember('Bob', 'intendant');
        $this->createMember('Chloé', 'identified');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertCount(1, $roster[$this->sectionId]['animateurs']);
        $this->assertSame('Alice', $roster[$this->sectionId]['animateurs'][0]->firstName);
        $this->assertCount(1, $roster[$this->sectionId]['intendants']);
        $this->assertSame('Bob', $roster[$this->sectionId]['intendants'][0]->firstName);
        $this->assertCount(1, $roster[$this->sectionId]['animes']);
        $this->assertSame('Chloé', $roster[$this->sectionId]['animes'][0]->firstName);
    }

    public function testEmptyBucketsAreReturnedAsEmptyArraysNotMissing(): void
    {
        $this->createMember('Alice', 'identified');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([], $roster[$this->sectionId]['animateurs']);
        $this->assertSame([], $roster[$this->sectionId]['intendants']);
        $this->assertCount(1, $roster[$this->sectionId]['animes']);
    }

    public function testMainFunctionDecidesTheBucketWhenAMemberHasSeveralFunctionsInTheSameSection(): void
    {
        $memberYearId = $this->createMember('Alice', 'identified');
        // A secondary, non-main "chief" function must not move Alice into
        // the animateurs bucket — only the main function decides.
        $this->attachFunction($memberYearId, 'chief', false);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertCount(1, $roster[$this->sectionId]['animes']);
        $this->assertSame([], $roster[$this->sectionId]['animateurs']);
    }

    public function testMemberWithNoEmailHasAnEmptyEmailList(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $row = $this->findRow($roster, $memberYearId);
        $this->assertSame([], $row->emails);
    }

    public function testMemberWithOneEmailHasExactlyOne(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', 'alice@example.com', null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $row = $this->findRow($roster, $memberYearId);
        $this->assertSame(['alice@example.com'], $row->emails);
    }

    public function testMemberWithSeveralEmailsHasAllOfThem(): void
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'alice@example.com', null, null);
        $this->attachFunctionTo($memberYearId, 'identified');

        // A confirmed secondary address.
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'valid')"
        );
        $stmt->execute([$memberId, $this->encryption->encrypt('alice.secondary@example.com'), $this->encryption->blindIndex('alice.secondary@example.com')]);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(2, $row->emails);
        $this->assertContains('alice@example.com', $row->emails);
        $this->assertContains('alice.secondary@example.com', $row->emails);
    }

    public function testPendingAndInactiveSecondaryEmailsAreNotIncluded(): void
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'alice@example.com', null, null);
        $this->attachFunctionTo($memberYearId, 'identified');

        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'pending')"
        );
        $stmt->execute([$memberId, $this->encryption->encrypt('pending@example.com'), $this->encryption->blindIndex('pending@example.com')]);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertSame(['alice@example.com'], $row->emails);
    }

    public function testMemberWithNoPhoneHasAnEmptyPhoneList(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertSame([], $row->phones);
    }

    public function testMemberWithOnePhoneHasExactlyOne(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, '0470123456', null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(1, $row->phones);
        $this->assertSame('Téléphone', $row->phones[0]['label']);
    }

    public function testMemberWithBothPhoneAndMobileHasBoth(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, '024651234', '0470123456');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(2, $row->phones);
        $labels = array_column($row->phones, 'label');
        $this->assertContains('Téléphone', $labels);
        $this->assertContains('GSM', $labels);
    }

    /**
     * @param array<int, array{animateurs: \Core\Member\MemberRosterRow[], intendants: \Core\Member\MemberRosterRow[], animes: \Core\Member\MemberRosterRow[]}> $roster
     */
    private function findRow(array $roster, int $memberYearId): \Core\Member\MemberRosterRow
    {
        foreach ($roster[$this->sectionId] as $bucket) {
            foreach ($bucket as $row) {
                if ($row->memberYearId === $memberYearId) {
                    return $row;
                }
            }
        }
        $this->fail('Row not found for member_year_id ' . $memberYearId);
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(string $firstName, string $functionRole): int
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'x@example.com', '0470000000', null, $firstName);
        $this->attachFunctionTo($memberYearId, $functionRole);
        return $memberYearId;
    }

    private function createMemberWithContacts(string $firstName, ?string $email, ?string $phone, ?string $mobile): int
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, $email, $phone, $mobile, $firstName);
        $this->attachFunctionTo($memberYearId, 'identified');
        return $memberYearId;
    }

    private function insertMemberBase(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        return (int) $this->pdo->lastInsertId();
    }

    private function insertMemberYear(int $memberId, ?string $email, ?string $phone, ?string $mobile, string $firstName = 'Alice'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, phone_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName),
            $this->encryption->encrypt('Dupont'),
            $email !== null ? $this->encryption->encrypt($email) : null,
            $email !== null ? $this->encryption->blindIndex(strtolower($email)) : null,
            $phone !== null ? $this->encryption->encrypt($phone) : null,
            $mobile !== null ? $this->encryption->encrypt($mobile) : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function attachFunctionTo(int $memberYearId, string $functionRole): void
    {
        $this->attachFunction($memberYearId, $functionRole, true);
    }

    private function attachFunction(int $memberYearId, string $functionRole, bool $isMain): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = $stmt->fetchColumn();
        if ($functionId === false) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')->execute([$functionRole, ucfirst($functionRole), $functionRole]);
            $functionId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId, $isMain ? 1 : 0]);
    }
}
