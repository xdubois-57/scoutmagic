<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MemberResolutionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberResolutionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberResolutionRepository $repository;
    private int $scoutYearId;
    private int $sectionAId;
    private int $sectionBId;
    private int $functionAnimateurId;
    private int $functionChiefId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberResolutionRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();

        $this->sectionAId = $this->createSection('LOU01', $branchId, 'Meute A');
        $this->sectionBId = $this->createSection('LOU02', $branchId, 'Meute B');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionAnimateurId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Chef', 'chief')");
        $this->functionChiefId = (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{member_id: int, member_year_id: int}
     */
    private function createMember(string $email, bool $active = true): array
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('John'), $this->encryption->encrypt('Doe'),
            $email !== '' ? $this->encryption->encrypt($email) : null,
            $email !== '' ? $this->encryption->blindIndex($email) : null,
            $active ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    private function assignFunction(int $memberYearId, int $functionId, int $sectionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    /**
     * unit_mail_consent (the Desk "Courrier d'unité" column) is
     * deliberately never checked — a member with consent=0 (the SQLite
     * column default) must still be resolved.
     */
    public function testResolveSectionMembersIgnoresUnitMailConsent(): void
    {
        $member = $this->createMember('a@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $stmt = $this->pdo->prepare('SELECT unit_mail_consent FROM member_years WHERE id = ?');
        $stmt->execute([$member['member_year_id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($member['member_id'], $resolved[0]['member_id']);
        $this->assertSame('a@test.be', $resolved[0]['email']);
    }

    public function testResolveSectionMembersOnlyIncludesTheGivenSection(): void
    {
        $inA = $this->createMember('a@test.be');
        $this->assignFunction($inA['member_year_id'], $this->functionAnimateurId, $this->sectionAId);
        $inB = $this->createMember('b@test.be');
        $this->assignFunction($inB['member_year_id'], $this->functionAnimateurId, $this->sectionBId);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($inA['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveActiveMembersExcludesInactiveMembers(): void
    {
        $active = $this->createMember('a@test.be', active: true);
        $inactive = $this->createMember('b@test.be', active: false);

        $resolved = $this->repository->resolveActiveMembers($this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($active['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveChiefsOnlyIncludesChiefRoleAndAbove(): void
    {
        $chief = $this->createMember('chief@test.be');
        $this->assignFunction($chief['member_year_id'], $this->functionChiefId, $this->sectionAId);

        $animator = $this->createMember('animator@test.be');
        $this->assignFunction($animator['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveChiefs($this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($chief['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveCustomListRequiresBothFunctionAndSectionMatch(): void
    {
        // Qualifies: has functionChief in sectionA.
        $qualifies = $this->createMember('qualifies@test.be');
        $this->assignFunction($qualifies['member_year_id'], $this->functionChiefId, $this->sectionAId);

        // Does not qualify: has functionChief, but in sectionB (not selected).
        $wrongSection = $this->createMember('wrong-section@test.be');
        $this->assignFunction($wrongSection['member_year_id'], $this->functionChiefId, $this->sectionBId);

        // Does not qualify: in sectionA, but with functionAnimateur (not selected).
        $wrongFunction = $this->createMember('wrong-function@test.be');
        $this->assignFunction($wrongFunction['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveCustomList([$this->functionChiefId], [$this->sectionAId], $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($qualifies['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveCustomListReturnsEmptyWhenEitherCriteriaGroupIsEmpty(): void
    {
        $this->assertSame([], $this->repository->resolveCustomList([], [$this->sectionAId], $this->scoutYearId));
        $this->assertSame([], $this->repository->resolveCustomList([$this->functionChiefId], [], $this->scoutYearId));
    }

    public function testEmailAddressIsDecryptedNotStoredCleartextRoundTrip(): void
    {
        $member = $this->createMember('secret@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $stmt = $this->pdo->prepare('SELECT email_encrypted FROM member_years WHERE id = ?');
        $stmt->execute([$member['member_year_id']]);
        $rawStored = (string) $stmt->fetchColumn();
        $this->assertStringNotContainsString('secret@test.be', $rawStored);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);
        $this->assertSame('secret@test.be', $resolved[0]['email']);
    }

    public function testMemberWithNoEmailResolvesWithNullEmail(): void
    {
        $member = $this->createMember('');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertNull($resolved[0]['email']);
    }
}
