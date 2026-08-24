<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\TreasurerScope;
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * "May this session use this account at all" — the predicate every
 * finance page and every finance write route now shares.
 *
 * The interesting cases are all conjunctions and exemptions: an account
 * with no section, an admin, the rule switched off. Each of them is a way
 * for the narrowing NOT to apply, and each one is a way to get it wrong in
 * the direction nobody notices — the account simply stays visible.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AccountVisibilityTest extends TestCase
{
    private \PDO $pdo;
    private TreasurerScopeService $rule;
    private int $scoutYearId = 1;
    private int $louveteauxId = 1;
    private int $eclaireursId = 2;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->rule = new TreasurerScopeService(
            Connection::withPdo($this->pdo),
            new BadgeRepository($this->pdo),
            new MemberBadgeRepository($this->pdo)
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");

        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    public function testAUnitAccountIsUnaffectedByTheRule(): void
    {
        $treasurerOfLouveteaux = $this->treasurerOf($this->louveteauxId);
        $notATreasurer = $this->plainAnimateur();

        // section_id NULL is the unit's own money: no section, no section
        // rule, role_min_view remains its whole answer.
        $unitAccount = $this->account(sectionId: null, roleMinView: 'intendant');

        $this->assertTrue($this->visibilityFor($treasurerOfLouveteaux)->isVisibleTo($unitAccount, Role::INTENDANT));
        $this->assertTrue($this->visibilityFor($notATreasurer)->isVisibleTo($unitAccount, Role::INTENDANT));
    }

    public function testASectionAccountIsVisibleToItsOwnTreasurer(): void
    {
        $member = $this->treasurerOf($this->louveteauxId);
        $account = $this->account(sectionId: $this->louveteauxId, roleMinView: 'intendant');

        $this->assertTrue($this->visibilityFor($member)->isVisibleTo($account, Role::INTENDANT));
    }

    public function testAnotherSectionsAccountIsNotVisibleToThatTreasurer(): void
    {
        $member = $this->treasurerOf($this->louveteauxId);
        $account = $this->account(sectionId: $this->eclaireursId, roleMinView: 'intendant');

        // The defect this iteration exists to fix: role_min_view alone said
        // yes to every intendant for every section.
        $this->assertFalse($this->visibilityFor($member)->isVisibleTo($account, Role::INTENDANT));
    }

    public function testAnIntendantWhoIsNobodysTreasurerLosesEverySectionAccount(): void
    {
        $this->treasurerOf($this->louveteauxId);
        $account = $this->account(sectionId: $this->louveteauxId, roleMinView: 'intendant');

        $this->assertFalse($this->visibilityFor($this->plainAnimateur())->isVisibleTo($account, Role::INTENDANT));
    }

    public function testTheChefDUniteKeepsEveryAccountUnconditionally(): void
    {
        $account = $this->account(sectionId: $this->eclaireursId, roleMinView: 'intendant');
        $visibility = $this->visibilityFor($this->treasurerOf($this->louveteauxId));

        // They answer for the unit's finances as a whole, and the badge is
        // about who does the day-to-day work, not about who is accountable.
        $this->assertTrue($visibility->isVisibleTo($account, Role::ADMIN));
        $this->assertTrue($visibility->isVisibleTo($account, Role::SUPERADMIN));
    }

    public function testRoleMinViewStillRefusesEvenTheSectionsOwnTreasurer(): void
    {
        $member = $this->treasurerOf($this->louveteauxId);
        $account = $this->account(sectionId: $this->louveteauxId, roleMinView: 'admin');

        // Both conditions, not either: the section rule NARROWS the old
        // floor, it never lifts it.
        $this->assertFalse($this->visibilityFor($member)->isVisibleTo($account, Role::INTENDANT));
    }

    public function testWithTheRuleOffEveryIntendantKeepsEverySectionAccount(): void
    {
        // Nobody carries the badge: the state every installation is in the
        // day it updates, and it must change nothing at all.
        $member = $this->plainAnimateur();
        $account = $this->account(sectionId: $this->eclaireursId, roleMinView: 'intendant');

        $this->assertTrue($this->visibilityFor($member)->isVisibleTo($account, Role::INTENDANT));
    }

    public function testAnUnknownAccountIsNeverVisible(): void
    {
        // Routes resolve an account from client input and pass whatever
        // came back; "no such account" and "not yours" answer the same, so
        // the client is not told which accounts exist.
        $this->assertFalse($this->visibilityFor($this->plainAnimateur())->isVisibleTo(null, Role::SUPERADMIN));
    }

    public function testStatusIsNotThisPredicatesBusiness(): void
    {
        $member = $this->treasurerOf($this->louveteauxId);
        $inactive = $this->account(sectionId: $this->louveteauxId, roleMinView: 'intendant', status: Account::STATUS_INACTIVE);

        // A receivable or a movement booked against an account the unit
        // has since deactivated must stay reconcilable —
        // FinanceService::getAccountsForUser() excludes it from the
        // picker, this predicate deliberately does not.
        $this->assertTrue($this->visibilityFor($member)->isVisibleTo($inactive, Role::INTENDANT));
    }

    // --- fixtures ---

    /** @param int[] $linkedMemberIds */
    private function visibilityFor(int ...$linkedMemberIds): AccountVisibility
    {
        return new AccountVisibility(
            TreasurerScope::forSession($this->rule, $linkedMemberIds, $this->scoutYearId)
        );
    }

    private function account(?int $sectionId, string $roleMinView, string $status = Account::STATUS_ACTIVE): Account
    {
        return new Account(
            id: 1,
            name: 'Compte',
            accountType: Account::TYPE_BANK,
            sectionId: $sectionId,
            iban: null,
            holderName: null,
            roleMinView: $roleMinView,
            status: $status,
            isDefault: false
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
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'x', 'y']);
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
