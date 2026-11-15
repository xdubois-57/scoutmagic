<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Database\Connection;

/**
 * "Which sections is this session the treasurer of, for the effective
 * scout year" — the rule behind `finance_accounts.section_id`, which
 * existed as a column long before anything read it for an access
 * decision. Until it did, every intendant saw every section's account.
 *
 * A treasurer of a section is not a new entity and needs no new table: it
 * is a person carrying the `Trésorier` badge (Core\Badge, §8.11) AND
 * animating that section (`member_functions` of role chief/admin, the
 * same resolution Core\Member\SectionStaffAuthorizationService uses,
 * §8.33). Two facts the unit already maintains, combined.
 *
 * The rule belongs to the finance module and not to core: core has no
 * opinion about what a treasurer may see, and no other module needs one.
 *
 * **Keyed on members.id, deliberately, and never on an e-mail address.**
 * Core\File\FileOwnershipCheckerInterface — which the receipts guard
 * implements — is handed `$linkedMemberIds` and nothing else. Designing
 * this on the address would force the rule to be written a second time
 * for the file guard, and two copies of an authorization rule always
 * drift apart. The caller resolves the ids the way the whole site does
 * (Core\Member\MemberService::getLinkedMembers(), blind index over the
 * Desk address plus every currently-'valid' secondary address).
 *
 * Like §8.33's service it answers only "which sections", never "is this
 * allowed": FinanceService owns what to do with the answer, including the
 * rules this service says nothing about (an account with no section, and
 * an admin/superadmin who gets everything unconditionally).
 */
class TreasurerScopeService
{
    public function __construct(
        private Connection $connection,
        private BadgeRepository $badgeRepository
    ) {
    }

    /**
     * Sections this session is the treasurer of, for this scout year.
     *
     * **Never "switched off" — no badge, no access (issue #1107).** This
     * used to return null, read as « rule disabled », whenever nobody
     * carried the badge for the year or the badge was deactivated. That
     * catch was meant for the unit that had not adopted the badge yet,
     * but it also meant any chief could reopen every section's account to
     * everyone by taking the badge off the treasurers — and that the rule
     * lapsed by itself every September until the new year's badges were
     * handed out. A missing, deactivated or unassigned badge now simply
     * means nobody is a treasurer: an empty list.
     *
     * What keeps a unit from being locked out of its own money is not
     * this method: `admin`/`superadmin` (the chefs d'unité) reach every
     * account regardless (Service\AccountVisibility), accounts without a
     * section stay on `role_min_view` alone, and
     * Service\TreasurerCoverageAttentionProvider tells the chefs d'unité which
     * section accounts have no treasurer this year.
     *
     * @param int[] $linkedMemberIds persistent members.id values this session is linked to
     * @return int[] section ids; empty when this session is nobody's treasurer
     */
    public function getTreasurerSectionIds(array $linkedMemberIds, int $scoutYearId): array
    {
        $badge = $this->badgeRepository->findByName(BadgeService::BADGE_TREASURER);
        if ($badge === null || !$badge->isActive) {
            return [];
        }

        // A session that resolves to no member at all is simply not a
        // treasurer — never "everything".
        if ($linkedMemberIds === []) {
            return [];
        }

        return $this->treasurerSections($badge->id, $scoutYearId, $linkedMemberIds);
    }

    /**
     * Sections that have at least one treasurer this scout year — somebody
     * carrying the active `Trésorier` badge and animating the section.
     *
     * The other side of getTreasurerSectionIds(): now that no treasurer
     * means no access below `admin`, a section account nobody covers is
     * one only the chefs d'unité can reach, and they must be told so
     * (Service\TreasurerCoverageAttentionProvider) — above all each September,
     * when the badges of the new year have not been handed out yet.
     *
     * @return int[]
     */
    public function sectionIdsWithTreasurer(int $scoutYearId): array
    {
        $badge = $this->badgeRepository->findByName(BadgeService::BADGE_TREASURER);
        if ($badge === null || !$badge->isActive) {
            return [];
        }

        return $this->treasurerSections($badge->id, $scoutYearId, null);
    }

    /**
     * The one definition of « a treasurer of a section »: an active member
     * year of this scout year carrying the badge, with a chief/admin
     * function in that section — narrowed to some members, or not at all.
     *
     * The join on member_badges is what makes carrying the badge and
     * animating the section ONE condition rather than two: a badge holder
     * who animates nothing gets no section, and an animateur without the
     * badge gets none either.
     *
     * Same trap SectionService::getSectionStaff() guards against: a
     * section's animés carry the same section_id on their own
     * member_functions row as its staff, so the role filter is what stops
     * an animé from being read as staff of their own section.
     *
     * @param int[]|null $memberIds persistent members.id values, or null for everybody
     * @return int[]
     */
    private function treasurerSections(int $badgeId, int $scoutYearId, ?array $memberIds): array
    {
        $sql = "SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             JOIN member_badges mb ON mb.member_year_id = my.id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND mb.badge_id = ?
               AND f.role IN ('chief', 'admin')
               AND mf.section_id IS NOT NULL";
        $memberParams = [];
        if ($memberIds !== null) {
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $sql .= " AND my.member_id IN ({$placeholders})";
            $memberParams = array_map('intval', $memberIds);
        }

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$scoutYearId, $badgeId, ...$memberParams]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
