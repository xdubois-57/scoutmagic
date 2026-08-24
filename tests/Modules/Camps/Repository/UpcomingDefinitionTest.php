<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Repository;

use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * "Upcoming" is written twice — once as PHP in Repository\Camp::
 * isUpcoming(), once as SQL in CampRepository::UPCOMING_SQL — because the
 * list needs the database to filter and the place sheet needs to split a
 * list it already holds.
 *
 * Two definitions of one rule drift. When these two drift, a camp appears
 * in the main screen's "À venir" while its own place calls it past, on one
 * day of the year, for one shape of camp. This test is the reason that
 * cannot happen quietly: every interesting case is asserted against BOTH.
 */
class UpcomingDefinitionTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->camps = new CampRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
    }

    /**
     * @dataProvider cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testBothDefinitionsAgree(
        string $today,
        ?string $endDate,
        ?int $yearOnly,
        string $status,
        bool $expected,
        string $why
    ): void {
        $id = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, $endDate, $endDate, $yearOnly, $status,
            null, null, null, null, []
        );
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        $inPhp = $camp->isUpcoming(new \DateTimeImmutable($today));
        $inSql = $this->camps->findUpcoming(new \DateTimeImmutable($today)) !== [];

        $this->assertSame($expected, $inPhp, "PHP disagrees: {$why}");
        $this->assertSame($expected, $inSql, "SQL disagrees: {$why}");
    }

    /**
     * @return array<string, array{string, ?string, ?int, string, bool, string}>
     */
    public static function cases(): array
    {
        return [
            'a dated camp in the future is upcoming' => [
                '2026-08-24', '2028-07-19', null, Camp::STATUS_CONFIRMED, true,
                'it has not happened yet',
            ],
            'a dated camp in the past is not' => [
                '2026-08-24', '2024-07-19', null, Camp::STATUS_CONFIRMED, false,
                'it is over',
            ],
            'a camp ending TODAY is still upcoming' => [
                '2026-08-24', '2026-08-24', null, Camp::STATUS_CONFIRMED, true,
                'the unit is still on the field on the last day',
            ],
            'a camp that ended yesterday is not' => [
                '2026-08-24', '2026-08-23', null, Camp::STATUS_CONFIRMED, false,
                'everyone is home',
            ],
            'a cancelled future camp is never upcoming' => [
                '2026-08-24', '2028-07-19', null, Camp::STATUS_CANCELLED, false,
                'nobody is going',
            ],
            'a to-confirm future camp is upcoming' => [
                '2026-08-24', '2028-07-19', null, Camp::STATUS_TO_CONFIRM, true,
                'unconfirmed is still a plan',
            ],
            'a year-only camp for a future year is upcoming' => [
                '2026-08-24', null, 2029, Camp::STATUS_TO_CONFIRM, true,
                'the year has not come',
            ],
            'a year-only camp for THIS year is still upcoming in August' => [
                '2026-08-24', null, 2026, Camp::STATUS_TO_CONFIRM, true,
                'the year is not over, and nobody moves it by hand',
            ],
            'a year-only camp for this year is still upcoming on 31 December' => [
                '2026-12-31', null, 2026, Camp::STATUS_TO_CONFIRM, true,
                'the last day of its year still belongs to it',
            ],
            'the same camp becomes past on 1 January' => [
                '2027-01-01', null, 2026, Camp::STATUS_TO_CONFIRM, false,
                'its year is over — this is the boundary the whole rule exists for',
            ],
            'a cancelled year-only camp is never upcoming' => [
                '2026-08-24', null, 2029, Camp::STATUS_CANCELLED, false,
                'cancelled beats every date rule',
            ],
        ];
    }

    public function testAnArchivedPlacesUpcomingStayLeavesTheMainList(): void
    {
        $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2028-07-12', '2028-07-19', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1');

        // The place sheet still shows it (Camp::isUpcoming knows nothing
        // about archiving, and should not) — but the main screen must not,
        // or archiving would hide a place while advertising its camps.
        $this->assertSame([], $this->camps->findUpcoming(new \DateTimeImmutable('2026-08-24')));
    }
}
