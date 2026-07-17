<?php

declare(strict_types=1);

namespace Tests\Modules\MemberStats\Service;

use Core\Member\MemberYearService;
use Modules\MemberStats\Repository\MemberStatsRepository;
use Modules\MemberStats\Service\MemberStatsService;
use PHPUnit\Framework\TestCase;

/**
 * Aggregation logic: mapping members to branch-year cells via
 * MemberYearService::getEffectiveAge() (birth year + scout_year_offset),
 * gender bucketing, totals, and bar-scaling max — all with a stubbed
 * repository so no encryption/database is involved.
 */
class MemberStatsServiceTest extends TestCase
{
    private const REFERENCE_YEAR = 2026;

    /**
     * @param array<int, array{branch_label: string, branch_sort_order: int, birth_date: ?string, gender: ?string, scout_year_offset: int}> $rows
     */
    private function service(array $rows): MemberStatsService
    {
        $repo = new class ($rows) extends MemberStatsRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $stubRows)
            {
                // Intentionally skip parent constructor: no DB/encryption in unit tests.
            }

            public function getMemberBranchData(int $scoutYearId): array
            {
                return $this->stubRows;
            }
        };

        return new MemberStatsService($repo, new MemberYearService());
    }

    /**
     * @param string $label
     * @param string $birthDate
     * @param string|null $gender
     * @return array{branch_label: string, branch_sort_order: int, birth_date: ?string, gender: ?string, scout_year_offset: int}
     */
    private function row(string $label, string $birthDate, ?string $gender, int $sort = 10, int $offset = 0): array
    {
        return [
            'branch_label' => $label,
            'branch_sort_order' => $sort,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'scout_year_offset' => $offset,
        ];
    }

    public function testFourBranchesAlwaysPresentInOrderWhenEmpty(): void
    {
        $stats = $this->service([])->getStatistics(1, self::REFERENCE_YEAR);

        $names = array_column($stats['branches'], 'name');
        $this->assertSame(['Baladins', 'Louveteaux', 'Éclaireurs', 'Pionniers'], $names);

        // 2 + 4 + 4 + 2 = 12 rows total.
        $rowCount = array_sum(array_map(fn($b) => count($b['rows']), $stats['branches']));
        $this->assertSame(12, $rowCount);

        $this->assertSame(['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0], $stats['totals']);
        $this->assertSame(0, $stats['max_count']);
    }

    public function testBranchMetadata(): void
    {
        $stats = $this->service([])->getStatistics(1, self::REFERENCE_YEAR);

        $this->assertSame('6–7', $stats['branches'][0]['age_range']);
        $this->assertSame('#378ADD', $stats['branches'][0]['color']);
        $this->assertSame('#639922', $stats['branches'][1]['color']);
        $this->assertSame('#1D9E75', $stats['branches'][2]['color']);
        $this->assertSame('16–17', $stats['branches'][3]['age_range']);
        $this->assertSame('#D85A30', $stats['branches'][3]['color']);
    }

    public function testBirthYearLabelsPerRow(): void
    {
        $stats = $this->service([])->getStatistics(1, self::REFERENCE_YEAR);

        // Baladins: age 6 → born 2020 (1ère année), age 7 → born 2019 (2e année).
        $baladins = $stats['branches'][0]['rows'];
        $this->assertSame('1ère année', $baladins[0]['year_label']);
        $this->assertSame(2020, $baladins[0]['birth_year']);
        $this->assertSame('2e année', $baladins[1]['year_label']);
        $this->assertSame(2019, $baladins[1]['birth_year']);

        // Pionniers: age 16 → 2010, age 17 → 2009.
        $pionniers = $stats['branches'][3]['rows'];
        $this->assertSame(2010, $pionniers[0]['birth_year']);
        $this->assertSame(2009, $pionniers[1]['birth_year']);
    }

    public function testAggregationAcrossBranchesAndGenders(): void
    {
        $rows = [
            $this->row('Baladins', '2020-01-01', 'M'),   // age 6 → y1 male
            $this->row('Baladins', '2020-05-05', 'F'),   // age 6 → y1 female
            $this->row('Baladins', '15/03/2019', 'M'),   // age 7 → y2 male (DD/MM/YYYY)
            $this->row('Louveteaux', '2018-01-01', 'H'), // age 8 → y1 male (H = male)
            $this->row('Louveteaux', '2015-01-01', 'F'), // age 11 → y4 female
            $this->row('Éclaireurs', '2014-01-01', 'X'), // age 12 → y1 other
            $this->row('Pionniers', '2010-01-01', 'M'),  // age 16 → y1 male
            $this->row('Pionniers', '2009-06-06', 'F'),  // age 17 → y2 female
            $this->row("Staff d'U", '1990-01-01', 'M'),  // age 36 → outside every branch → skipped
            $this->row('Baladins', '', 'M'),             // no birth date → skipped
            $this->row('Baladins', 'inconnu', 'F'),      // unparseable → skipped
        ];

        $stats = $this->service($rows)->getStatistics(1, self::REFERENCE_YEAR);

        $this->assertSame(['total' => 8, 'male' => 4, 'female' => 3, 'other' => 1], $stats['totals']);
        // Baladins 1ère année has 2 members — the tallest bar.
        $this->assertSame(2, $stats['max_count']);

        $baladins = $stats['branches'][0]['rows'];
        $this->assertSame(['total' => 2, 'male' => 1, 'female' => 1, 'other' => 0], $this->cell($baladins[0]));
        $this->assertSame(['total' => 1, 'male' => 1, 'female' => 0, 'other' => 0], $this->cell($baladins[1]));

        $louveteaux = $stats['branches'][1]['rows'];
        $this->assertSame(['total' => 1, 'male' => 1, 'female' => 0, 'other' => 0], $this->cell($louveteaux[0]));
        $this->assertSame(['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0], $this->cell($louveteaux[1]));
        $this->assertSame(['total' => 1, 'male' => 0, 'female' => 1, 'other' => 0], $this->cell($louveteaux[3]));

        $eclaireurs = $stats['branches'][2]['rows'];
        $this->assertSame(['total' => 1, 'male' => 0, 'female' => 0, 'other' => 1], $this->cell($eclaireurs[0]));

        $pionniers = $stats['branches'][3]['rows'];
        $this->assertSame(['total' => 1, 'male' => 1, 'female' => 0, 'other' => 0], $this->cell($pionniers[0]));
        $this->assertSame(['total' => 1, 'male' => 0, 'female' => 1, 'other' => 0], $this->cell($pionniers[1]));
    }

    public function testAgeOutsideAnyBranchRangeIsExcluded(): void
    {
        // Born 2005 → age 21 in 2026: outside every branch (Baladins..Pionniers
        // cover 6-17). Branch/year now comes exclusively from getEffectiveAge(),
        // so an out-of-range effective age is excluded rather than clamped into
        // whatever branch the Desk export happened to label the row with.
        $rows = [$this->row('Baladins', '2005-01-01', 'M')];
        $stats = $this->service($rows)->getStatistics(1, self::REFERENCE_YEAR);

        $baladins = $stats['branches'][0]['rows'];
        $this->assertSame(0, $baladins[0]['total']);
        $this->assertSame(0, $baladins[1]['total']);
        $this->assertSame(0, $stats['totals']['total']);
    }

    public function testScoutYearOffsetShiftsAMemberIntoTheAdjacentBranch(): void
    {
        // Born 2014 → raw age 12 (éclaireurs 1ère année). A chief-applied -1
        // offset moves the effective age to 11 → louveteaux 4e année.
        $rows = [$this->row('Éclaireurs', '2014-01-01', 'F', offset: -1)];
        $stats = $this->service($rows)->getStatistics(1, self::REFERENCE_YEAR);

        $louveteaux = $stats['branches'][1]['rows'];
        $this->assertSame(1, $louveteaux[3]['total']); // 4e année
        $this->assertSame(1, $louveteaux[3]['female']);

        $eclaireurs = $stats['branches'][2]['rows'];
        $this->assertSame(0, $eclaireurs[0]['total']);

        $this->assertSame(1, $stats['totals']['total']);
    }

    public function testScoutYearOffsetCanBringAnOutOfRangeMemberBackIntoABranch(): void
    {
        // Born 2020 → raw age 6 (baladins 1ère année). A -1 offset would push
        // this particular member below every branch on its own, but a +1
        // offset on a member who would otherwise be excluded brings them back
        // in: born 2021 → raw age 5 (below every branch), offset +1 → age 6 →
        // baladins 1ère année.
        $rows = [$this->row('Baladins', '2021-01-01', 'M', offset: 1)];
        $stats = $this->service($rows)->getStatistics(1, self::REFERENCE_YEAR);

        $baladins = $stats['branches'][0]['rows'];
        $this->assertSame(1, $baladins[0]['total']);
        $this->assertSame(1, $stats['totals']['total']);
    }

    public function testAccentlessEclaireurLabelStillMatches(): void
    {
        $rows = [$this->row('Eclaireurs', '2014-01-01', 'F')];
        $stats = $this->service($rows)->getStatistics(1, self::REFERENCE_YEAR);

        $this->assertSame(1, $stats['branches'][2]['rows'][0]['female']);
        $this->assertSame(1, $stats['totals']['female']);
    }

    public function testReferenceYearIsTheScoutYearStartYear(): void
    {
        // "2025-2026" → 2025, so figures do not drift with today's date and are
        // correct when viewing a past scout year.
        $this->assertSame(2025, MemberYearService::referenceYearFromScoutYearLabel('2025-2026'));
        $this->assertSame(2023, MemberYearService::referenceYearFromScoutYearLabel('2023-2024'));
        $this->assertSame((int) date('Y'), MemberYearService::referenceYearFromScoutYearLabel('sans année'));
    }

    public function testBirthYearLabelsUseTheScoutYearStartYear(): void
    {
        // For scout year 2025-2026 (reference 2025), baladins are born 2019 & 2018.
        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel('2025-2026');
        $stats = $this->service([])->getStatistics(1, $referenceYear);

        $baladins = $stats['branches'][0]['rows'];
        $this->assertSame(2019, $baladins[0]['birth_year']);
        $this->assertSame(2018, $baladins[1]['birth_year']);

        // And pionniers (16-17) are born 2009 & 2008.
        $pionniers = $stats['branches'][3]['rows'];
        $this->assertSame(2009, $pionniers[0]['birth_year']);
        $this->assertSame(2008, $pionniers[1]['birth_year']);
    }

    /**
     * @param array{year_label: string, birth_year: int, total: int, male: int, female: int, other: int} $row
     * @return array{total: int, male: int, female: int, other: int}
     */
    private function cell(array $row): array
    {
        return ['total' => $row['total'], 'male' => $row['male'], 'female' => $row['female'], 'other' => $row['other']];
    }
}
