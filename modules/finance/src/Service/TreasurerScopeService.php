<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
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
        private BadgeRepository $badgeRepository,
        private MemberBadgeRepository $memberBadgeRepository
    ) {
    }

    /**
     * Sections this session is the treasurer of, or **null when the rule
     * is switched off entirely**.
     *
     * The null is the safety catch, and it is the whole reason this
     * returns `?array` rather than `array`. A unit that has never
     * assigned the `Trésorier` badge — which is every unit on the day it
     * installs this version — would otherwise wake up to finances nobody
     * can reach: the badge is the only thing that grants a section
     * account, so "no badge anywhere" would mean "no section account for
     * anyone". The rule therefore only starts applying once the unit has
     * actually said who its treasurers are, and switching it off again is
     * as simple as it was to switch on.
     *
     * Three separate ways to be off, all of them a deliberate act by the
     * unit rather than an accident:
     *
     *  - the badge does not exist (it is seeded, so this is an
     *    installation that removed it from the table by hand);
     *  - the badge is **deactivated** — the one action the badges screen
     *    offers for a default badge, and the only way a unit can say "we
     *    do not work this way" without deleting anybody's assignment;
     *  - nobody holds it for this scout year — the badge is assigned per
     *    year (`member_badges.member_year_id`), so a unit that used it
     *    last year and has not got round to it this year is off too, not
     *    locked out.
     *
     * An empty array is the opposite of null and means the rule IS on and
     * this session is nobody's treasurer: no section account. A caller
     * that cannot tell the two apart would fail open on every unit that
     * uses the feature, which is why they are different types rather than
     * a count.
     *
     * @param int[] $linkedMemberIds persistent members.id values this session is linked to
     * @return int[]|null section ids, or null when the rule is disabled
     */
    public function getTreasurerSectionIds(array $linkedMemberIds, int $scoutYearId): ?array
    {
        $badge = $this->badgeRepository->findByName(BadgeService::BADGE_TREASURER);
        if ($badge === null || !$badge->isActive) {
            return null;
        }
        if ($this->memberBadgeRepository->findMemberYearIdsForBadgeAndYear($badge->id, $scoutYearId) === []) {
            return null;
        }

        // The rule is on. From here a session that resolves to no member
        // at all is simply not a treasurer — never "everything".
        if ($linkedMemberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($linkedMemberIds), '?'));

        // The join on member_badges is what makes carrying the badge and
        // animating the section ONE condition rather than two: a badge
        // holder who animates nothing gets no section, and an animateur
        // without the badge gets none either.
        //
        // Same trap SectionService::getSectionStaff() guards against: a
        // section's animés carry the same section_id on their own
        // member_functions row as its staff, so the role filter is what
        // stops an animé from being read as staff of their own section.
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             JOIN member_badges mb ON mb.member_year_id = my.id
             WHERE my.member_id IN ({$placeholders})
               AND my.scout_year_id = ?
               AND my.is_active = 1
               AND mb.badge_id = ?
               AND f.role IN ('chief', 'admin')
               AND mf.section_id IS NOT NULL"
        );
        $stmt->execute([...array_map('intval', $linkedMemberIds), $scoutYearId, $badge->id]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
