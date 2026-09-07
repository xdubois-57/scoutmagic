<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * getSectionAnimeMemberIds() — the persistent-identity twin of
 * getSectionAnimeMemberYearIds(), for a table keyed on `members.id`
 * (`presences_records` is the first).
 *
 * The property that matters is that it splits animés from staff by the
 * same rule as its siblings: without the role filter, an animé's own
 * section staff would come back as animés of their own section.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionServiceAnimeMemberIdsTest extends TestCase
{
    private \PDO $pdo;
    private SectionService $service;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $sectionId;
    private int $otherSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new SectionService(
            Connection::withPdo($this->pdo),
            $this->encryption,
            new MemberBadgeRepository($this->pdo)
        );

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionId = $this->createSection($branchId, 'LOU1');
        $this->otherSectionId = $this->createSection($branchId, 'LOU2');
    }

    public function testItHoldsTheSectionsAnimesAndNobodyElse(): void
    {
        $here = $this->createMember('animated', $this->sectionId);
        $elsewhere = $this->createMember('animated', $this->otherSectionId);
        $animateur = $this->createMember('chief', $this->sectionId);
        $intendant = $this->createMember('intendant', $this->sectionId);

        $ids = $this->service->getSectionAnimeMemberIds($this->sectionId, $this->scoutYearId);

        $this->assertSame([$here], $ids);
        $this->assertNotContains($elsewhere, $ids);
        $this->assertNotContains($animateur, $ids);
        $this->assertNotContains($intendant, $ids);
    }

    public function testItAgreesWithTheMemberYearTwinOnWhoIsAnAnime(): void
    {
        $this->createMember('animated', $this->sectionId);
        $this->createMember('animated', $this->sectionId);
        $this->createMember('chief', $this->sectionId);

        $this->assertCount(
            count($this->service->getSectionAnimeMemberYearIds($this->sectionId, $this->scoutYearId)),
            $this->service->getSectionAnimeMemberIds($this->sectionId, $this->scoutYearId)
        );
    }

    public function testAnotherScoutYearsAnimesAreNotThisYears(): void
    {
        $this->createMember('animated', $this->sectionId);

        $this->assertSame([], $this->service->getSectionAnimeMemberIds($this->sectionId, 999_999));
    }

    private function createSection(int $branchId, string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(string $functionRole, int $sectionId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([uniqid('desk', true)]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
            ->execute([$functionRole, $functionRole, $functionRole]);
        $functionId = (int) $this->pdo->query(
            'SELECT id FROM functions WHERE desk_code = ' . $this->pdo->quote($functionRole)
        )->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberId;
    }
}
