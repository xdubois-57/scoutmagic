<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Import\ParsedFunction;
use Core\Import\ParsedImport;
use Core\Import\ParsedMember;
use Core\Import\RosterComparisonRepository;
use Core\Import\RosterReplacementGuard;
use Core\Import\RosterReplacementVerdict;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The barrier against a Desk export filtered on one section
 * (Core\Import\RosterReplacementGuard).
 *
 * The cases here are the four the chantier names: a single-section file
 * against a multi-section roster is refused; an end-of-season file with
 * real departures is not; a file leaving no admin is refused outright;
 * and the first import of a season, which has no roster to be missing
 * from, passes.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RosterReplacementGuardTest extends TestCase
{
    private \PDO $pdo;
    private RosterReplacementGuard $guard;
    private int $scoutYearId;
    private int $animateurFunctionId;
    private int $chiefFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->guard = new RosterReplacementGuard(
            new RosterComparisonRepository($this->pdo),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                new MemberYearRepository($this->pdo)
            )
        );

        $this->animateurFunctionId = $this->createFunction('ANIM', 'identified');
        $this->chiefFunctionId = $this->createFunction('CU', 'admin');
    }

    /* ------------------------------------------------------------------ */
    /* The signal this guard exists for                                    */
    /* ------------------------------------------------------------------ */

    public function testSingleSectionFileAgainstMultiSectionRosterIsRefused(): void
    {
        $this->buildRoster([
            'BAL1' => 20,
            'LOUV1' => 24,
            'ECL1' => 18,
        ]);
        $this->addChiefToRoster('CU-1');

        // The export was filtered on Baladins 1 — 20 of the 63 members.
        // The chef d'unité is in it (Desk exports them with the section
        // they animate), so the invariant holds and the single-section
        // signal is what the verdict rests on.
        $parsed = $this->parsedFile(['BAL1' => 20], ['CU-1']);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::FILTERED_EXPORT, $assessment->verdict);
        $this->assertTrue($assessment->verdict->allowsOverride());
        $this->assertFalse($assessment->isClear());
        $this->assertSame(['BAL1'], $assessment->fileSectionCodes);
        $this->assertSame(3, $assessment->rosterSectionCount);
    }

    public function testSingleSectionFileCountsTheMembersItWouldDeactivate(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 20], ['CU-1']), $this->scoutYearId, 0);

        // The 24 Louveteaux, and nobody else: the 20 Baladins and the
        // chef d'unité are in the file.
        $this->assertSame(24, $assessment->deactivatedCount);
        $this->assertSame(45, $assessment->rosterMemberCount);
        $this->assertSame(21, $assessment->fileMemberCount);
        $this->assertSame(['LOUV1'], $assessment->sectionCodesGoingInactive);
    }

    public function testAOneSectionUnitIsNotRefusedForHavingOneSection(): void
    {
        // A unit genuinely down to one section has one section in its
        // roster too — which is why the signal is "one in the file AND
        // several in the roster", never "one in the file".
        $this->buildRoster(['BAL1' => 20]);
        $this->addChiefToRoster('CU-1');

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 20], ['CU-1']), $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
    }

    /* ------------------------------------------------------------------ */
    /* The traps the chantier names                                        */
    /* ------------------------------------------------------------------ */

    public function testFirstImportOfASeasonIsNotRefused(): void
    {
        // A year nobody has imported yet: nothing is "missing" from an
        // empty roster, and the barrier must stay silent.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 20]), $nextYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
        $this->assertSame(0, $assessment->rosterMemberCount);
        $this->assertSame(0, $assessment->deactivationPercent());
    }

    public function testTheComparisonIsScopedToTheYearBeingImported(): void
    {
        // A fully populated current year must not make next year's first
        // import look like a mass deactivation.
        $this->buildRoster(['BAL1' => 30, 'LOUV1' => 30, 'ECL1' => 30]);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 5]), $nextYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
        $this->assertSame(0, $assessment->deactivatedCount);
    }

    public function testEndOfSeasonDeparturesDoNotTripTheBarrier(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18]);
        $this->addChiefToRoster('CU-1');

        // Every section still present, a normal end-of-season attrition of
        // roughly a fifth of the roster.
        $parsed = $this->parsedFile(['BAL1' => 16, 'LOUV1' => 19, 'ECL1' => 14], ['CU-1']);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
    }

    public function testMembersAlreadyInactiveAreNotCountedAsDeactivated(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');
        // Last season's departures, retired by a previous import.
        $this->addInactiveMembers('OLD', 30);

        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24], ['CU-1']);
        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
        $this->assertSame(0, $assessment->deactivatedCount);
        $this->assertSame(45, $assessment->rosterMemberCount);
    }

    public function testASmallRosterIsNotJudgedOnAProportion(): void
    {
        // Six members losing four is 57 % and entirely ordinary at this
        // size; both sections stay named, so only the proportion could
        // have fired.
        $this->buildRoster(['BAL1' => 3, 'LOUV1' => 3]);
        $this->addChiefToRoster('CU-1');

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 1, 'LOUV1' => 1], ['CU-1']), $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
    }

    /* ------------------------------------------------------------------ */
    /* The mass-deactivation signal                                        */
    /* ------------------------------------------------------------------ */

    public function testAnUnusualDropWithEverySectionPresentAsksForConfirmation(): void
    {
        $this->buildRoster(['BAL1' => 30, 'LOUV1' => 30, 'ECL1' => 30]);
        $this->addChiefToRoster('CU-1');

        // Every section named, but 45 of 91 members gone — a truncated export.
        $parsed = $this->parsedFile(['BAL1' => 15, 'LOUV1' => 15, 'ECL1' => 15], ['CU-1']);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::MASS_DEACTIVATION, $assessment->verdict);
        $this->assertTrue($assessment->verdict->allowsOverride());
        $this->assertSame(49, $assessment->deactivationPercent());
    }

    /* ------------------------------------------------------------------ */
    /* The invariant no confirmation lifts                                 */
    /* ------------------------------------------------------------------ */

    public function testAFileLeavingNoAdminIsRefusedOutright(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18]);
        $this->addChiefToRoster('CU-1');
        $this->addChiefToRoster('CU-2');

        // Same roster, same sections, same people — except the two chefs
        // d'unité, whose function is gone from the file.
        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18]);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::NO_ADMIN_LEFT, $assessment->verdict);
        $this->assertFalse($assessment->verdict->allowsOverride());
        $this->assertSame(0, $assessment->adminCountAfter);
        $this->assertTrue($assessment->unitStaffWipedOut());
        $this->assertSame(2, $assessment->unitStaffLostCount);
    }

    public function testASiteThatHasNoAdministratorYetIsNotRefusedTheImportThatGivesItOne(): void
    {
        // A fresh install: nobody holds an admin function, and the first
        // import is exactly what will create the Staff d'Unité. Refusing
        // it "to protect the last administrator" would refuse the import
        // the site needs to have one at all.
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 20, 'LOUV1' => 24]), $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::ALLOWED, $assessment->verdict);
        $this->assertSame(0, $assessment->unitStaffCount);
    }

    public function testASuperAdminAccountSatisfiesTheInvariant(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');
        $this->createUserAccount('super_idx', true);

        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24]);
        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        // The site keeps an administrative access, so the hard refusal
        // does not apply — the loss of the whole Staff d'Unité is still
        // reported, but as something a human may confirm.
        $this->assertNotSame(RosterReplacementVerdict::NO_ADMIN_LEFT, $assessment->verdict);
        $this->assertTrue($assessment->hasSuperAdminAccount);
        $this->assertTrue($assessment->unitStaffWipedOut());
    }

    /**
     * A deactivated super admin is refused by every login path, so it is
     * not an escape hatch at all. Counting it here would report a way out
     * that nobody can open, and let through exactly the import that
     * leaves the unit with no administrative access whatsoever.
     */
    public function testADeactivatedSuperAdminDoesNotSatisfyTheInvariant(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');
        $accountId = $this->createUserAccount('super_idx', true);
        $this->pdo->exec('UPDATE user_accounts SET is_active = 0 WHERE id = ' . $accountId);

        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24]);
        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertFalse($assessment->hasSuperAdminAccount);
        $this->assertSame(RosterReplacementVerdict::NO_ADMIN_LEFT, $assessment->verdict);
    }

    public function testAFunctionThisInstallationHasNeverSeenCountsAsTheLowestRole(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');

        // The chef d'unité's function code was renamed in Desk. The new
        // code will be created at role 'identified' (SECURITY.md §3), so
        // it must not be read as "probably still an admin".
        $parsed = new ParsedImport(
            [
                ...$this->parsedFile(['BAL1' => 20, 'LOUV1' => 24])->members,
                $this->parsedMember('CU-1', [new ParsedFunction('CU-NOUVEAU-CODE', null, null, null, null, null, null, true)]),
            ],
            45
        );

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, 0);

        $this->assertSame(RosterReplacementVerdict::NO_ADMIN_LEFT, $assessment->verdict);
    }

    /* ------------------------------------------------------------------ */
    /* The consequence that concerns the importer                          */
    /* ------------------------------------------------------------------ */

    public function testTheImporterIsToldWhenTheyWouldLoseTheirOwnAccess(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18]);
        $accountId = $this->createUserAccount('cu_idx', false);
        $this->addChiefToRoster('CU-1', 'cu_idx');
        $this->addChiefToRoster('CU-2');

        // CU-2 stays, so the invariant holds; CU-1 — the importer — goes.
        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18], ['CU-2']);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, $accountId);

        $this->assertTrue($assessment->importerLosesAdmin);
        $this->assertSame(1, $assessment->adminCountAfter);
    }

    public function testASuperAdminImporterIsNeverToldTheyWouldLoseAccess(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18]);
        $accountId = $this->createUserAccount('cu_idx', true);
        $this->addChiefToRoster('CU-1', 'cu_idx');
        $this->addChiefToRoster('CU-2');

        $parsed = $this->parsedFile(['BAL1' => 20, 'LOUV1' => 24, 'ECL1' => 18], ['CU-2']);

        $assessment = $this->guard->assess($parsed, $this->scoutYearId, $accountId);

        // Their access comes from the account flag, which no import touches.
        $this->assertFalse($assessment->importerLosesAdmin);
    }

    /* ------------------------------------------------------------------ */
    /* What the journal is allowed to carry                                */
    /* ------------------------------------------------------------------ */

    public function testTheJournalContextCarriesCountersAndNoIdentity(): void
    {
        $this->buildRoster(['BAL1' => 20, 'LOUV1' => 24]);
        $this->addChiefToRoster('CU-1');

        $assessment = $this->guard->assess($this->parsedFile(['BAL1' => 20], ['CU-1']), $this->scoutYearId, 0);
        $context = $assessment->journalContext();

        foreach ($context as $key => $value) {
            $this->assertIsNotArray($value, "journalContext()[{$key}] must stay a scalar counter");
        }
        $encoded = json_encode($context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('BAL1', $encoded);
        $this->assertStringNotContainsString('CU-1', $encoded);
        $this->assertSame(24, $context['deactivated_count']);
        $this->assertSame(2, $context['roster_section_count']);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function createFunction(string $deskCode, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $deskCode, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createUserAccount(string $blindIndex, bool $superAdmin): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, ?)'
        );
        $stmt->execute(['x', $blindIndex, $superAdmin ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute([$deskCode]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label) VALUES (?, ?)');
        $stmt->execute(['B-' . $deskCode, $deskCode]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, int> $sectionSizes */
    private function buildRoster(array $sectionSizes): void
    {
        foreach ($sectionSizes as $sectionCode => $size) {
            $sectionId = $this->createSection((string) $sectionCode);
            for ($i = 1; $i <= $size; $i++) {
                $memberYearId = $this->addActiveMember(sprintf('%s-%d', $sectionCode, $i));
                $stmt = $this->pdo->prepare(
                    'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
                );
                $stmt->execute([$memberYearId, $this->animateurFunctionId, $sectionId]);
            }
        }
    }

    private function addActiveMember(string $deskId, ?string $emailBlindIndex = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'x', 'y', $emailBlindIndex]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addInactiveMembers(string $prefix, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([sprintf('%s-%d', $prefix, $i)]);
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
                 VALUES (?, ?, ?, ?, 0)'
            );
            $stmt->execute([$memberId, $this->scoutYearId, 'x', 'y']);
        }
    }

    private function addChiefToRoster(string $deskId, ?string $emailBlindIndex = null): void
    {
        $memberYearId = $this->addActiveMember($deskId, $emailBlindIndex);
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->chiefFunctionId]);
    }

    /**
     * A parsed file holding $sectionSizes members per section, plus the
     * Desk identifiers in $chiefDeskIds as chefs d'unité.
     *
     * @param array<string, int> $sectionSizes
     * @param string[] $chiefDeskIds
     */
    private function parsedFile(array $sectionSizes, array $chiefDeskIds = []): ParsedImport
    {
        $members = [];
        foreach ($sectionSizes as $sectionCode => $size) {
            for ($i = 1; $i <= $size; $i++) {
                $members[] = $this->parsedMember(
                    sprintf('%s-%d', $sectionCode, $i),
                    [new ParsedFunction('ANIM', 'B-' . $sectionCode, (string) $sectionCode, (string) $sectionCode, null, null, null, true)]
                );
            }
        }
        foreach ($chiefDeskIds as $deskId) {
            $members[] = $this->parsedMember($deskId, [new ParsedFunction('CU', null, null, null, null, null, null, true)]);
        }

        return new ParsedImport($members, count($members));
    }

    /** @param ParsedFunction[] $functions */
    private function parsedMember(string $deskId, array $functions): ParsedMember
    {
        return new ParsedMember(
            deskId: $deskId,
            lastName: 'Nom',
            firstName: 'Prenom',
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            totem: null,
            quali: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            feeCode: null,
            unitCode: null,
            handicap: null,
            supplementaryInsurance: null,
            addresses: [],
            functions: $functions
        );
    }
}
