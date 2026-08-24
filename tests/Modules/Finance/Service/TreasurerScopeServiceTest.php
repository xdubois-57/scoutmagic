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
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The rule itself: "which sections is this session the treasurer of".
 *
 * Two things this suite exists to pin down, because getting either wrong
 * is invisible until it hurts somebody:
 *
 *  1. **null and [] are different answers.** null switches the rule off
 *     and hands every section account back to every intendant; [] denies
 *     them all. A conflation in either direction is a security bug in one
 *     direction and a lock-out in the other, so every case below asserts
 *     which of the two it is, using assertNull/assertSame rather than
 *     anything that would treat them alike.
 *  2. **Both halves are required** — the badge AND animating the section.
 *     Either alone grants nothing.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TreasurerScopeServiceTest extends TestCase
{
    private \PDO $pdo;
    private TreasurerScopeService $service;
    private int $scoutYearId;
    private int $louveteauxId;
    private int $eclaireursId;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new TreasurerScopeService(
            Connection::withPdo($this->pdo),
            new BadgeRepository($this->pdo),
            new MemberBadgeRepository($this->pdo)
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = 1;

        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        $this->louveteauxId = 1;
        $this->eclaireursId = 2;

        // Two functions, so "carries a chief function" and "is an animé of
        // the same section" can be told apart — they share a section_id.
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief'), (2, 'ANIME', 'Animé', 'identified')");

        $this->badgeId = $this->createBadge(BadgeService::BADGE_TREASURER, isActive: true);
    }

    // --- The three ways the rule is OFF ---

    public function testTheRuleIsOffWhenTheBadgeDoesNotExistAtAll(): void
    {
        $this->pdo->exec('DELETE FROM badges');
        $memberId = $this->createStaffMember(section: $this->louveteauxId);

        $this->assertNull($this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    public function testTheRuleIsOffWhenTheBadgeIsDeactivated(): void
    {
        $memberId = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);
        $this->pdo->exec('UPDATE badges SET is_active = 0');

        // Deactivating the badge is the only thing the badges screen lets a
        // unit do to a default badge — it is how "we do not work this way"
        // is said, and it must not leave the assignments half-applied.
        $this->assertNull($this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    public function testTheRuleIsOffWhenNobodyHoldsTheBadgeThisYear(): void
    {
        $memberId = $this->createStaffMember(section: $this->louveteauxId);

        // The state every installation is in the day it updates. Turning
        // the rule on here would lock a whole unit out of its own accounts.
        $this->assertNull($this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    public function testABadgeHeldOnlyInAnotherYearDoesNotSwitchThisYearOn(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (2, '2024-2025', '2024-09-01', '2025-08-31', 0)");
        $memberId = $this->createStaffMember(section: $this->louveteauxId, withBadge: true, scoutYearId: 2);

        // member_badges is keyed per year: a unit that used the badge last
        // year and has not got round to it this year is OFF, not locked out.
        $this->assertNull($this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    // --- The rule is ON: [] is a real, denying answer ---

    public function testAnAnimateurWithoutTheBadgeIsTreasurerOfNothing(): void
    {
        $this->createStaffMember(section: $this->eclaireursId, withBadge: true);
        $plainAnimateur = $this->createStaffMember(section: $this->louveteauxId);

        // Not null: somebody else's badge switched the rule on, and this
        // account is simply not a treasurer.
        $this->assertSame([], $this->service->getTreasurerSectionIds([$plainAnimateur], $this->scoutYearId));
    }

    public function testABadgeHolderWhoAnimatesNothingIsTreasurerOfNothing(): void
    {
        $memberId = $this->createStaffMember(section: null, withBadge: true);

        $this->assertSame([], $this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    public function testASessionLinkedToNoMemberAtAllIsTreasurerOfNothing(): void
    {
        $this->createStaffMember(section: $this->louveteauxId, withBadge: true);

        // Never "everything": an account the Desk import does not reach
        // must fail closed once the rule is on.
        $this->assertSame([], $this->service->getTreasurerSectionIds([], $this->scoutYearId));
    }

    public function testAnAnimeHoldingTheBadgeIsNotTreasurerOfTheirOwnSection(): void
    {
        $this->createStaffMember(section: $this->eclaireursId, withBadge: true);
        $anime = $this->createStaffMember(section: $this->louveteauxId, withBadge: true, functionId: 2);

        // The same trap SectionService::getSectionStaff() guards against:
        // an animé's member_functions row carries the same section_id as
        // that section's staff. Only the function's role tells them apart.
        $this->assertSame([], $this->service->getTreasurerSectionIds([$anime], $this->scoutYearId));
    }

    // --- The rule is ON and grants ---

    public function testTheBadgeAndTheSectionTogetherGrantThatSection(): void
    {
        $memberId = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);

        $this->assertSame([$this->louveteauxId], $this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId));
    }

    public function testOnlyTheSectionsThisPersonAnimates(): void
    {
        $mine = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);
        $this->createStaffMember(section: $this->eclaireursId, withBadge: true);

        $sections = $this->service->getTreasurerSectionIds([$mine], $this->scoutYearId);

        $this->assertNotNull($sections);
        $this->assertSame([$this->louveteauxId], $sections);
    }

    public function testEverySectionAMultiSectionTreasurerAnimates(): void
    {
        $memberId = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);
        $this->addFunction($this->memberYearIdOf($memberId), $this->eclaireursId);

        $sections = $this->service->getTreasurerSectionIds([$memberId], $this->scoutYearId);

        $this->assertNotNull($sections);
        sort($sections);
        $this->assertSame([$this->louveteauxId, $this->eclaireursId], $sections);
    }

    public function testEveryLinkedMemberCounts(): void
    {
        // One login, two members — which is what a secondary address
        // resolves to upstream (MemberService::getLinkedMembers()). The
        // service takes the ids and must not silently use only the first.
        $first = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);
        $second = $this->createStaffMember(section: $this->eclaireursId, withBadge: true);

        $sections = $this->service->getTreasurerSectionIds([$first, $second], $this->scoutYearId);

        $this->assertNotNull($sections);
        sort($sections);
        $this->assertSame([$this->louveteauxId, $this->eclaireursId], $sections);
    }

    public function testAnInactiveMemberYearGrantsNothing(): void
    {
        $active = $this->createStaffMember(section: $this->eclaireursId, withBadge: true);
        $departed = $this->createStaffMember(section: $this->louveteauxId, withBadge: true);
        $this->pdo->prepare('UPDATE member_years SET is_active = 0 WHERE member_id = ?')->execute([$departed]);

        $this->assertSame([], $this->service->getTreasurerSectionIds([$departed], $this->scoutYearId));
        $this->assertSame([$this->eclaireursId], $this->service->getTreasurerSectionIds([$active], $this->scoutYearId));
    }

    // --- fixtures ---

    private function createBadge(string $name, bool $isActive): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, ?)');
        $stmt->execute([$name, $isActive ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A member with one function in $section (null = a function carrying no
     * section, e.g. a Staff d'U role), optionally carrying the badge.
     * Returns the persistent members.id — the key this service works on.
     */
    private function createStaffMember(
        ?int $section,
        bool $withBadge = false,
        int $functionId = 1,
        ?int $scoutYearId = null
    ): int {
        $scoutYearId ??= $this->scoutYearId;

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'x', 'y']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->addFunction($memberYearId, $section, $functionId);

        if ($withBadge) {
            $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
            $stmt->execute([$memberYearId, $this->badgeId]);
        }

        return $memberId;
    }

    private function addFunction(int $memberYearId, ?int $sectionId, int $functionId = 1): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    private function memberYearIdOf(int $memberId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM member_years WHERE member_id = ?');
        $stmt->execute([$memberId]);
        return (int) $stmt->fetchColumn();
    }
}
