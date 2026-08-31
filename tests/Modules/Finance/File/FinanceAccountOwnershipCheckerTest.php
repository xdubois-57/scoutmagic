<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\File;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * A receipt's FILE follows its account's rule.
 *
 * The defect this closes is specific and easy to re-open: `files.role_min`
 * is a hierarchical floor and cannot say "the Louveteaux section", so
 * narrowing the SCREEN to the section's treasurer left `/files/{id}`
 * serving the very receipts the screen had just stopped showing. Every
 * case below therefore goes through the real Core\File\FileAccessGuard
 * rather than calling the checker directly — asserting on the checker
 * alone would prove nothing about the route that actually serves the
 * bytes.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FinanceAccountOwnershipCheckerTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private FinanceAccountOwnershipChecker $checker;
    private int $scoutYearId = 1;
    private int $louveteauxId = 1;
    private int $eclaireursId = 2;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fileRepository = new FileRepository($this->pdo);
        $this->checker = new FinanceAccountOwnershipChecker(
            new AccountRepository($this->pdo, $encryption),
            new TreasurerScopeService(
                Connection::withPdo($this->pdo),
                new BadgeRepository($this->pdo),
                new MemberBadgeRepository($this->pdo)
            ),
            $this->scoutYearId
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");

        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    public function testTheOwnerTypeIsPrefixedWithTheModuleId(): void
    {
        // The first checker whose supports() answers true wins, so an
        // unprefixed string would make access depend on registration order.
        $this->assertSame('finance_account', FinanceAccountOwnershipChecker::OWNER_TYPE);
        $this->assertTrue($this->checker->supports('finance_account'));
        $this->assertFalse($this->checker->supports('section_document'));
    }

    public function testTheSectionsOwnTreasurerIsServedItsReceipt(): void
    {
        $account = $this->createAccount($this->louveteauxId);
        $fileId = $this->createReceiptFile($account);

        $this->assertNotNull($this->guardFor($this->treasurerOf($this->louveteauxId))->check($fileId));
    }

    public function testAnotherSectionsTreasurerIsRefusedTheFileItself(): void
    {
        $account = $this->createAccount($this->eclaireursId);
        $fileId = $this->createReceiptFile($account);

        // THE defect: role_min alone said yes here, because both are
        // 'intendant' and a floor cannot express a section.
        $this->assertNull($this->guardFor($this->treasurerOf($this->louveteauxId))->check($fileId));
    }

    public function testAnIntendantWhoIsNobodysTreasurerIsRefused(): void
    {
        $account = $this->createAccount($this->louveteauxId);
        $fileId = $this->createReceiptFile($account);
        $this->treasurerOf($this->louveteauxId);

        $this->assertNull($this->guardFor($this->plainAnimateur())->check($fileId));
    }

    public function testTheChefDUniteIsServedEverySectionsReceipts(): void
    {
        $account = $this->createAccount($this->eclaireursId);
        $fileId = $this->createReceiptFile($account);
        $treasurer = $this->treasurerOf($this->louveteauxId);

        $this->assertNotNull($this->guardFor($treasurer, Role::ADMIN)->check($fileId));
    }

    public function testAUnitAccountsReceiptIsUnaffected(): void
    {
        $account = $this->createAccount(null);
        $fileId = $this->createReceiptFile($account);
        $this->treasurerOf($this->louveteauxId);

        $this->assertNotNull($this->guardFor($this->plainAnimateur())->check($fileId));
    }

    public function testWithNoBadgeAssignedTheFileIsServedExactlyAsBefore(): void
    {
        $account = $this->createAccount($this->eclaireursId);
        $fileId = $this->createReceiptFile($account);

        // Rule off — the state of every installation on update day.
        $this->assertNotNull($this->guardFor($this->plainAnimateur())->check($fileId));
    }

    public function testRoleMinIsStillCheckedFirstAndIndependently(): void
    {
        $account = $this->createAccount($this->louveteauxId, roleMinView: 'admin');
        $fileId = $this->createReceiptFile($account);

        // Ownership NARROWS; it never lifts the floor. The section's own
        // treasurer is still refused a file whose role_min is above them.
        $this->assertNull($this->guardFor($this->treasurerOf($this->louveteauxId))->check($fileId));
    }

    /**
     * owner_id 0 is the unit's sorting pile — a receipt that arrived by
     * email with no IBAN and from an address animating no single staff,
     * plus the legacy rows the ownership backfill stamped.
     *
     * It used to deny everybody, which was the right fail-safe while
     * nothing could produce such a row on purpose. Now that it is a pile
     * somebody is meant to work through, the rule is narrower than the
     * page's floor rather than absent: treasurers and the chef d'unité,
     * because an unsorted receipt may belong to any section.
     */
    public function testTheSortingPileIsServedToATreasurer(): void
    {
        $fileId = $this->unassignedReceiptFile();

        $this->assertNotNull($this->guardFor($this->treasurerOf($this->louveteauxId))->check($fileId));
    }

    public function testTheSortingPileIsServedToTheChefDUnite(): void
    {
        $fileId = $this->unassignedReceiptFile();
        $treasurer = $this->treasurerOf($this->louveteauxId);

        $this->assertNotNull($this->guardFor($treasurer, Role::ADMIN)->check($fileId));
    }

    public function testAnIntendantWhoIsNobodysTreasurerIsRefusedTheSortingPile(): void
    {
        // The half of the old "denied" that must survive: opening the pile
        // to whoever holds the badge must not open it to every intendant.
        $fileId = $this->unassignedReceiptFile();
        $this->treasurerOf($this->louveteauxId);

        $this->assertNull($this->guardFor($this->plainAnimateur())->check($fileId));
    }

    public function testWithNoBadgeAssignedTheSortingPileFallsBackToTheFloor(): void
    {
        // Rule off — the state of every installation on update day. Reading
        // that as "nobody is a treasurer" would lock a unit out of its own
        // pile until somebody assigned a badge no screen mentions.
        $fileId = $this->unassignedReceiptFile();

        $this->assertNotNull($this->guardFor($this->plainAnimateur())->check($fileId));
    }

    public function testTheSortingPileStillRespectsTheRoleFloor(): void
    {
        // Ownership NARROWS; it never lifts the floor. An identified member
        // below `intendant` is refused whatever badge they carry.
        $fileId = $this->unassignedReceiptFile();

        $this->assertNull($this->guardFor($this->treasurerOf($this->louveteauxId), Role::IDENTIFIED)->check($fileId));
    }

    private function unassignedReceiptFile(): int
    {
        return $this->fileRepository->create(
            'finance/receipts/orphan.enc',
            'r.pdf',
            'application/pdf',
            10,
            'intendant',
            'finance',
            null,
            true,
            null,
            FinanceAccountOwnershipChecker::OWNER_TYPE,
            FinanceAccountOwnershipChecker::UNASSIGNED_OWNER_ID
        );
    }

    public function testAnUnregisteredCheckerDeniesTheFileRatherThanFreeingIt(): void
    {
        $account = $this->createAccount($this->louveteauxId);
        $fileId = $this->createReceiptFile($account);

        // With finance disabled the registry has no checker for this
        // owner_type: fail-closed, never "no rule means no restriction".
        $guard = new FileAccessGuard($this->fileRepository, Role::SUPERADMIN, [], []);

        $this->assertNull($guard->check($fileId));
    }

    // --- fixtures ---

    /** @param int[] $linkedMemberIds */
    private function guardFor(int $memberId, Role $role = Role::INTENDANT): FileAccessGuard
    {
        return new FileAccessGuard($this->fileRepository, $role, [$memberId], [$this->checker]);
    }

    private function createAccount(?int $sectionId, string $roleMinView = 'intendant'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO finance_accounts (name, account_type, section_id, role_min_view, status) VALUES (?, 'bank', ?, ?, 'active')"
        );
        $stmt->execute(['Compte', $sectionId, $roleMinView]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createReceiptFile(int $accountId): int
    {
        $roleMin = (string) $this->pdo->query("SELECT role_min_view FROM finance_accounts WHERE id = {$accountId}")->fetchColumn();

        return $this->fileRepository->create(
            'finance/receipts/' . uniqid() . '.enc',
            'recu.pdf',
            'application/pdf',
            10,
            $roleMin,
            'finance',
            null,
            true,
            null,
            FinanceAccountOwnershipChecker::OWNER_TYPE,
            $accountId
        );
    }

    private function treasurerOf(int $sectionId): int
    {
        return $this->createMember($sectionId, withBadge: true);
    }

    private function plainAnimateur(): int
    {
        return $this->createMember($this->louveteauxId, withBadge: false);
    }

    private function createMember(int $sectionId, bool $withBadge): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, 1, ?, ?, 1)'
        );
        $stmt->execute([$memberId, 'x', 'y']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)');
        $stmt->execute([$memberYearId, $sectionId]);

        if ($withBadge) {
            $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
            $stmt->execute([$memberYearId, $this->badgeId]);
        }

        return $memberId;
    }
}
