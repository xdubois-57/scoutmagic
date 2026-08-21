<?php

declare(strict_types=1);

namespace Tests\Core\Member\Export;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionRosterRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class MemberExportRowBuilderTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberExportRowBuilder $builder;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $sectionService = new SectionService($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $memberEmailRepository = new MemberEmailRepository($this->pdo, $this->encryption);
        $movementClassifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYearService);
        $rosterRepository = new SectionRosterRepository($this->pdo);

        $this->builder = new MemberExportRowBuilder(
            $rosterRepository, $sectionService, $scoutYearService, $this->encryption, $memberEmailRepository, $movementClassifier
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testExportIncludesAnimateursIntendantsAndAnimes(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $this->createMember($sectionId, 'Alice', 'chief');
        $this->createMember($sectionId, 'Bob', 'intendant');
        $this->createMember($sectionId, 'Chloé', 'identified');

        $rows = $this->builder->buildForSections([$sectionId], $this->scoutYearId);

        $names = array_map(fn($r) => $r->firstName, $rows);
        $this->assertCount(3, $rows);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Chloé', $names);
    }

    public function testExportRespectsTheSectionFilterScope(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section A');
        $sectionB = $this->createSection('LOU02', $branchId, 'Section B');
        $this->createMember($sectionA, 'Alice', 'identified');
        $this->createMember($sectionB, 'Bob', 'identified');

        $rows = $this->builder->buildForSections([$sectionA], $this->scoutYearId);

        $names = array_map(fn($r) => $r->firstName, $rows);
        $this->assertSame(['Alice'], $names);
    }

    public function testAllSectionsExportsEveryone(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section A');
        $sectionB = $this->createSection('LOU02', $branchId, 'Section B');
        $this->createMember($sectionA, 'Alice', 'identified');
        $this->createMember($sectionB, 'Bob', 'identified');

        $rows = $this->builder->buildForSections([$sectionA, $sectionB], $this->scoutYearId);

        $this->assertCount(2, $rows);
    }

    public function testMultipleEmailsAndPhonesAreAllPresent(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'Alice', 'primary@example.com', '024651234', '0470123456');
        $this->attachFunction($memberYearId, $sectionId, 'identified');
        $stmt = $this->pdo->prepare("INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status) VALUES (?, ?, ?, 'manual', 'valid')");
        $stmt->execute([$memberId, $this->encryption->encrypt('secondary@example.com', 'member_emails.email'), $this->encryption->blindIndex('secondary@example.com', 'email')]);

        $rows = $this->builder->buildForSections([$sectionId], $this->scoutYearId);
        $row = $rows[0];

        $this->assertCount(2, $row->emails);
        // Landline and mobile share a single export column/cell — never
        // two separate columns.
        $this->assertCount(2, $row->phones);
        $this->assertSame('Téléphone : ' . \Core\Service\TextNormalizerService::normalizePhone('024651234'), $row->phones[0]);
        $this->assertSame('GSM : ' . \Core\Service\TextNormalizerService::normalizePhone('0470123456'), $row->phones[1]);
    }

    public function testASinglePhoneNumberProducesASingleEntry(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'Alice', null, null, '0470123456');
        $this->attachFunction($memberYearId, $sectionId, 'identified');

        $rows = $this->builder->buildForSections([$sectionId], $this->scoutYearId);

        $this->assertSame(['GSM : ' . \Core\Service\TextNormalizerService::normalizePhone('0470123456')], $rows[0]->phones);
    }

    public function testNoPhoneNumberProducesAnEmptyList(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'Alice', null, null, null);
        $this->attachFunction($memberYearId, $sectionId, 'identified');

        $rows = $this->builder->buildForSections([$sectionId], $this->scoutYearId);

        $this->assertSame([], $rows[0]->phones);
    }

    public function testAddressAndFunctionsAreIncludedInTheExportRow(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'Alice', null, null, null);
        $this->attachFunction($memberYearId, $sectionId, 'chief');

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted, postal_code_encrypted, city_encrypted, country_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId, 'main',
            $this->encryption->encrypt('Rue de la Station', 'member_addresses.street'),
            $this->encryption->encrypt('12', 'member_addresses.number'),
            $this->encryption->encrypt('1000', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Bruxelles', 'member_addresses.city'),
            $this->encryption->encrypt('Belgique', 'member_addresses.country'),
        ]);

        $rows = $this->builder->buildForSections([$sectionId], $this->scoutYearId);
        $row = $rows[0];

        $this->assertSame('Rue de la Station', $row->street);
        $this->assertSame('1000', $row->postalCode);
        $this->assertSame('Bruxelles', $row->city);
        $this->assertSame('Ma section', $row->sectionName);
        $this->assertSame('Louveteaux', $row->branchName);
        $this->assertSame('Animateur', $row->roleBucketLabel);
        $this->assertContains('Chief', $row->functionLabels);
    }

    private function createMember(int $sectionId, string $firstName, string $functionRole): int
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, $firstName, null, null, null);
        $this->attachFunction($memberYearId, $sectionId, $functionRole);
        return $memberYearId;
    }

    private function insertMemberBase(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        return (int) $this->pdo->lastInsertId();
    }

    private function insertMemberYear(int $memberId, string $firstName, ?string $email, ?string $phone, ?string $mobile): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, phone_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex($email, 'email') : null,
            $phone !== null ? $this->encryption->encrypt($phone, 'member_years.phone') : null,
            $mobile !== null ? $this->encryption->encrypt($mobile, 'member_years.mobile') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function attachFunction(int $memberYearId, int $sectionId, string $functionRole): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = $stmt->fetchColumn();
        if ($functionId === false) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')->execute([$functionRole, ucfirst($functionRole), $functionRole]);
            $functionId = (int) $this->pdo->lastInsertId();
        }
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    private function createBranch(string $code, string $label, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$code, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId, ?string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }
}
