<?php

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeException;
use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Module\BadgeUsageProvider;
use Core\Module\HookRegistry;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BadgeServiceTest extends TestCase
{
    private \PDO $pdo;
    private BadgeService $service;
    private BadgeRepository $badgeRepository;
    private MemberBadgeRepository $memberBadgeRepository;
    private SectionService $sectionService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->badgeRepository = new BadgeRepository($this->pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService(
            Connection::withPdo($this->pdo), new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->memberBadgeRepository
        );
        $this->service = new BadgeService($this->badgeRepository, $this->memberBadgeRepository, $this->sectionService);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createMemberYear(string $deskId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc']);
        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, ?string $name, bool $visible = true): int
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR_{$deskCode}', 'Branch', 10)");
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name, $visible ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function linkMemberToSection(int $memberYearId, int $sectionId): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('FN_" . uniqid() . "', 'Fonction', 'chief')");
        $functionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    /**
     * A function on a section that does NOT animate it — an `intendant`,
     * the role that exists to reach the finances without being a chief.
     */
    private function linkMemberToSectionAsIntendant(int $memberYearId, int $sectionId): void
    {
        $this->pdo->exec(
            "INSERT INTO functions (desk_code, label, role) VALUES ('FN_" . uniqid() . "', 'Intendant', 'intendant')"
        );
        $functionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    /** Turns the animating function of a member into a non-animating one. */
    private function demoteToIntendant(int $memberYearId): void
    {
        $this->pdo->exec(
            "UPDATE functions SET role = 'intendant' WHERE id IN ("
            . "SELECT function_id FROM member_functions WHERE member_year_id = {$memberYearId})"
        );
    }

    private function treasurerBadgeId(): int
    {
        $this->service->ensureDefaults();
        $badge = $this->badgeRepository->findByName(BadgeService::BADGE_TREASURER);
        $this->assertNotNull($badge);

        return $badge->id;
    }

    public function testEnsureDefaultsCreatesInfirmierAndTresorier(): void
    {
        $this->service->ensureDefaults();

        $names = array_map(fn($b) => $b->name, $this->service->getAll());
        $this->assertContains('Infirmier', $names);
        $this->assertContains('Trésorier', $names);
    }

    public function testEnsureDefaultsIsIdempotent(): void
    {
        $this->service->ensureDefaults();
        $this->service->ensureDefaults();

        $this->assertCount(2, $this->service->getAll());
    }

    public function testEnsureDefaultsMarksThemAsDefault(): void
    {
        $this->service->ensureDefaults();

        foreach ($this->service->getAll() as $badge) {
            $this->assertTrue($badge->isDefault);
        }
    }

    public function testGetActiveExcludesDeactivatedBadges(): void
    {
        $badge = $this->service->create('Communication');
        $this->service->setActive($badge->id, false);
        $this->service->create('Boussole');

        $names = array_map(fn($b) => $b->name, $this->service->getActive());
        $this->assertContains('Boussole', $names);
        $this->assertNotContains('Communication', $names);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(BadgeException::class);
        $this->service->create('  ');
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $this->service->create('Communication');

        $this->expectException(BadgeException::class);
        $this->service->create('Communication');
    }

    public function testCreateNewBadgeIsNotDefault(): void
    {
        $badge = $this->service->create('Communication');

        $this->assertFalse($badge->isDefault);
    }

    public function testUpdateRejectsUnknownBadge(): void
    {
        $this->expectException(BadgeException::class);
        $this->service->update(9999, 'Name');
    }

    public function testUpdateRenamesBadge(): void
    {
        $badge = $this->service->create('Communication');

        $updated = $this->service->update($badge->id, 'Comm Interne');

        $this->assertSame('Comm Interne', $updated->name);
    }

    public function testUpdateAllowsKeepingTheSameName(): void
    {
        $badge = $this->service->create('Communication');

        $updated = $this->service->update($badge->id, 'Communication');

        $this->assertSame('Communication', $updated->name);
    }

    public function testUpdateRejectsCollidingWithAnotherBadgesName(): void
    {
        $this->service->create('Communication');
        $other = $this->service->create('Boussole');

        $this->expectException(BadgeException::class);
        $this->service->update($other->id, 'Communication');
    }

    public function testUpdateRejectsRenamingDefaultBadge(): void
    {
        $this->service->ensureDefaults();
        $infirmier = array_values(array_filter($this->service->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $this->expectException(BadgeException::class);
        $this->service->update($infirmier->id, 'Nurse');
    }

    public function testSetActiveRejectsUnknownBadge(): void
    {
        $this->expectException(BadgeException::class);
        $this->service->setActive(9999, false);
    }

    public function testDeleteRejectsDefaultBadge(): void
    {
        $this->service->ensureDefaults();
        $infirmier = array_values(array_filter($this->service->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $this->expectException(BadgeException::class);
        $this->service->delete($infirmier->id);
    }

    public function testDeleteRejectsAssignedBadge(): void
    {
        $badge = $this->service->create('Communication');
        $memberYearId = $this->createMemberYear('D1');
        $this->memberBadgeRepository->assign($memberYearId, $badge->id, null);

        $this->expectException(BadgeException::class);
        $this->service->delete($badge->id);
    }

    public function testDeleteSucceedsForUnusedNonDefaultBadge(): void
    {
        $badge = $this->service->create('Communication');

        $this->service->delete($badge->id);

        $this->assertEmpty($this->service->getAll());
    }

    /**
     * A module pointing at a badge by id holds a foreign key with its own
     * ON DELETE CASCADE, and a cascade is silent — see
     * Core\Module\BadgeUsageProvider for the mailing list that motivated
     * the guard.
     */
    public function testDeleteIsRefusedWhileAModuleStillPointsAtTheBadge(): void
    {
        $hooks = new HookRegistry();
        $service = new BadgeService(
            $this->badgeRepository,
            $this->memberBadgeRepository,
            $this->sectionService,
            $hooks
        );
        $inUse = $service->create('Communication');
        $free = $service->create('Boussole');

        $hooks->register(BadgeUsageProvider::class, new class ([$inUse->id]) implements BadgeUsageProvider {
            /** @param int[] $ids */
            public function __construct(private array $ids)
            {
            }

            /** @return int[] */
            public function badgeIdsInUse(): array
            {
                return $this->ids;
            }

            public function describeBadgeUsage(): string
            {
                return 'utilisé comme critère par une liste de diffusion';
            }
        });

        $this->assertSame(
            ['Ce badge est utilisé comme critère par une liste de diffusion — désactivez-le au lieu de le '
                . 'supprimer.'],
            array_values($service->getUndeletableBadgeReasons()),
            'The page disables the button with the same sentence the server would throw.'
        );

        // The one nothing points at is still deletable.
        $service->delete($free->id);

        $this->expectException(BadgeException::class);
        $service->delete($inUse->id);
    }

    /**
     * Without a registry — an installation with no module pointing at
     * badges — nothing changes: the guard simply does not apply.
     */
    public function testDeleteIsUnguardedWhenNoModuleProvidesBadgeUsage(): void
    {
        $badge = $this->service->create('Communication');

        $this->assertSame([], $this->service->getUndeletableBadgeReasons());

        $this->service->delete($badge->id);

        $this->assertEmpty($this->service->getAll());
    }

    public function testAnAssignedBadgeKeepsTheAssignmentReason(): void
    {
        $badge = $this->service->create('Communication');
        $memberYearId = $this->createMemberYear('D1');
        $this->memberBadgeRepository->assign($memberYearId, $badge->id, null);

        $this->assertSame(
            ['Ce badge est déjà attribué à un membre — désactivez-le au lieu de le supprimer.'],
            array_values($this->service->getUndeletableBadgeReasons())
        );
    }

    public function testGetAssignedBadgeIdsReturnsOnlyAssignedBadges(): void
    {
        $assignedBadge = $this->service->create('Communication');
        $unassignedBadge = $this->service->create('Boussole');
        $memberYearId = $this->createMemberYear('D1');
        $this->memberBadgeRepository->assign($memberYearId, $assignedBadge->id, null);

        $ids = $this->service->getAssignedBadgeIds();

        $this->assertSame([$assignedBadge->id], $ids);
        $this->assertNotContains($unassignedBadge->id, $ids);
    }

    public function testToggleAssignmentAssignsThenUnassigns(): void
    {
        $badge = $this->service->create('Communication');
        $memberYearId = $this->createMemberYear('D1');

        $firstToggle = $this->service->toggleAssignment($memberYearId, $badge->id, null);
        $this->assertTrue($firstToggle);
        $this->assertCount(1, $this->service->getBadgesForMemberYear($memberYearId));

        $secondToggle = $this->service->toggleAssignment($memberYearId, $badge->id, null);
        $this->assertFalse($secondToggle);
        $this->assertEmpty($this->service->getBadgesForMemberYear($memberYearId));
    }

    public function testToggleAssignmentRejectsInactiveBadge(): void
    {
        $badge = $this->service->create('Communication');
        $this->service->setActive($badge->id, false);
        $memberYearId = $this->createMemberYear('D1');

        $this->expectException(BadgeException::class);
        $this->service->toggleAssignment($memberYearId, $badge->id, null);
    }

    // ── the Trésorier badge and what it actually grants (issue #222) ──

    /**
     * The badge grants exactly one thing: the finance account of a section
     * its holder ANIMATES. Given to an intendant it granted nothing while
     * the page said « attribué » — and, worse, switched the whole unit's
     * account partition on, so the intendant who saw every section's
     * account before now saw none.
     */
    public function testTheTreasurerBadgeIsRefusedToSomebodyWhoAnimatesNothing(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSectionAsIntendant($memberYearId, $this->createSection('POSTE', 'Poste'));

        $this->expectException(BadgeException::class);
        $this->expectExceptionMessage('anime une section');
        $this->service->toggleAssignment($memberYearId, $this->treasurerBadgeId(), null);
    }

    public function testTheTreasurerBadgeIsRefusedToSomebodyWithNoFunctionAtAll(): void
    {
        $memberYearId = $this->createMemberYear('D1');

        $this->expectException(BadgeException::class);
        $this->service->toggleAssignment($memberYearId, $this->treasurerBadgeId(), null);
    }

    public function testTheTreasurerBadgeIsGivenToAnAnimator(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $this->createSection('MEUTE', 'Meute'));

        $this->assertTrue($this->service->toggleAssignment($memberYearId, $this->treasurerBadgeId(), null));
    }

    /**
     * Removing is always allowed, which is what makes an installation that
     * already carries a useless assignment repairable — and what stops the
     * refusal from trapping somebody whose function changed under them.
     */
    public function testABadgeSomebodyShouldNeverHaveHadCanStillBeRemoved(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $this->createSection('MEUTE', 'Meute'));
        $badgeId = $this->treasurerBadgeId();
        $this->service->toggleAssignment($memberYearId, $badgeId, null);

        $this->demoteToIntendant($memberYearId);

        $this->assertFalse($this->service->toggleAssignment($memberYearId, $badgeId, null));
        $this->assertEmpty($this->service->getBadgesForMemberYear($memberYearId));
    }

    /** Another badge is not the treasurer's: nothing about it is gated. */
    public function testAnotherBadgeIsUnaffectedByTheAnimationRule(): void
    {
        $badge = $this->service->create('Communication');
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSectionAsIntendant($memberYearId, $this->createSection('POSTE', 'Poste'));

        $this->assertTrue($this->service->toggleAssignment($memberYearId, $badge->id, null));
    }

    // ── the other door: an import that strands a badge ────────────────

    public function testAHolderWhoStoppedAnimatingIsReportedAsStranded(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $this->createSection('MEUTE', 'Meute'));
        $this->service->toggleAssignment($memberYearId, $this->treasurerBadgeId(), null);

        $this->assertSame([], $this->service->findStrandedTreasurerMemberYearIds($this->scoutYearId));

        // What a Desk import does when the export says « Intendant ».
        $this->demoteToIntendant($memberYearId);

        $this->assertSame(
            [$memberYearId],
            $this->service->findStrandedTreasurerMemberYearIds($this->scoutYearId)
        );
    }

    public function testADeactivatedTreasurerBadgeStrandsNobody(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $this->createSection('MEUTE', 'Meute'));
        $badgeId = $this->treasurerBadgeId();
        $this->service->toggleAssignment($memberYearId, $badgeId, null);
        $this->demoteToIntendant($memberYearId);

        // Deactivating the badge is how a unit says « we do not work this
        // way »; the rule is off, so nothing is stranded.
        $this->service->setActive($badgeId, false);

        $this->assertSame([], $this->service->findStrandedTreasurerMemberYearIds($this->scoutYearId));
    }

    public function testGetBadgesForMemberYearsBatchesAcrossMembers(): void
    {
        $badge = $this->service->create('Communication');
        $memberYearId1 = $this->createMemberYear('D1');
        $memberYearId2 = $this->createMemberYear('D2');
        $this->service->toggleAssignment($memberYearId1, $badge->id, null);

        $result = $this->service->getBadgesForMemberYears([$memberYearId1, $memberYearId2]);

        $this->assertArrayHasKey($memberYearId1, $result);
        $this->assertArrayNotHasKey($memberYearId2, $result);
    }

    public function testSyncCreatesAReferentBadgeForEachVisibleSection(): void
    {
        $this->createSection('LOU01', 'Louveteaux');

        $this->service->syncSectionReferentBadges();

        $names = array_map(fn($b) => $b->name, $this->service->getAll());
        $this->assertContains('Référent Louveteaux', $names);
    }

    public function testSyncFallsBackToDeskCodeWhenSectionHasNoName(): void
    {
        $this->createSection('LOU01', null);

        $this->service->syncSectionReferentBadges();

        $names = array_map(fn($b) => $b->name, $this->service->getAll());
        $this->assertContains('Référent LOU01', $names);
    }

    public function testSyncSkipsTheStaffDuSectionItself(): void
    {
        $this->createSection(UnitStaffSectionService::DESK_CODE, "Staff d'U");

        $this->service->syncSectionReferentBadges();

        $names = array_map(fn($b) => $b->name, $this->service->getAll());
        $this->assertNotContains("Référent Staff d'U", $names);
    }

    public function testSyncNeverCreatesABadgeForAnInvisibleSection(): void
    {
        $this->createSection('LOU01', 'Louveteaux', visible: false);

        $this->service->syncSectionReferentBadges();

        $this->assertEmpty($this->service->getAll());
    }

    public function testSyncIsIdempotent(): void
    {
        $this->createSection('LOU01', 'Louveteaux');

        $this->service->syncSectionReferentBadges();
        $this->service->syncSectionReferentBadges();

        $this->assertCount(1, $this->service->getAll());
    }

    public function testSyncRenamesTheBadgeWhenTheSectionIsRenamed(): void
    {
        $sectionId = $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();

        $this->sectionService->updateSectionInfo($sectionId, 'Les Louveteaux', null);
        $this->service->syncSectionReferentBadges();

        $names = array_map(fn($b) => $b->name, $this->service->getAll());
        $this->assertContains('Référent Les Louveteaux', $names);
        $this->assertNotContains('Référent Louveteaux', $names);
        $this->assertCount(1, $this->service->getAll());
    }

    public function testReferentBadgeIsActiveByDefaultOnCreation(): void
    {
        $this->createSection('LOU01', 'Louveteaux');

        $this->service->syncSectionReferentBadges();

        $badge = array_values($this->service->getAll())[0];
        $this->assertTrue($badge->isActive);
    }

    /**
     * Module spec correction: a referent badge is "activatable/
     * deactivatable like any other badge" — sync must never override a
     * manual toggle, even when the section's own visibility later changes.
     */
    public function testSyncDoesNotOverrideAManualDeactivationWhenTheSectionStaysVisible(): void
    {
        $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();
        $badge = array_values($this->service->getAll())[0];
        $this->service->setActive($badge->id, false);

        $this->service->syncSectionReferentBadges();

        $refreshed = array_values($this->service->getAll())[0];
        $this->assertFalse($refreshed->isActive);
    }

    public function testSyncDoesNotDeactivateAnExistingBadgeWhenItsSectionBecomesInvisible(): void
    {
        $sectionId = $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();

        $this->sectionService->updateSectionVisibility($sectionId, false);
        $this->service->syncSectionReferentBadges();

        $badge = array_values($this->service->getAll())[0];
        $this->assertTrue($badge->isActive);
    }

    public function testSyncDoesNotReactivateAManuallyDeactivatedBadgeWhenItsSectionBecomesVisibleAgain(): void
    {
        $sectionId = $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();
        $badge = array_values($this->service->getAll())[0];
        $this->service->setActive($badge->id, false);
        $this->sectionService->updateSectionVisibility($sectionId, false);
        $this->service->syncSectionReferentBadges();

        $this->sectionService->updateSectionVisibility($sectionId, true);
        $this->service->syncSectionReferentBadges();

        $refreshed = array_values($this->service->getAll())[0];
        $this->assertFalse($refreshed->isActive);
    }

    public function testReferentBadgeIsMarkedAsDefault(): void
    {
        $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();

        $badge = array_values($this->service->getAll())[0];
        $this->assertTrue($badge->isDefault);
        $this->assertNotNull($badge->referentSectionId);
    }

    public function testUpdateRejectsRenamingAReferentBadgeDirectly(): void
    {
        $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();
        $badge = array_values($this->service->getAll())[0];

        $this->expectException(BadgeException::class);
        $this->service->update($badge->id, 'Autre nom');
    }

    public function testToggleAssignmentRejectsAReferentBadgeForANonStaffDuMember(): void
    {
        $sectionId = $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();
        $badge = array_values($this->service->getAll())[0];

        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $sectionId);

        $this->expectException(BadgeException::class);
        $this->service->toggleAssignment($memberYearId, $badge->id, null);
    }

    public function testToggleAssignmentAllowsAReferentBadgeForAStaffDuMember(): void
    {
        $this->createSection('LOU01', 'Louveteaux');
        $this->service->syncSectionReferentBadges();
        $badge = array_values($this->service->getAll())[0];

        $staffduSectionId = $this->createSection(UnitStaffSectionService::DESK_CODE, "Staff d'U");
        $memberYearId = $this->createMemberYear('D1');
        $this->linkMemberToSection($memberYearId, $staffduSectionId);

        $result = $this->service->toggleAssignment($memberYearId, $badge->id, null);

        $this->assertTrue($result);
    }
}
