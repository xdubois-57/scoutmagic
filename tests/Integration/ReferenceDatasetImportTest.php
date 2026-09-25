<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Import\MemberYearRepository;
use Core\Member\Household\HouseholdRepository;
use Core\Member\HouseholdFeeCategory;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\Fixtures\ReferenceDataset\ScenarioCatalog;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;

/**
 * The reference dataset, replayed through the real import pipeline, still
 * means what it says it means.
 *
 * This is the important half of the dataset's protection (chantier §4.2). The
 * format test next door proves the files still parse; this one proves they
 * still SAY something — that the branch passages happened, that the member who
 * left kept their members row, that the returning member inherited their
 * offset, that the emptied section went inactive rather than away, that
 * Staff d'U filled up only once the roles were confirmed, and that a sibling
 * group shares one address blind index.
 *
 * It earns its upkeep twice over, because it is also a real regression test of
 * the import pipeline itself: every assertion below is a property of
 * Core\Import, exercised over a roster with the size and the awkwardness of a
 * real one rather than over five hand-written rows.
 *
 * It replays through Tests\Fixtures\ReferenceDataset\DeskImportReplay, which
 * is the same class the CLI builder uses. A test with its own copy of the
 * wiring would keep passing on the day the builder's copy broke.
 *
 * @see tests/fixtures/reference-dataset/README.md
 */
#[Group('database')]
final class ReferenceDatasetImportTest extends TestCase
{
    private \PDO $pdo;

    /** @var array<string, int> */
    private array $yearIds;

    /** @var list<string> */
    private array $unconfirmed;

    /** Staff d'U membership as it stood after the imports, before Correspondances Desk. */
    private int $unitStaffBeforeConfirmation;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $replay = new DeskImportReplay($this->pdo, $encryption, self::datasetRoot());

        $this->yearIds = $replay->ensureYears();
        $replay->importAll($this->yearIds, 1);

        // The state between the last import and the confirmation is the state
        // a real chief sees before their first visit to Correspondances Desk, and it is
        // worth capturing rather than describing: every function is
        // `identified`, so Staff d'U must have nobody in it yet even though
        // DeskImportService::import() already called syncMembership() three
        // times.
        $this->unitStaffBeforeConfirmation = $this->unitStaffMembershipCount();

        $this->unconfirmed = $replay->confirmFunctionRoles($this->yearIds);
    }

    private static function datasetRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/reference-dataset';
    }

    // ------------------------------------------------------------- effectifs

    public function testEachYearHasTheHeadcountItsBlueprintDeclares(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            $members = $this->countMembersIn($label);

            self::assertGreaterThanOrEqual(170, $members, "The unit in {$label} lost people on the way.");
            self::assertLessThanOrEqual(190, $members, "The unit in {$label} gained people on the way.");
        }
    }

    public function testSectionHeadcountsMatchTheBlueprint(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            foreach (UnitBlueprint::HEADCOUNT[$label] as $handle => [$expectedAnimes, $expectedCadres]) {
                $name = UnitBlueprint::SECTIONS[$handle]['name'];

                self::assertSame(
                    $expectedAnimes,
                    $this->countInSection($label, $name, ['Animé']),
                    "{$name} does not hold the declared number of « animés » in {$label}.",
                );
                self::assertSame(
                    $expectedCadres,
                    // Un « Animateur responsable », un « Intendant » ou un
                    // « Candidat … » compte comme un cadre : le générateur
                    // promeut un animateur existant, il n'ajoute personne
                    // (PopulationBuilder::designateSectionLeads() et
                    // ::designateSectionSpecialists()). La liste vit dans le
                    // blueprint, lu aussi par le générateur, pour que les deux
                    // ne puissent pas diverger.
                    $this->countInSection($label, $name, UnitBlueprint::SECTION_STAFF_FUNCTIONS),
                    "{$name} does not hold the declared number of « cadres » in {$label}.",
                );
            }
        }
    }

    public function testEveryBranchOfTheBlueprintExistsWithItsCanonicalOrder(): void
    {
        // AgeBranchRepository::canonicalSortOrder() resolves by substring, so
        // a branch label that stopped matching would silently land at 99 and
        // sort last everywhere on the site.
        $expected = [
            'Baladins' => 10, 'Louveteaux' => 20, 'Éclaireurs' => 30,
            'Pionniers' => 40, "Staff d'U" => 50, 'Route' => 60, 'Iama' => 70,
        ];

        $rows = $this->pdo->query('SELECT label, sort_order FROM age_branches')?->fetchAll() ?: [];
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['label']] = (int) $row['sort_order'];
        }

        foreach ($expected as $label => $order) {
            self::assertArrayHasKey($label, $actual, "The {$label} branch was not created by the import.");
            self::assertSame($order, $actual[$label], "The {$label} branch does not carry its canonical order.");
        }
    }

    // --------------------------------------------------------- les parcours

    public function testEveryBranchFrontierIsActuallyCrossed(): void
    {
        // Scenario 2 — one Tiers per frontier, each crossing between A1 and A2
        // and holding in A3.
        $crossings = [
            'T0002' => ['Baladins', 'Louveteaux', 'Louveteaux'],
            'T0003' => ['Louveteaux', 'Éclaireurs', 'Éclaireurs'],
            'T0004' => ['Éclaireurs', 'Pionniers', 'Pionniers'],
            'T0005' => ['Pionniers', 'Route', 'Route'],
        ];

        foreach ($crossings as $tiers => $expected) {
            foreach (UnitBlueprint::YEARS as $index => $label) {
                self::assertSame(
                    $expected[$index],
                    $this->branchOf($tiers, $label),
                    "The branch passage of {$tiers} did not happen in {$label}.",
                );
            }
        }
    }

    public function testASectionChangeInsideABranchIsNotABranchChange(): void
    {
        // Scenario 3 — T0007 has four Louveteaux years, so the move is clean.
        self::assertSame('Meute de Seeonee', $this->sectionOf('T0007', '2024-2025'));
        self::assertSame('Meute de Waingunga', $this->sectionOf('T0007', '2025-2026'));
        self::assertSame('Louveteaux', $this->branchOf('T0007', '2025-2026'));
    }

    public function testTheMemberWhoLeftKeepsTheirMembersRowButHasNoYear(): void
    {
        // Scenario 4. members is identity and survives; member_years is the
        // annual snapshot and simply does not exist for a year they were not
        // in. Losing the members row would break the Tiers continuity every
        // other scenario depends on.
        self::assertNotNull($this->memberIdOf('T0008'), 'the members row for T0008 is gone');

        self::assertNotNull($this->memberYearIdOf('T0008', '2024-2025'));
        self::assertNull($this->memberYearIdOf('T0008', '2025-2026'), 'T0008 has a member_years in A2 although they left');
        self::assertNull($this->memberYearIdOf('T0008', '2026-2027'));
    }

    public function testTheReturningMemberInheritsTheirOffsetAcrossTheMissingYear(): void
    {
        // Scenario 5, and the reason ARCHITECTURE.md §8.1 insists the
        // inheritance is ordered by start_date rather than by row id or scout
        // year id: T0009 has no A2 row to inherit from, so A3 must reach back
        // to A1.
        //
        // This test replays the years itself instead of using setUp()'s
        // finished database, because the inheritance only happens on INSERT —
        // MemberYearRepository::inheritedScoutYearOffset() deliberately never
        // touches the column on UPDATE, so that a chief's correction survives
        // a re-import of the same year. The offset therefore has to be set
        // BETWEEN A1 and A3, exactly as it happens in real life: a chief opens
        // the member page in A1 and marks the member as held back, and the
        // following year's import inherits it.
        $pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $replay = new DeskImportReplay($pdo, $encryption, self::datasetRoot());
        $yearIds = $replay->ensureYears();

        $replay->importYear('2024-2025', $yearIds['2024-2025'], 1);

        $stmt = $pdo->prepare(
            'SELECT my.id FROM member_years my JOIN members m ON m.id = my.member_id
             WHERE m.desk_id = ? AND my.scout_year_id = ?'
        );
        $stmt->execute(['T0009', $yearIds['2024-2025']]);
        $firstYearRow = $stmt->fetch();
        self::assertNotFalse($firstYearRow, 'T0009 devrait exister en A1.');

        (new MemberYearRepository($pdo))->updateScoutYearOffset((int) $firstYearRow['id'], 1);

        $replay->importYear('2025-2026', $yearIds['2025-2026'], 1);
        $replay->importYear('2026-2027', $yearIds['2026-2027'], 1);

        $stmt = $pdo->prepare(
            'SELECT my.scout_year_offset FROM member_years my JOIN members m ON m.id = my.member_id
             WHERE m.desk_id = ? AND my.scout_year_id = ?'
        );
        $stmt->execute(['T0009', $yearIds['2025-2026']]);
        self::assertFalse($stmt->fetch(), 'T0009 must stay absent from A2');

        $stmt->execute(['T0009', $yearIds['2026-2027']]);
        $lastYearRow = $stmt->fetch();
        self::assertNotFalse($lastYearRow, 'T0009 should have come back in A3');
        self::assertSame(
            1,
            (int) $lastYearRow['scout_year_offset'],
            'T0009 did not inherit its A1 offset across the missing year',
        );
    }

    public function testAPioneerBecomesALeaderAndAnimatorsMoveSection(): void
    {
        // Scenarios 8 and 9 — the two career changes the photo rules lean on:
        // both members keep a photo file whose prefix stops describing them.
        self::assertSame('Animé', $this->functionCodeOf('T0013', '2025-2026'));
        self::assertSame('Animateur', $this->functionCodeOf('T0013', '2026-2027'));
        self::assertSame('Ribambelle Bleue', $this->sectionOf('T0013', '2026-2027'));

        self::assertSame('Meute de Seeonee', $this->sectionOf('T0014', '2024-2025'));
        self::assertSame('Meute de Waingunga', $this->sectionOf('T0014', '2025-2026'));
    }

    // -------------------------------------------------------------- structure

    public function testTheEmptiedSectionGoesInactiveAndIsNeverDeleted(): void
    {
        // Scenario 15. MappingResolver::deactivateAllSections() turns every
        // section off at the start of each import and resolveSection() turns
        // back on the ones actually referenced — so a section with no members
        // is left inactive, never dropped.
        $row = $this->sectionRow('Iama Horizon');

        self::assertNotNull($row, 'the emptied section was deleted instead of being deactivated');
        self::assertSame(0, (int) $row['is_active'], 'Iama Horizon should be inactive after the A3 import');
    }

    public function testTheSectionThatOnlyExistsFromA2Exists(): void
    {
        // Scenario 16 — and it must be ACTIVE, since A3 still has members in
        // it: created late is not the same thing as emptied.
        $row = $this->sectionRow('Ribambelle Verte');

        self::assertNotNull($row, 'the section that appeared in A2 was not created');
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(0, $this->countInSection('2024-2025', 'Ribambelle Verte', ['Animé']));
        self::assertGreaterThan(0, $this->countInSection('2025-2026', 'Ribambelle Verte', ['Animé']));
    }

    public function testTheAllCapsSectionColumnWasNeverRead(): void
    {
        // The dataset fills "SECTION" with the code of a DIFFERENT section on
        // purpose. If anything ever started reading it, those codes would show
        // up as section desk_codes here.
        $codes = $this->pdo->query('SELECT desk_code FROM sections')?->fetchAll() ?: [];

        foreach ($codes as $row) {
            self::assertStringStartsNotWith(
                'ZZ001',
                (string) $row['desk_code'],
                'a section was identified from the SECTION column, which must never be read',
            );
        }
    }

    // ------------------------------------------------------------ les rôles

    public function testStaffDUIsEmptyUntilRolesAreConfirmedAndPopulatedAfter(): void
    {
        // The heart of scenario 10, and the one invariant that needs both
        // halves stated. The section itself is created by every import
        // (UnitStaffSectionService::ensureSection(), because
        // deactivateAllSections() would otherwise leave it off), but a Desk
        // import can never know a ROLE: the membership only appears once
        // Correspondances Desk confirms "Chef d'unité" as admin.
        $section = $this->sectionRow(null, UnitStaffSectionService::DESK_CODE);
        self::assertNotNull($section, 'the « Staff d\'U » section was not created');

        self::assertSame(
            0,
            $this->unitStaffBeforeConfirmation,
            'the « Staff d\'U » section is populated although no role was confirmed yet — so the role came from the CSV',
        );

        self::assertGreaterThanOrEqual(
            array_sum(UnitBlueprint::UNIT_STAFF_SIZE),
            $this->unitStaffMembershipCount(),
            'the « Staff d\'U » section is empty although the roles were confirmed',
        );
    }

    private function unitStaffMembershipCount(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT mf.member_year_id) AS n
             FROM member_functions mf
             JOIN functions f ON f.id = mf.function_id
             JOIN sections s ON s.id = mf.section_id
             WHERE s.desk_code = ?'
        );
        $stmt->execute([UnitStaffSectionService::DESK_CODE]);

        return (int) ($stmt->fetch()['n'] ?? 0);
    }

    public function testTheBrandNewFunctionOfA3ArrivesUnconfirmed(): void
    {
        // Scenario 14. Every function imports as identified/unconfirmed; this
        // one is the case a chief has to act on, and it only appears in the
        // last import.
        $stmt = $this->pdo->prepare('SELECT role, confirmed FROM functions WHERE desk_code = ?');
        $stmt->execute([UnitBlueprint::BRAND_NEW_FUNCTION]);
        $row = $stmt->fetch();

        self::assertNotFalse($row, 'the function new in A3 was not created');
        self::assertSame('identified', (string) $row['role']);

        // And it STAYS unconfirmed after Correspondances Desk is replayed, because it
        // is deliberately absent from UnitBlueprint::FUNCTIONS. That is the
        // case a chief has to act on, and the dataset is meant to contain at
        // least one — see UnitBlueprint::BRAND_NEW_FUNCTION.
        self::assertContains(UnitBlueprint::BRAND_NEW_FUNCTION, $this->unconfirmed);
    }

    /**
     * The other half of the same rule: every function a real unit holds IS in
     * the table, so nothing else is left hanging. A second unconfirmed label
     * would mean a function was added to the dataset without being declared.
     */
    public function testTheBrandNewFunctionIsTheOnlyUnconfirmedOne(): void
    {
        self::assertSame([UnitBlueprint::BRAND_NEW_FUNCTION], $this->unconfirmed);
    }

    /**
     * A guard against reintroducing the vocabulary this dataset used to
     * invent. Desk produces none of these three, and a generated function
     * carrying one means somebody copied an old example.
     */
    public function testNoFunctionCarriesTheRetiredFictionalLabels(): void
    {
        $labels = $this->pdo->query('SELECT desk_code FROM functions')?->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        foreach (["Chef d'unité", "Trésorier d'unité", "Accompagnateur d'unité"] as $retired) {
            self::assertNotContains($retired, $labels, "The retired made-up wording « {$retired} » is back.");
        }
    }

    /**
     * Staff d'U is a staff, not one person: the vocabulary now carries three
     * distinct unit-level functions and the generator hands them out in turn,
     * so every year has at least one of each and the roster the public Contact
     * page renders has somebody on it.
     */
    public function testStaffDUHoldsSeveralPeopleEveryYear(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            self::assertGreaterThanOrEqual(
                3,
                $this->countInSection($label, UnitStaffSectionService::DESK_CODE, UnitBlueprint::UNIT_LEVEL_FUNCTIONS),
                "The « Staff d'U » section is too small in {$label} for the roster to show anything.",
            );
        }

        foreach (UnitBlueprint::UNIT_LEVEL_FUNCTIONS as $code) {
            self::assertGreaterThan(
                0,
                $this->countInSection('2026-2027', UnitStaffSectionService::DESK_CODE, [$code]),
                "No « {$code} » in 2026-2027: the rotation of unit functions has stopped turning.",
            );
        }
    }

    /**
     * Exactly one designated responsable per section per year. Nothing in the
     * dataset carried « Animateur responsable » before, so
     * Core\Module\SectionResponsableProvider answered null everywhere and
     * three surfaces that depend on it — the public Sections page, the member
     * page's responsable block, the trombinoscope's highlighted card — were
     * exercised by nothing at all.
     */
    public function testEverySectionHasExactlyOneResponsable(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            foreach (UnitBlueprint::HEADCOUNT[$label] as $handle => [, $cadres]) {
                if ($cadres === 0) {
                    continue;
                }

                $name = UnitBlueprint::SECTIONS[$handle]['name'];
                self::assertSame(
                    1,
                    $this->countInSection($label, $name, ['Animateur responsable']),
                    "{$name} does not have exactly one leader in {$label}.",
                );
            }
        }
    }

    /**
     * Both candidate functions exist, and CandidateDetector recognises them —
     * the word « candidat » comes first in the real Desk labels, which is the
     * spelling this dataset now carries.
     */
    public function testBothCandidateFunctionsExistAndAreDetectedAsSuch(): void
    {
        $labels = $this->pdo->query('SELECT desk_code FROM functions')?->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        self::assertContains('Candidat animateur', $labels);
        self::assertContains('Candidat intendant', $labels);

        $detector = new \Modules\Leadership\Service\CandidateDetector();
        self::assertTrue($detector->isCandidateLabel('Candidat animateur'));
        self::assertTrue($detector->isCandidateLabel('Candidat intendant'));
        self::assertFalse($detector->isCandidateLabel('Animateur responsable'));
    }

    public function testAFunctionLabelledCandidateSurvivesTheImportIntact(): void
    {
        // Scenario 13. Nothing consumes the word today; the future Encadrement
        // module will, and this is the assertion that keeps the label alive
        // until then.
        self::assertSame('Candidat animateur', $this->functionCodeOf('T0018', '2025-2026'));
        self::assertSame('Animateur', $this->functionCodeOf('T0018', '2026-2027'));
    }

    public function testAMemberWithTwoFunctionsKeepsBothAndOnlyOneIsMain(): void
    {
        // Scenario 12 — and the asymmetry the writer relies on: functions are
        // not deduplicated the way addresses are.
        $rows = $this->functionRowsOf('T0017', '2024-2025');

        self::assertCount(2, $rows);
        self::assertSame(1, array_sum(array_map(static fn (array $r): int => (int) $r['is_main_function'], $rows)));
    }

    // ------------------------------------------------------------- les foyers

    public function testASiblingGroupSharesOneAddressBlindIndex(): void
    {
        // Scenario 17, and the whole point of it: Core\Member\AddressNormalizer
        // produces one blind index per household, which is what
        // FeeEstimationService counts. Three children at one address must be
        // one household, not three.
        $indexes = [];
        foreach (['T0020', 'T0021', 'T0022'] as $tiers) {
            $indexes[$tiers] = $this->addressBlindIndexOf($tiers, '2026-2027', 'Domicile');
        }

        self::assertNotNull($indexes['T0020']);
        self::assertSame($indexes['T0020'], $indexes['T0021'], 'two members of the same sibling group have different households');
        self::assertSame($indexes['T0020'], $indexes['T0022'], 'the third child did not join the household');
    }

    public function testTheHouseholdShrinksWhenAChildLeaves(): void
    {
        // Scenario 18.
        $index = $this->addressBlindIndexOf('T0023', '2024-2025', 'Domicile');
        self::assertNotNull($index);

        self::assertSame(3, $this->householdSize($index, '2024-2025'));
        self::assertSame(2, $this->householdSize($index, '2025-2026'));
    }

    public function testAMemberWithTwoAddressesKeepsBothAndOneFunction(): void
    {
        // Scenario 19, and the asymmetry it exists to pin down. Two rows in
        // the CSV, one per address, both carrying the SAME function:
        //
        //   - addresses ARE deduplicated by DeskCsvParser::buildMember()
        //     (first row per "Type d'adresse" wins), so two rows give two
        //     addresses, not four;
        //   - the two function rows the export repeats are collapsed by
        //     DeskImportService, so the same "Animé" is stored once.
        //
        // The second half of that used to be the opposite: both rows were
        // stored, and every reader without a DISTINCT counted this member
        // twice. The repeat is an artefact of the export's one-row-per-
        // (function × address) shape, not a second function, and it is
        // collapsed where the two rows are still known to come from one
        // fact. The DISTINCT counting below is unchanged and still right —
        // it is simply no longer the only thing standing between this
        // member and being counted twice.
        $types = $this->addressTypesOf('T0026', '2024-2025');
        self::assertSame(['Adresse secondaire', 'Domicile'], $types);

        $functions = $this->functionRowsOf('T0026', '2024-2025');
        self::assertCount(1, $functions, 'the two CSV lines describe only one function');
        self::assertSame('Animé', (string) $functions[0]['desk_code']);

        // A section now holds exactly as many member_functions rows as it
        // holds members with that function: the gap the duplicate opened is
        // closed, and the headcount is unchanged either way.
        $distinct = $this->countInSection('2024-2025', 'Troupe du Faucon', ['Animé']);
        $rows = $this->countFunctionRowsInSection('2024-2025', 'Troupe du Faucon', ['Animé']);

        self::assertSame($rows, $distinct, 'a duplicate function reappeared in the dataset');
        self::assertSame(
            UnitBlueprint::HEADCOUNT['2024-2025']['ecl1'][0],
            $distinct,
            'the per-section count must stay a DISTINCT member_year_id',
        );
    }

    /**
     * Issue #201. A unit where 98 % of the addresses hold one person is not a
     * unit — fratries are the norm in a real one, and they are the reason the
     * federation offers a couple and a famille tariff at all. The generator
     * used to produce exactly two multi-member homes in the whole dataset,
     * both of them hand-written scenarios, which left « Justesse des tarifs »
     * with nothing to show and `Core\Member\FeeEstimationService` with nobody
     * to count.
     *
     * Read through the repository the screen itself reads, not through a
     * grouping written here: a dataset whose homes the site would group
     * differently would be quietly testing nothing.
     *
     * The floors are floors, well under what the generator produces today
     * (2024-2025: 22 couples, 23 familles). They are not the numbers to chase
     * — they are the level below which the screen goes back to being empty.
     */
    public function testEveryHouseholdSizeIsRepresentedWithVolume(): void
    {
        $repository = new HouseholdRepository($this->pdo);

        foreach (UnitBlueprint::YEARS as $label) {
            $households = $repository->findHouseholdsForYear($this->yearIds[$label]);
            self::assertNotEmpty($households, "Aucun foyer en {$label}.");

            $alone = 0;
            $couples = 0;
            $families = 0;
            foreach ($households as $household) {
                match (true) {
                    $household->deskSize() >= 3 => $families++,
                    $household->deskSize() === 2 => $couples++,
                    default => $alone++,
                };
            }

            self::assertGreaterThanOrEqual(15, $couples, "Too few two-person households in {$label}.");
            self::assertGreaterThanOrEqual(10, $families, "Too few households of three or more in {$label}.");
            self::assertLessThan(
                0.85 * count($households),
                $alone,
                "Nearly every household in {$label} holds one person only.",
            );
        }
    }

    /**
     * Issue #201, the member's side of the same fact: it is not enough that
     * multi-member homes exist, a real share of the unit has to live in one.
     * `Modules\Registration`'s HouseholdRegistrationCountProvider and the
     * fee suggestion on the inscription form both do nothing until they have
     * a second person at the address.
     */
    public function testAGoodThirdOfTheUnitSharesAHomeWithSomebody(): void
    {
        $repository = new HouseholdRepository($this->pdo);

        foreach (UnitBlueprint::YEARS as $label) {
            $sharing = [];
            foreach ($repository->findHouseholdsForYear($this->yearIds[$label]) as $household) {
                if ($household->deskSize() < 2) {
                    continue;
                }
                foreach ($household->memberYearIds() as $memberYearId) {
                    $sharing[$memberYearId] = true;
                }
            }

            $members = $this->countMembersIn($label);
            self::assertGreaterThanOrEqual(
                (int) round($members / 3),
                count($sharing),
                "Fewer than a third of the unit in {$label} share a home.",
            );
        }
    }

    /**
     * Issue #201 again, and the case the report singled out: a big brother
     * animateur and a little sister baladine at the same address. It is
     * common in a real unit, it was absent here, and it is the shape that
     * produces the interesting arbitration on « Justesse des tarifs » —
     * two members of one home encoded through two different Desk routes.
     */
    public function testAtLeastOneHouseholdMixesAnAnimeAndACadre(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            self::assertGreaterThanOrEqual(
                1,
                $this->householdsMixingAnimesAndCadres($label),
                "No household in {$label} mixes an « animé » and a « cadre ».",
            );
        }
    }

    /**
     * Issue #201, last piece. Since #194 every member's Tarif is DEDUCED from
     * the size of their home, so the export is perfectly coherent and
     * `Modules\Fees\Service\FeeAccuracyService` has, by construction,
     * nothing to report: the « à corriger » tab and its banner would be empty
     * on the demonstration instance forever. That screen exists for the unit
     * that forgot to re-encode a tariff when a sibling arrived, so a few homes
     * forget on purpose (PopulationBuilder::staleTheOldestTariffOfSomeHouseholds).
     *
     * The check is the service's own rule, not the generator's: a member whose
     * encoded category differs from the one their household's size implies.
     * Only the Domicile grouping is counted, which is the one the tariff was
     * derived from.
     */
    public function testSomeHouseholdsCarryATariffNobodyUpdated(): void
    {
        $repository = new HouseholdRepository($this->pdo);

        foreach (UnitBlueprint::YEARS as $label) {
            // What the generator undertook, on the Domicile grouping it
            // derived the tariffs from: exactly this many homes forget.
            $forgetful = 0;
            foreach ($this->homeGroupsOf($label) as $feeCodes) {
                if (self::anyDisagreesWithSize($feeCodes)) {
                    $forgetful++;
                }
            }

            self::assertSame(
                UnitBlueprint::STALE_TARIFF_HOUSEHOLDS_PER_YEAR,
                $forgetful,
                "The count of households with a tariff mismatch in {$label} is no longer the one the blueprint declares.",
            );

            // What the SCREEN will see, which is not the same grouping:
            // FeeAccuracyService reads the households the site derives, and
            // those are grouped on every address rather than on the home one.
            // A dataset that satisfied the line above and left this one at
            // zero would have exercised nothing.
            $encoded = $this->feeCodesByMemberYear($label);
            $reported = 0;
            foreach ($repository->findHouseholdsForYear($this->yearIds[$label]) as $household) {
                $expected = UnitBlueprint::FEE_CODES[$household->deskCategory()->value];
                foreach ($household->memberYearIds() as $memberYearId) {
                    if (($encoded[$memberYearId] ?? $expected) !== $expected) {
                        $reported++;
                        break;
                    }
                }
            }

            self::assertGreaterThanOrEqual(
                1,
                $reported,
                "« Justesse des tarifs » would have nothing to correct in {$label}.",
            );
        }
    }

    /**
     * Whether one home's members do not all carry the tariff their number
     * implies — `FeeAccuracyService`'s rule, over the three Desk codes this
     * dataset uses.
     *
     * @param list<string> $feeCodes
     */
    private static function anyDisagreesWithSize(array $feeCodes): bool
    {
        $expected = UnitBlueprint::FEE_CODES[
            HouseholdFeeCategory::fromHouseholdSize(count($feeCodes))->value
        ];

        foreach ($feeCodes as $feeCode) {
            if ($feeCode !== $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * Issue #201. One parent mailbox over several children is the commonest
     * shape on the public site — `DeskImportService::ensureUserAccount()`
     * keys an account on the email blind index, so a shared mailbox is one
     * account linked to several members. Outside the two hand-written
     * fratries the dataset never exercised it.
     *
     * The floors are set above what those two fratries alone can supply (one
     * mailbox over three members in A1, two over two): an assertion they
     * could satisfy on their own would keep passing on the day the filler
     * stops sharing anything, which is exactly the state this issue is about.
     */
    public function testParentAccountsCoverSeveralMembers(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            self::assertGreaterThanOrEqual(
                15,
                $this->mailboxesCovering($label, 2),
                "Too few shared mailboxes in {$label}: the parent account stays unexercised.",
            );
            self::assertGreaterThanOrEqual(
                5,
                $this->mailboxesCovering($label, 3),
                "Hardly any mailbox in {$label} covers three members or more.",
            );
        }
    }

    // ------------------------------------------------------------ robustesse

    public function testTheMemberWithNoEmailGetsNoUserAccount(): void
    {
        // Scenario 20. DeskImportService::ensureUserAccount() only runs for a
        // member who has an email; a member without one must not conjure a
        // login.
        $memberYearId = $this->memberYearIdOf('T0027', '2024-2025');
        self::assertNotNull($memberYearId);

        $row = $this->pdo->query(
            'SELECT email_blind_index, birth_date_encrypted, phone_encrypted
             FROM member_years WHERE id = ' . $memberYearId
        )?->fetch();

        self::assertNull($row['email_blind_index'] ?? null);
        self::assertNull($row['birth_date_encrypted'] ?? null);
        self::assertNull($row['phone_encrypted'] ?? null);
    }

    public function testTheFeeCategoryFollowsTheMemberYearByYear(): void
    {
        // Scenario 23 — a member's tariff is the size of their household,
        // so a household that changes size changes tariff, and each
        // member_years row points at the right fee_category for that year.
        //
        // The Delvaux household (scenario 17) gains its youngest in A2 and
        // goes from two to three: couple, then familiale.
        self::assertSame(UnitBlueprint::FEE_CODES['couple'], $this->feeCodeOf('T0020', '2024-2025'));
        self::assertSame(UnitBlueprint::FEE_CODES['family'], $this->feeCodeOf('T0020', '2025-2026'));
        self::assertSame(UnitBlueprint::FEE_CODES['family'], $this->feeCodeOf('T0022', '2025-2026'));

        // The Poncelet household (scenario 18) loses one and goes the other
        // way: familiale, then couple.
        self::assertSame(UnitBlueprint::FEE_CODES['family'], $this->feeCodeOf('T0023', '2024-2025'));
        self::assertSame(UnitBlueprint::FEE_CODES['couple'], $this->feeCodeOf('T0023', '2025-2026'));

        // T0033 lives alone throughout — the control. Without it, a
        // derivation that had collapsed into "everybody familiale" would
        // still satisfy one of the two transitions above.
        foreach (['2024-2025', '2025-2026', '2026-2027'] as $year) {
            self::assertSame(UnitBlueprint::FEE_CODES['normal'], $this->feeCodeOf('T0033', $year));
        }
    }

    /**
     * Issue #194. Desk offers three cotisation types and no others, so a
     * generated export may not contain a fourth — it used to give every
     * cadre a « Tarif animateur » no real export has ever carried, which
     * put the unit's whole staff outside the household comparison on the
     * « Justesse des tarifs » screen.
     */
    public function testEveryImportedFeeCategoryIsOneOfDesksThreeTypes(): void
    {
        $labels = $this->pdo->query('SELECT desk_code FROM fee_categories ORDER BY desk_code')
            ?->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        self::assertNotEmpty($labels);
        foreach ($labels as $label) {
            self::assertContains(
                (string) $label,
                array_values(UnitBlueprint::FEE_CODES),
                "The « {$label} » tariff is not one of Desk's three fee types.",
            );
        }
    }

    /**
     * The point of deriving the tariff from the household rather than
     * writing it down: an animateur is billed like anybody else, so the
     * staff must not all land on one tariff — and in particular must not
     * land on a tariff of their own.
     */
    public function testAnimateursCarryTheSameTariffsAsEveryoneElse(): void
    {
        $staffTariffs = $this->pdo->query(
            "SELECT DISTINCT fc.desk_code
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN functions f ON f.id = mf.function_id
             JOIN fee_categories fc ON fc.id = my.fee_category_id
             WHERE f.desk_code = 'Animateur'"
        )?->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        self::assertNotEmpty($staffTariffs);
        foreach ($staffTariffs as $tariff) {
            self::assertContains((string) $tariff, array_values(UnitBlueprint::FEE_CODES));
        }
    }

    public function testEveryPinnedScenarioTiersSurvivedTheImport(): void
    {
        foreach (ScenarioCatalog::pinnedTiers() as $tiers) {
            self::assertNotNull(
                $this->memberIdOf($tiers),
                "Tiers {$tiers}, pinned by scenario " . ScenarioCatalog::scenarioOf($tiers)
                . ", did not survive the import.",
            );
        }
    }

    public function testTheGenderBalanceIsNeverTrivial(): void
    {
        // Scenario 24 — asserted as the invariant that matters rather than as
        // a figure to chase: a 50/50 split gives Prévisions and Statistiques
        // nothing to show.
        //
        // The band is « never within three points of 50 », not five. This
        // guard has failed twice on nothing but a change to the RNG stream,
        // because the draw was aimed at 46 % and 176 of them land anywhere
        // from about 42 % to 50 % — so it was asserting something the
        // generator did not actually aim for.
        //
        // What kept the draw that high was PhotoAssigner: it refused to
        // build a unit with fewer female cadres than the photo lot has
        // female portraits, which happened below about 40 %. Since issue
        // #194, PopulationBuilder::nextCadreGender() guarantees that supply
        // outright instead of hoping for it, so the draw was free to move
        // down to PersonFactory::GENDER_F_PERCENT = 42 and the three years
        // now sit between 41.6 % and 44.4 % — a margin, rather than a
        // coincidence.
        //
        // What the scenario really promises is in its second half — the share
        // MOVES from one year to the next — and that is asserted below rather
        // than described.
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $shares = [];

        foreach (UnitBlueprint::YEARS as $label) {
            $rows = $this->pdo->query(
                'SELECT gender_encrypted FROM member_years
                 WHERE scout_year_id = ' . $this->yearIds[$label] . ' AND is_active = 1'
            )?->fetchAll() ?: [];

            $females = 0;
            foreach ($rows as $row) {
                if ($encryption->decrypt((string) $row['gender_encrypted'], 'member_years.gender') === 'F') {
                    $females++;
                }
            }

            $share = $females / max(count($rows), 1) * 100;
            $shares[$label] = $share;

            self::assertLessThan(47.0, $share, "The girl/boy balance of {$label} is too flat to show anything.");
            self::assertGreaterThan(30.0, $share, "The girl/boy balance of {$label} is no longer credible.");
        }

        self::assertGreaterThan(
            1,
            count(array_unique(array_map(static fn (float $share): int => (int) round($share), $shares))),
            'the share of F is the same across every year: the forecast and statistics charts would be flat',
        );
    }

    // ----------------------------------------------------------------- outils

    private function countMembersIn(string $label): int
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS n FROM member_years WHERE scout_year_id = ' . $this->yearIds[$label] . ' AND is_active = 1'
        )?->fetch();

        return (int) ($row['n'] ?? 0);
    }

    /**
     * @param list<string> $functionCodes
     */
    private function countInSection(string $label, string $sectionName, array $functionCodes): int
    {
        $placeholders = implode(',', array_fill(0, count($functionCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT mf.member_year_id) AS n
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN sections s ON s.id = mf.section_id
             JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND s.desk_code = ?
               AND f.desk_code IN ({$placeholders})"
        );
        $stmt->execute([$this->yearIds[$label], $sectionName, ...$functionCodes]);

        return (int) ($stmt->fetch()['n'] ?? 0);
    }

    /**
     * The same count without the DISTINCT, so a test can show the difference
     * the DISTINCT makes rather than assert it in the abstract.
     *
     * @param list<string> $functionCodes
     */
    private function countFunctionRowsInSection(string $label, string $sectionName, array $functionCodes): int
    {
        $placeholders = implode(',', array_fill(0, count($functionCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS n
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN sections s ON s.id = mf.section_id
             JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND s.desk_code = ?
               AND f.desk_code IN ({$placeholders})"
        );
        $stmt->execute([$this->yearIds[$label], $sectionName, ...$functionCodes]);

        return (int) ($stmt->fetch()['n'] ?? 0);
    }

    private function memberIdOf(string $tiers): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM members WHERE desk_id = ?');
        $stmt->execute([$tiers]);
        $row = $stmt->fetch();

        return $row === false ? null : (int) $row['id'];
    }

    private function memberYearIdOf(string $tiers, string $label): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.id FROM member_years my
             JOIN members m ON m.id = my.member_id
             WHERE m.desk_id = ? AND my.scout_year_id = ?'
        );
        $stmt->execute([$tiers, $this->yearIds[$label]]);
        $row = $stmt->fetch();

        return $row === false ? null : (int) $row['id'];
    }

    private function scoutYearOffsetOf(string $tiers, string $label): ?int
    {
        $id = $this->memberYearIdOf($tiers, $label);
        if ($id === null) {
            return null;
        }
        $row = $this->pdo->query('SELECT scout_year_offset FROM member_years WHERE id = ' . $id)?->fetch();

        return $row === false || $row === null ? null : (int) $row['scout_year_offset'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function functionRowsOf(string $tiers, string $label): array
    {
        $id = $this->memberYearIdOf($tiers, $label);
        if ($id === null) {
            return [];
        }

        $rows = $this->pdo->query(
            'SELECT mf.is_main_function, mf.section_id, mf.age_branch_id, f.desk_code
             FROM member_functions mf
             JOIN functions f ON f.id = mf.function_id
             WHERE mf.member_year_id = ' . $id . ' ORDER BY mf.id'
        )?->fetchAll() ?: [];

        return array_values($rows);
    }

    private function functionCodeOf(string $tiers, string $label): ?string
    {
        $rows = $this->functionRowsOf($tiers, $label);

        return $rows === [] ? null : (string) $rows[0]['desk_code'];
    }

    private function branchOf(string $tiers, string $label): ?string
    {
        $rows = $this->functionRowsOf($tiers, $label);
        if ($rows === [] || $rows[0]['age_branch_id'] === null) {
            return null;
        }

        $row = $this->pdo->query('SELECT label FROM age_branches WHERE id = ' . (int) $rows[0]['age_branch_id'])?->fetch();

        return $row === false || $row === null ? null : (string) $row['label'];
    }

    private function sectionOf(string $tiers, string $label): ?string
    {
        $rows = $this->functionRowsOf($tiers, $label);
        if ($rows === [] || $rows[0]['section_id'] === null) {
            return null;
        }

        $row = $this->pdo->query('SELECT desk_code FROM sections WHERE id = ' . (int) $rows[0]['section_id'])?->fetch();

        return $row === false || $row === null ? null : (string) $row['desk_code'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sectionRow(?string $name, ?string $deskCode = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sections WHERE desk_code = ?');
        $stmt->execute([$deskCode ?? $name]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function addressBlindIndexOf(string $tiers, string $label, string $type): ?string
    {
        $id = $this->memberYearIdOf($tiers, $label);
        if ($id === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT address_normalized_blind_index FROM member_addresses
             WHERE member_year_id = ? AND address_type = ?'
        );
        $stmt->execute([$id, $type]);
        $row = $stmt->fetch();

        return $row === false ? null : (string) $row['address_normalized_blind_index'];
    }

    private function householdSize(string $blindIndex, string $label): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT my.member_id) AS n
             FROM member_addresses ma
             JOIN member_years my ON my.id = ma.member_year_id
             WHERE ma.address_normalized_blind_index = ? AND my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$blindIndex, $this->yearIds[$label]]);

        return (int) ($stmt->fetch()['n'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private function addressTypesOf(string $tiers, string $label): array
    {
        $id = $this->memberYearIdOf($tiers, $label);
        if ($id === null) {
            return [];
        }

        $rows = $this->pdo->query(
            'SELECT address_type FROM member_addresses WHERE member_year_id = ' . $id . ' ORDER BY address_type'
        )?->fetchAll() ?: [];

        return array_map(static fn (array $row): string => (string) $row['address_type'], $rows);
    }

    /**
     * The Domicile groups of one year: every home, as a list of the Desk
     * tariff codes its members carry. Same grouping key as the site's
     * (`address_normalized_blind_index`), restricted to the home address —
     * which is the one PopulationBuilder derived the tariff from.
     *
     * @return list<list<string>>
     */
    private function homeGroupsOf(string $label): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ma.address_normalized_blind_index AS blind_index, fc.desk_code AS fee_code
             FROM member_years my
             JOIN member_addresses ma ON ma.member_year_id = my.id
             JOIN fee_categories fc ON fc.id = my.fee_category_id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND ma.address_type = \'Domicile\'
               AND ma.address_normalized_blind_index IS NOT NULL'
        );
        $stmt->execute([$this->yearIds[$label]]);

        $groups = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $groups[(string) $row['blind_index']][] = (string) $row['fee_code'];
        }

        return array_values($groups);
    }

    /** How many mailboxes of a year are shared by at least $members members. */
    private function mailboxesCovering(string $label, int $members): int
    {
        // Grouped in SQL, counted in PHP. The derived table this replaces
        // needed its threshold in a HAVING, and a placeholder there came
        // back as zero matches — which is how the concatenation got in.
        // Without the wrapper there is nothing left to concatenate.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT my.member_id) AS holders
             FROM member_years my
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND my.email_blind_index IS NOT NULL
             GROUP BY my.email_blind_index'
        );
        $stmt->execute([$this->yearIds[$label]]);

        $shared = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['holders'] >= $members) {
                $shared++;
            }
        }

        return $shared;
    }

    /**
     * The Desk tariff code each member_year of a year carries.
     *
     * @return array<int, string>
     */
    private function feeCodesByMemberYear(string $label): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.id AS member_year_id, fc.desk_code AS fee_code
             FROM member_years my
             JOIN fee_categories fc ON fc.id = my.fee_category_id
             WHERE my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$this->yearIds[$label]]);

        $codes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $codes[(int) $row['member_year_id']] = (string) $row['fee_code'];
        }

        return $codes;
    }

    /** How many homes of a year hold at least one animé AND at least one cadre. */
    private function householdsMixingAnimesAndCadres(string $label): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT ma.address_normalized_blind_index AS blind_index,
                    my.id AS member_year_id,
                    MAX(CASE WHEN f.desk_code = \'Animé\' THEN 1 ELSE 0 END) AS is_anime
             FROM member_years my
             JOIN member_addresses ma ON ma.member_year_id = my.id
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND ma.address_normalized_blind_index IS NOT NULL
             GROUP BY ma.address_normalized_blind_index, my.id'
        );
        $stmt->execute([$this->yearIds[$label]]);

        $homes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $homes[(string) $row['blind_index']][(int) $row['is_anime']] = true;
        }

        $mixed = 0;
        foreach ($homes as $kinds) {
            if (isset($kinds[0], $kinds[1])) {
                $mixed++;
            }
        }

        return $mixed;
    }

    private function feeCodeOf(string $tiers, string $label): ?string
    {
        $id = $this->memberYearIdOf($tiers, $label);
        if ($id === null) {
            return null;
        }

        $row = $this->pdo->query(
            'SELECT fc.desk_code FROM member_years my
             JOIN fee_categories fc ON fc.id = my.fee_category_id
             WHERE my.id = ' . $id
        )?->fetch();

        return $row === false || $row === null ? null : (string) $row['desk_code'];
    }
}
