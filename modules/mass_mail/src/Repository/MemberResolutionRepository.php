<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * Resolves "which members does this mailing list currently mean" — every
 * method here is a fresh, uncached query against member_years/
 * member_functions (module spec: "Résolution dynamique à chaque
 * utilisation (pas de cache de membres)"). Deliberately its own
 * repository, separate from Repository\MailingListRepository (custom
 * lists' own identity/criteria) and Repository\RecipientRepository
 * (already-sent recipients) — this one only ever reads core member
 * tables, never mass_mail's own.
 *
 * Every query filters member_years.is_active = 1 only. An earlier version
 * also filtered on member_years.unit_mail_consent — deliberately dropped:
 * the "Courrier d'unité" Desk column it comes from isn't reliable enough
 * to gate delivery on (unit decision).
 *
 * @phpstan-type ResolvedMember array{member_id: int, email: ?string}
 */
class MemberResolutionRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The "Section - {nom}" default list — every member (animateur,
     * intendant, animé) holding any function within this section.
     *
     * @return ResolvedMember[]
     */
    public function resolveSectionMembers(int $sectionId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             WHERE mf.section_id = ? AND my.scout_year_id = ?
               AND my.is_active = 1'
        );
        $stmt->execute([$sectionId, $scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * The "Membres actifs" default list — every active member of the
     * unit, any section.
     *
     * @return ResolvedMember[]
     */
    public function resolveActiveMembers(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, email_encrypted
             FROM member_years
             WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * The "Chefs uniquement" default list — a function whose role is
     * chief or above (chief, admin, superadmin).
     *
     * @return ResolvedMember[]
     */
    public function resolveChiefs(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN ('chief', 'admin', 'superadmin')"
        );
        $stmt->execute([$scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * A custom list's criteria, on three axes: functions, sections and
     * badges. **AND between the axes, OR within each one** — a member
     * qualifies when they hold one of the selected functions AND are
     * within one of the selected sections AND wear one of the selected
     * badges.
     *
     * **An empty axis is not a constraint**, and drops out of the
     * conjunction entirely: a list naming three functions and no section
     * means those functions in every section. This reverses the previous
     * convention, which returned the empty set as soon as EITHER of the
     * two axes was empty — so « les intendants, toutes sections
     * confondues » was unexpressible, and a list that lost its last
     * section to a Desk import silently resolved to nobody instead of
     * widening. That behaviour was never reachable through the form
     * (`MailingListService::validateCriteria()` demanded one of each), so
     * nothing stored can change meaning under the new rule.
     *
     * **All three axes empty resolves to NO member**, never to every
     * member. « No constraint on any axis » would otherwise mean « the
     * whole unit », which is what the « Membres actifs » default list is
     * for, and a mail to the entire unit is not something a form should
     * be able to produce by having nothing filled in.
     *
     * The badge axis joins `member_badges` on the member_year of the year
     * being resolved: badges are already historised per scout year
     * (`Core\Badge`, ARCHITECTURE.md §8.11), so a badge worn two years ago
     * never counts for this one.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @return ResolvedMember[]
     */
    public function resolveCustomList(
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        int $scoutYearId
    ): array {
        if ($functionIds === [] && $sectionIds === [] && $badgeIds === []) {
            return [];
        }

        $conditions = ['my.scout_year_id = ?', 'my.is_active = 1'];
        $parameters = [$scoutYearId];

        // The member_functions join carries both the function and the
        // section axis, and only those two — joining it for a badge-only
        // list would silently add « holds any function » to the criteria.
        $joins = '';
        if ($functionIds !== [] || $sectionIds !== []) {
            $joins .= ' JOIN member_functions mf ON mf.member_year_id = my.id';
        }
        if ($functionIds !== []) {
            $conditions[] = 'mf.function_id IN (' . self::placeholders($functionIds) . ')';
            $parameters = [...$parameters, ...array_values($functionIds)];
        }
        if ($sectionIds !== []) {
            $conditions[] = 'mf.section_id IN (' . self::placeholders($sectionIds) . ')';
            $parameters = [...$parameters, ...array_values($sectionIds)];
        }
        if ($badgeIds !== []) {
            $joins .= ' JOIN member_badges mb ON mb.member_year_id = my.id';
            $conditions[] = 'mb.badge_id IN (' . self::placeholders($badgeIds) . ')';
            $parameters = [...$parameters, ...array_values($badgeIds)];
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my' . $joins . '
             WHERE ' . implode(' AND ', $conditions)
        );
        $stmt->execute($parameters);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * @param int[] $ids
     */
    private static function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * Mail-merge import: which members do these Desk "Tiers" values
     * designate — keyed by the exact desk_id string, a value with no
     * member is simply absent (Service\AudienceImportService then refuses
     * the file, naming the row).
     *
     * @param string[] $deskIds
     * @return array<string, int> desk_id => member_id
     */
    public function findMemberIdsByDeskIds(array $deskIds): array
    {
        $deskIds = array_values(array_unique(array_filter($deskIds, fn(string $d) => $d !== '')));
        if ($deskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($deskIds), '?'));
        $stmt = $this->pdo->prepare("SELECT desk_id, id FROM members WHERE desk_id IN ({$placeholders})");
        $stmt->execute($deskIds);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['desk_id']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * Mail-merge freeze: each member's most recent member_years profile
     * (by the year's real start_date, not row id) — its Desk email and
     * its scout_year_id, which is what the frozen recipient row gets
     * tagged with. Deliberately NOT filtered on is_active: a mail-merge
     * file explicitly names its members, so the file is the authority —
     * an inactive member listed by their Tiers is still written to.
     *
     * @param int[] $memberIds
     * @return array<int, array{scout_year_id: int, email: ?string}> keyed by member_id;
     *         a member with no member_years row at all is absent
     */
    public function resolveMergeMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT my.member_id, my.scout_year_id, my.email_encrypted, sy.start_date
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id IN ({$placeholders})
             ORDER BY my.member_id ASC, sy.start_date ASC, my.id ASC"
        );
        $stmt->execute($memberIds);

        // Ascending order + overwrite = the last (most recent) year wins.
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['member_id']] = [
                'scout_year_id' => (int) $row['scout_year_id'],
                'email' => $row['email_encrypted'] !== null
                    ? $this->encryption->decrypt($row['email_encrypted'], 'member_years.email')
                    : null,
            ];
        }
        return $map;
    }

    /**
     * @return array{member_id: int, email: ?string}[]
     */
    private function hydrateResolvedMembers(\PDOStatement $stmt): array
    {
        $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $members[] = [
                'member_id' => (int) $row['member_id'],
                'email' => $row['email_encrypted'] !== null
                    ? $this->encryption->decrypt($row['email_encrypted'], 'member_years.email')
                    : null,
            ];
        }
        return $members;
    }
}
