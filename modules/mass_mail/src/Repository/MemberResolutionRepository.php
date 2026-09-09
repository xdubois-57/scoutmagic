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
     * The « Anciens » default list: a member who was active in a PAST
     * scout year and is not active in the effective one.
     *
     * Resolved fresh on every use, never materialised (no table, no
     * junction, no scheduled task). A departure is not an event this site
     * receives — it is the absence of a row in the next Desk import, so
     * there is no hook to hang « add to the former members » on. A stored
     * list would have to be recomputed by a scheduled task anyway, which
     * means running this query and then storing an answer that can only
     * be out of date: a former member who comes back, a Desk import
     * correcting a past year, a year transition done in two steps.
     *
     * Three conditions, and the third is the one that needs stating:
     *
     * 1. at least one `is_active = 1` row on a scout year strictly BEFORE
     *    the effective one, compared on `start_date` — a scout year row is
     *    created the moment something needs it, so its id says nothing
     *    about chronology;
     * 2. no `is_active = 1` row on the effective year;
     * 3. present in at least `$minScoutYears` DISTINCT scout years —
     *    `member_years` is an ANNUAL snapshot and Desk gives neither an
     *    entry nor a leaving date, so somebody who stayed three weeks and
     *    somebody who stayed twelve months both have exactly one row. The
     *    number of distinct scout years is the only real granularity
     *    there is, which is why the setting is in years and not months.
     *
     * `$maxYearsSinceDeparture` bounds the other end, counted in scout
     * years since the last active one; `0` means no bound.
     *
     * **`unit_mail_consent` IS applied here**, and only here — read from
     * the winning row rather than filtered in the query, so that all four
     * answers this method gives (in the list at all, at which address,
     * under which scout year, and how long ago they left) come from the
     * SAME row. The rest of
     * this repository deliberately ignores it (see the class docblock):
     * the Desk column it comes from was judged unreliable, and for members
     * who are in the unit right now that is defensible — they are being
     * written to about the thing they take part in. Somebody who left five
     * years ago is a different case: nothing about the unit's ordinary
     * life justifies writing to them, the only positive signal anybody
     * ever recorded is that column, and a bounce or a complaint from an
     * address nobody has used in years costs the whole domain's sending
     * reputation. So an unreliable yes is still the only yes there is, and
     * this list asks for it.
     *
     * Each member is resolved from THEIR OWN last active year: that year's
     * address is the last one the unit ever knew for them, and it is what
     * the recipient row must be tagged with — the tracking page looks a
     * recipient's profile up by `scout_year_id`, and theirs only exists
     * for that year.
     *
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     */
    public function resolveFormerMembers(
        int $effectiveScoutYearId,
        int $minScoutYears,
        int $maxYearsSinceDeparture
    ): array {
        // unit_mail_consent is SELECTED, never filtered on here — see
        // the reduction below for why that distinction is the whole
        // correctness of this query.
        $stmt = $this->pdo->prepare(
            'SELECT my.member_id, my.scout_year_id, my.email_encrypted, my.unit_mail_consent, sy.start_date
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.is_active = 1
               AND sy.start_date < (SELECT start_date FROM scout_years WHERE id = ?)
               AND my.member_id NOT IN (
                   SELECT member_id FROM member_years WHERE scout_year_id = ? AND is_active = 1
               )
             ORDER BY my.member_id ASC, sy.start_date ASC, my.id ASC'
        );
        $stmt->execute([$effectiveScoutYearId, $effectiveScoutYearId]);

        // Ascending order + overwrite = the last active year wins, which
        // is the row this member is resolved from.
        $lastActive = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lastActive[(int) $row['member_id']] = $row;
        }

        // Consent is read from THAT row, and only once it has won.
        // Filtering on it in the WHERE above would have made the winner
        // « the last active year that also happened to consent », not the
        // last active year: somebody whose most recent Desk snapshot says
        // no would still have been written to, at the stale address of an
        // older year, tagged with that older year's id — the profile the
        // tracking page then fails to find — and measured against the
        // departure bound from the wrong date. One row decides all four,
        // or none of them is trustworthy.
        $lastActive = array_filter(
            $lastActive,
            static fn(array $row): bool => (int) $row['unit_mail_consent'] === 1
        );
        // After the filter, never before it: the consent column is
        // unreliable and often unset, so « candidates found, none of them
        // consenting » is the ordinary case, not the edge one — and it is
        // the one that would otherwise reach countDistinctScoutYears()
        // with no ids and build `IN ()`, which MySQL and MariaDB reject
        // outright. SQLite tolerates it, so the tests here would never
        // have said so.
        if ($lastActive === []) {
            return [];
        }

        $yearCounts = $this->countDistinctScoutYears(array_keys($lastActive));
        $cutoff = $maxYearsSinceDeparture > 0
            ? $this->scoutYearStartDateOffsetBy($effectiveScoutYearId, $maxYearsSinceDeparture)
            : null;

        $members = [];
        foreach ($lastActive as $memberId => $row) {
            if (($yearCounts[$memberId] ?? 0) < $minScoutYears) {
                continue;
            }
            if ($cutoff !== null && (string) $row['start_date'] < $cutoff) {
                continue;
            }

            $members[] = [
                'member_id' => $memberId,
                'email' => $row['email_encrypted'] !== null
                    ? $this->encryption->decrypt($row['email_encrypted'], 'member_years.email')
                    : null,
                'scout_year_id' => (int) $row['scout_year_id'],
            ];
        }

        return $members;
    }

    /**
     * How many DISTINCT scout years each member was active in — the
     * honest substitute for « how long did they stay », `member_years`
     * being an annual snapshot.
     *
     * @param int[] $memberIds
     * @return array<int, int> member_id => distinct active scout years
     */
    private function countDistinctScoutYears(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT member_id, COUNT(DISTINCT scout_year_id) AS years
             FROM member_years
             WHERE is_active = 1 AND member_id IN (' . self::placeholders($memberIds) . ')
             GROUP BY member_id'
        );
        $stmt->execute(array_values($memberIds));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['member_id']] = (int) $row['years'];
        }

        return $counts;
    }

    /**
     * The `start_date` of the scout year `$offset` years before the given
     * one, or null when the unit has no such year — in which case nothing
     * is old enough to be excluded and the bound simply does not bite.
     *
     * Counted in scout YEARS the unit actually has rather than in calendar
     * arithmetic: a unit that skipped a year has no row for it, and
     * subtracting ten from a date would silently include one it should
     * have excluded.
     */
    private function scoutYearStartDateOffsetBy(int $scoutYearId, int $offset): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT start_date FROM scout_years
             WHERE start_date <= (SELECT start_date FROM scout_years WHERE id = ?)
             ORDER BY start_date DESC
             LIMIT 1 OFFSET ?'
        );
        $stmt->bindValue(1, $scoutYearId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    /**
     * The oldest scout year the unit has ever imported — what the
     * « Anciens » list's own description names, because that list cannot
     * know anybody from before it.
     */
    public function oldestKnownScoutYearLabel(): ?string
    {
        $stmt = $this->pdo->query(
            'SELECT sy.label FROM scout_years sy
             JOIN member_years my ON my.scout_year_id = sy.id
             ORDER BY sy.start_date ASC
             LIMIT 1'
        );
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return $value !== false ? (string) $value : null;
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
