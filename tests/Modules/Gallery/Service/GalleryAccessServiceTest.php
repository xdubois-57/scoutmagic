<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Service\GalleryAccessService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryAccessServiceTest extends TestCase
{
    private \PDO $pdo;
    private GalleryAccessService $service;
    private EncryptionService $encryption;
    private int $chiefSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);

        $this->service = new GalleryAccessService($memberService, $sectionService, $scoutYearService);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->chiefSectionId = (int) $this->pdo->lastInsertId();
        $stmt->execute([$branchId, 'MEUTE_B', 'Meute B']);
    }

    private function createChiefLinkedToSection(string $email, int $sectionId, string $role = 'chief'): void
    {
        $scoutYear = (new ScoutYearService($this->pdo))->getCurrentYear();

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['M' . random_int(1000, 999999)]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $scoutYear['id'],
            $this->encryption->encrypt('Jean'), $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($email), $this->encryption->blindIndex($email),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)");
        $stmt->execute(['CHIEF_FN_' . random_int(1000, 999999), 'Chef', $role]);
        $functionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    public function testAdminCanManageAnySectionAlbum(): void
    {
        $this->assertTrue($this->service->canManageAlbum(Role::ADMIN, $this->chiefSectionId, 'nobody@test.com'));
    }

    public function testAnyChiefCanManageAUnitWideAlbum(): void
    {
        $this->assertTrue($this->service->canManageAlbum(Role::CHIEF, null, 'nobody@test.com'));
    }

    public function testIntendantCannotManageAnyAlbum(): void
    {
        $this->assertFalse($this->service->canManageAlbum(Role::INTENDANT, null, 'nobody@test.com'));
    }

    public function testChiefLinkedToTheSectionCanManageIt(): void
    {
        $this->createChiefLinkedToSection('chief@test.com', $this->chiefSectionId);

        $this->assertTrue($this->service->canManageAlbum(Role::CHIEF, $this->chiefSectionId, 'chief@test.com'));
    }

    public function testChiefNotLinkedToTheSectionCannotManageIt(): void
    {
        $this->createChiefLinkedToSection('chief@test.com', $this->chiefSectionId);

        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute(['MEUTE_B']);
        $otherSectionId = (int) $stmt->fetchColumn();

        $this->assertFalse($this->service->canManageAlbum(Role::CHIEF, $otherSectionId, 'chief@test.com'));
    }

    public function testGetManagedSectionIdsReturnsOnlyChiefPlusFunctions(): void
    {
        $this->createChiefLinkedToSection('intendant@test.com', $this->chiefSectionId, 'intendant');

        $this->assertSame([], $this->service->getManagedSectionIds('intendant@test.com'));
    }
}
