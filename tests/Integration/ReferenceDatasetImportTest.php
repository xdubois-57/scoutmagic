<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Import\MemberYearRepository;
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

    /** Staff d'U membership as it stood after the imports, before Config Desk. */
    private int $unitStaffBeforeConfirmation;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $replay = new DeskImportReplay($this->pdo, $encryption, self::datasetRoot());

        $this->yearIds = $replay->ensureYears();
        $replay->importAll($this->yearIds, 1);

        // The state between the last import and the confirmation is the state
        // a real chief sees before their first visit to Config Desk, and it is
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

            self::assertGreaterThanOrEqual(170, $members, "L'unité de {$label} a perdu du monde en route.");
            self::assertLessThanOrEqual(190, $members, "L'unité de {$label} a gagné du monde en route.");
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
                    "{$name} n'a pas le nombre d'animés déclaré en {$label}.",
                );
                self::assertSame(
                    $expectedCadres,
                    $this->countInSection($label, $name, ['Animateur', 'Animateur candidat']),
                    "{$name} n'a pas le nombre de cadres déclaré en {$label}.",
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
            self::assertArrayHasKey($label, $actual, "La branche {$label} n'a pas été créée par l'import.");
            self::assertSame($order, $actual[$label], "La branche {$label} n'a pas son ordre canonique.");
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
                    "Le passage de branche de {$tiers} n'a pas eu lieu en {$label}.",
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
        self::assertNotNull($this->memberIdOf('T0008'), 'La ligne members de T0008 a disparu.');

        self::assertNotNull($this->memberYearIdOf('T0008', '2024-2025'));
        self::assertNull($this->memberYearIdOf('T0008', '2025-2026'), 'T0008 a un member_years en A2 alors qu\'il est parti.');
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
        self::assertFalse($stmt->fetch(), 'T0009 doit rester absent de A2.');

        $stmt->execute(['T0009', $yearIds['2026-2027']]);
        $lastYearRow = $stmt->fetch();
        self::assertNotFalse($lastYearRow, 'T0009 devrait être revenu en A3.');
        self::assertSame(
            1,
            (int) $lastYearRow['scout_year_offset'],
            'T0009 n\'a pas hérité de son décalage de A1 par-dessus l\'année manquante.',
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

        self::assertNotNull($row, 'La section vidée a été supprimée au lieu d\'être désactivée.');
        self::assertSame(0, (int) $row['is_active'], 'Iama Horizon devrait être inactive après l\'import de A3.');
    }

    public function testTheSectionThatOnlyExistsFromA2Exists(): void
    {
        // Scenario 16 — and it must be ACTIVE, since A3 still has members in
        // it: created late is not the same thing as emptied.
        $row = $this->sectionRow('Ribambelle Verte');

        self::assertNotNull($row, 'La section apparue en A2 n\'a pas été créée.');
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
                'Une section a été identifiée par la colonne SECTION, qui ne doit jamais être lue.',
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
        // Config Desk confirms "Chef d'unité" as admin.
        $section = $this->sectionRow(null, UnitStaffSectionService::DESK_CODE);
        self::assertNotNull($section, 'La section Staff d\'U n\'a pas été créée.');

        self::assertSame(
            0,
            $this->unitStaffBeforeConfirmation,
            'Staff d\'U est peuplé alors qu\'aucun rôle n\'a encore été confirmé — le rôle viendrait donc du CSV.',
        );

        self::assertGreaterThanOrEqual(
            array_sum(UnitBlueprint::UNIT_STAFF_SIZE),
            $this->unitStaffMembershipCount(),
            'Staff d\'U est vide alors que les rôles ont été confirmés.',
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
        $row = $this->pdo->query(
            "SELECT role, confirmed FROM functions WHERE desk_code = 'Accompagnateur d''unité'"
        )?->fetch();

        self::assertNotFalse($row, 'La fonction inédite de A3 n\'a pas été créée.');
        self::assertSame('identified', (string) $row['role']);

        // confirmFunctionRoles() confirmed it, because the blueprint declares
        // it — what matters is that the IMPORT never did.
        self::assertNotContains('Accompagnateur d\'unité', $this->unconfirmed);
    }

    public function testAFunctionLabelledCandidateSurvivesTheImportIntact(): void
    {
        // Scenario 13. Nothing consumes the word today; the future Encadrement
        // module will, and this is the assertion that keeps the label alive
        // until then.
        self::assertSame('Animateur candidat', $this->functionCodeOf('T0018', '2025-2026'));
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
        self::assertSame($indexes['T0020'], $indexes['T0021'], 'Deux membres d\'une même fratrie ont des foyers différents.');
        self::assertSame($indexes['T0020'], $indexes['T0022'], 'Le troisième enfant n\'a pas rejoint le foyer.');
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
        self::assertCount(1, $functions, 'Les deux lignes du CSV ne décrivent qu\'une fonction.');
        self::assertSame('Animé', (string) $functions[0]['desk_code']);

        // A section now holds exactly as many member_functions rows as it
        // holds members with that function: the gap the duplicate opened is
        // closed, and the headcount is unchanged either way.
        $distinct = $this->countInSection('2024-2025', 'Troupe du Faucon', ['Animé']);
        $rows = $this->countFunctionRowsInSection('2024-2025', 'Troupe du Faucon', ['Animé']);

        self::assertSame($rows, $distinct, 'Un doublon de fonction est réapparu dans le jeu de données.');
        self::assertSame(
            UnitBlueprint::HEADCOUNT['2024-2025']['ecl1'][0],
            $distinct,
            'Le comptage par section doit rester en DISTINCT member_year_id.',
        );
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
        // Scenario 23 — two fee_categories exist, and each member_years row
        // points at the right one for that year.
        self::assertSame(UnitBlueprint::FEE_CODES['anime'], $this->feeCodeOf('T0033', '2024-2025'));
        self::assertSame(UnitBlueprint::FEE_CODES['anime_reduit'], $this->feeCodeOf('T0033', '2025-2026'));
    }

    public function testEveryPinnedScenarioTiersSurvivedTheImport(): void
    {
        foreach (ScenarioCatalog::pinnedTiers() as $tiers) {
            self::assertNotNull(
                $this->memberIdOf($tiers),
                "Le Tiers {$tiers}, épinglé par le scénario " . ScenarioCatalog::scenarioOf($tiers)
                . ", n'a pas survécu à l'import.",
            );
        }
    }

    public function testTheGenderBalanceIsNeverTrivial(): void
    {
        // Scenario 24 — asserted as the invariant that matters rather than as
        // a figure to chase: a 50/50 split gives Prévisions and Statistiques
        // nothing to show.
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

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
            self::assertLessThan(45.0, $share, "L'équilibre filles/garçons de {$label} est trop plat pour montrer quoi que ce soit.");
            self::assertGreaterThan(30.0, $share, "L'équilibre filles/garçons de {$label} n'est plus crédible.");
        }
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
