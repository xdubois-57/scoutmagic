<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Module\MemberCampStayProvider;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\MemberCampStayService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * Where a member went, as the admin member page shows it.
 *
 * Nothing records a camp's participants one by one — camps link to
 * SECTIONS — so every case here is really about the same question: does
 * the inference claim only what the site knows?
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberCampStayServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private PlaceRepository $places;
    private MemberCampStayService $service;
    private int $memberId;
    private int $meuteId;
    private int $rucheId;
    private int $year2526;
    private int $year2425;
    private int $placeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->places = new PlaceRepository($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->year2425 = $scoutYearService->ensureYear('2024-2025');
        $this->year2526 = $scoutYearService->ensureYear('2025-2026');

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->meuteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Ruche')");
        $this->rucheId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $this->placeId = $this->places->create('Ferme de Beaumont', null, null, 'Beaumont', 'BE', null);

        $this->service = new MemberCampStayService(
            $this->camps,
            $this->places,
            new SectionMembershipRepository($this->pdo),
            new SectionService($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo)),
            $scoutYearService
        );
    }

    private function wasIn(int $sectionId, int $scoutYearId, string $startDate): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date) VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->execute([$this->memberId, $sectionId, $scoutYearId, $startDate]);
    }

    /**
     * @param int[] $sectionIds
     */
    private function stay(?string $start, ?string $end, ?int $yearOnly, array $sectionIds): int
    {
        return $this->camps->create(
            $this->placeId,
            'grand_camp',
            $start,
            $end,
            $yearOnly,
            'confirmed',
            null,
            null,
            null,
            null,
            $sectionIds
        );
    }

    public function testAStayHerSectionWentOnThatYearIsHers(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $stayId = $this->stay('2026-07-12', '2026-07-19', null, [$this->meuteId]);

        $stays = $this->service->getCampStays($this->memberId);

        $this->assertCount(1, $stays);
        $this->assertSame('Ferme de Beaumont', $stays[0]->placeLabel);
        $this->assertSame('Meute', $stays[0]->sectionName);
        $this->assertSame('2025-2026', $stays[0]->scoutYearLabel);
        $this->assertSame('/chefs/camps/sejours/' . $stayId, $stays[0]->path);
        $this->assertNotSame('', $stays[0]->periodLabel);
    }

    /**
     * The heart of the inference: belonging to the section is not enough,
     * she has to have belonged to it IN the year the stay happened.
     */
    public function testAStayHerSectionWentOnBeforeSheJoinedItIsNotHers(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $this->stay('2025-07-12', '2025-07-19', null, [$this->meuteId]);

        $this->assertSame([], $this->service->getCampStays($this->memberId));
    }

    public function testAStayAnotherSectionWentOnIsNotHers(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $this->stay('2026-07-12', '2026-07-19', null, [$this->rucheId]);

        $this->assertSame([], $this->service->getCampStays($this->memberId));
    }

    /**
     * A grand camp happens in July or August, so a bare "2012" is read as
     * the scout year ENDING in it. Dropping those stays instead would
     * silently empty the block for exactly the old camps this exists to
     * remember.
     */
    public function testAStayRememberedOnlyByItsYearIsPlacedAtTheEndOfThatScoutYear(): void
    {
        $this->wasIn($this->meuteId, $this->year2425, '2024-09-01');
        $this->stay(null, null, 2025, [$this->meuteId]);

        $stays = $this->service->getCampStays($this->memberId);

        $this->assertCount(1, $stays);
        $this->assertSame('2024-2025', $stays[0]->scoutYearLabel);
        $this->assertSame('2025', $stays[0]->periodLabel);
    }

    public function testMostRecentFirst(): void
    {
        $this->wasIn($this->meuteId, $this->year2425, '2024-09-01');
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $this->stay('2025-07-12', '2025-07-19', null, [$this->meuteId]);
        $this->stay('2026-07-12', '2026-07-19', null, [$this->meuteId]);

        $stays = $this->service->getCampStays($this->memberId);

        $this->assertSame(['2025-2026', '2024-2025'], array_map(fn($s) => $s->scoutYearLabel, $stays));
    }

    /**
     * A stay two of her sections both went on is ONE stay: the reader
     * wants where she went, not how many rows the join produced.
     */
    public function testAStayTwoOfHerSectionsWentOnIsStillOneLine(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $this->wasIn($this->rucheId, $this->year2526, '2026-01-15');
        $this->stay('2026-07-12', '2026-07-19', null, [$this->meuteId, $this->rucheId]);

        $this->assertCount(1, $this->service->getCampStays($this->memberId));
    }

    public function testAMemberWithNoSectionHistoryGetsNothingRatherThanAnError(): void
    {
        $this->stay('2026-07-12', '2026-07-19', null, [$this->meuteId]);

        $this->assertSame([], $this->service->getCampStays($this->memberId));
    }

    /**
     * A stay with neither a readable date nor a year cannot be attached
     * to anybody: the site does not know when it happened.
     */
    public function testAnUndatableStayIsAttachedToNobody(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        $this->stay(null, null, null, [$this->meuteId]);

        $this->assertSame([], $this->service->getCampStays($this->memberId));
    }

    public function testTheListIsCapped(): void
    {
        $this->wasIn($this->meuteId, $this->year2526, '2025-09-01');
        for ($i = 0; $i < MemberCampStayProvider::LIMIT + 3; $i++) {
            $this->stay('2026-07-0' . (($i % 8) + 1), '2026-07-1' . (($i % 9) + 1), null, [$this->meuteId]);
        }

        $this->assertCount(MemberCampStayProvider::LIMIT, $this->service->getCampStays($this->memberId));
    }
}
