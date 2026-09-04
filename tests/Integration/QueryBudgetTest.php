<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Database\Connection;
use Core\Database\InstrumentedPdo;
use Core\Database\QueryCounter;
use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\EncryptionService;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The query budget of the member-listing paths, on a real (SQLite)
 * database counted by Core\Database\InstrumentedPdo.
 *
 * Every assertion here is a shape, not a number: the statement count of a
 * page that lists N members or N sections must be the same for a small
 * unit and a large one. The counts themselves are pinned at the value the
 * batched code issues today, so a change that quietly reintroduces a
 * per-member or per-section query fails here before it reaches a unit of
 * 900 members (docs/chantiers/CHANTIER-performance.md).
 */
final class QueryBudgetTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $branchId;
    private int $chiefFunctionId;
    private int $animeFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase(new InstrumentedPdo('sqlite::memory:'));
        $this->pdo->exec('CREATE TABLE trombinoscope_function_flags (function_id INTEGER PRIMARY KEY, is_lead INTEGER NOT NULL DEFAULT 0)');
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CHEF', 'Animateur', 'chief', 1)");
        $this->chiefFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIME', 'Animé', 'identified', 1)");
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();
    }

    /** @return array<int, int> section ids */
    private int $sectionsCreated = 0;

    private function unit(int $sections, int $staffPerSection, int $animesPerSection): array
    {
        $ids = [];
        for ($s = 0; $s < $sections; $s++) {
            $code = 'SEC' . (++$this->sectionsCreated);
            $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
            $stmt->execute([$code, $this->branchId, 'Section ' . $code]);
            $sectionId = (int) $this->pdo->lastInsertId();
            $ids[] = $sectionId;
            for ($i = 0; $i < $staffPerSection; $i++) {
                $this->member($sectionId, $this->chiefFunctionId, "Chef{$s}-{$i}");
            }
            for ($i = 0; $i < $animesPerSection; $i++) {
                $this->member($sectionId, $this->animeFunctionId, "Kid{$s}-{$i}");
            }
        }

        return $ids;
    }

    private function member(int $sectionId, int $functionId, string $firstName): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $this->branchId]);

        return $memberId;
    }

    private function sectionService(): SectionService
    {
        return new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo));
    }

    /**
     * @return int statements issued by $work
     */
    private function statementsOf(callable $work): int
    {
        QueryCounter::reset();
        $work();

        return QueryCounter::count();
    }

    public function testTheTrombinoscopeWallCostsTheSameWhateverTheNumberOfSections(): void
    {
        $small = $this->unit(2, 3, 0);
        $service = new TrombinoscopeService(new TrombinoscopeRepository(Connection::withPdo($this->pdo)), $this->sectionService());
        $smallCount = $this->statementsOf(fn() => $service->getSectionStaffForSections($small, $this->scoutYearId));

        $large = array_merge($small, $this->unit(12, 6, 0));
        $largeCount = $this->statementsOf(fn() => $service->getSectionStaffForSections($large, $this->scoutYearId));

        $this->assertSame($smallCount, $largeCount, 'fourteen sections must not cost more statements than two');
        $this->assertLessThanOrEqual(5, $largeCount);
        $this->assertCount(14, $service->getSectionStaffForSections($large, $this->scoutYearId));
        $this->assertSame($smallCount, $this->statementsOf(fn() => $service->getResponsables($large, $this->scoutYearId)));
    }

    public function testStaffAndAnimesOfEverySectionCostTheSameWhateverTheUnitSize(): void
    {
        $service = $this->sectionService();
        $small = $this->unit(2, 2, 5);
        $smallCount = $this->statementsOf(function () use ($service, $small) {
            $service->getStaffForSections($small, $this->scoutYearId);
            $service->getAnimesForSections($small, $this->scoutYearId);
        });

        $large = array_merge($small, $this->unit(10, 5, 30));
        $largeCount = $this->statementsOf(function () use ($service, $large) {
            $service->getStaffForSections($large, $this->scoutYearId);
            $service->getAnimesForSections($large, $this->scoutYearId);
        });

        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(10, $largeCount);
        $animes = $service->getAnimesForSections($large, $this->scoutYearId);
        $this->assertSame(2 * 5 + 10 * 30, array_sum(array_map('count', $animes)), 'every animé of every section is there');
    }

    public function testPrimingThePhotosOfAWholePageIsOneStatement(): void
    {
        $memberIds = [];
        foreach ($this->unit(3, 20, 0) as $sectionId) {
            $stmt = $this->pdo->prepare(
                'SELECT my.member_id FROM member_years my JOIN member_functions mf ON mf.member_year_id = my.id WHERE mf.section_id = ?'
            );
            $stmt->execute([$sectionId]);
            $memberIds = [...$memberIds, ...array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))];
        }
        $this->assertCount(60, $memberIds);
        $service = new MemberPhotoService(new MemberPhotoRepository($this->pdo));

        $priming = $this->statementsOf(fn() => $service->primeFileIds($memberIds, $this->scoutYearId));
        $this->assertSame(1, $priming);

        $rendering = $this->statementsOf(function () use ($service, $memberIds) {
            foreach ($memberIds as $memberId) {
                $service->resolveFileId($memberId, $this->scoutYearId);
            }
        });
        $this->assertSame(0, $rendering, 'sixty portraits rendered from the memo, without a query each');
    }
}
