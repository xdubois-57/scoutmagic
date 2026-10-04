<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

class SectionMembershipRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Distinct section ids a member_year currently has a function in
     * (member_functions.section_id, non-null) — the "desired" set
     * SectionMembershipService::syncForMember() reconciles periods
     * against right after Core\Import\DeskImportService replaces that
     * member's functions.
     *
     * @return int[]
     */
    public function findDistinctSectionIdsForMemberYear(int $memberYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT section_id FROM member_functions WHERE member_year_id = ? AND section_id IS NOT NULL'
        );
        $stmt->execute([$memberYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Section ids this member currently has an OPEN period for, within
     * this exact scout year.
     *
     * @return int[]
     */
    public function findOpenSectionIdsForMemberAndYear(int $memberId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT section_id FROM member_section_periods
             WHERE member_id = ? AND scout_year_id = ? AND end_date IS NULL'
        );
        $stmt->execute([$memberId, $scoutYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every OPEN period for this member belonging to a scout year other
     * than $scoutYearId — a period that should have been closed at an
     * earlier rollover but wasn't (see schema/core.sql's comment). Each
     * row carries its own scout_year_id so the caller can close it at
     * THAT year's end_date, never at today's import date.
     *
     * @return MemberSectionPeriod[]
     */
    public function findOpenPeriodsForMemberOutsideYear(int $memberId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_section_periods
             WHERE member_id = ? AND scout_year_id != ? AND end_date IS NULL'
        );
        $stmt->execute([$memberId, $scoutYearId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Close every open period for (member, section, scout year) at
     * $endDate (Y-m-d) — normally matches at most one row, but is written
     * as a bulk UPDATE so it stays correct even if an edge case (e.g. a
     * manually-corrected import) ever left more than one open.
     */
    public function closeOpenPeriods(int $memberId, int $sectionId, int $scoutYearId, string $endDate): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_section_periods SET end_date = ?
             WHERE member_id = ? AND section_id = ? AND scout_year_id = ? AND end_date IS NULL'
        );
        $stmt->execute([$endDate, $memberId, $sectionId, $scoutYearId]);
    }

    public function close(int $id, string $endDate): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_section_periods SET end_date = ? WHERE id = ?');
        $stmt->execute([$endDate, $id]);
    }

    public function open(int $memberId, int $sectionId, int $scoutYearId, string $startDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date)
             VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId, $startDate]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Every distinct (member, section, scout year) triple derivable from
     * member_functions right now — the raw material for
     * SectionMembershipService::backfillFromFunctions()'s one-time seed.
     *
     * @return array<int, array{member_id: int, section_id: int, scout_year_id: int}>
     */
    public function findDistinctMemberSectionYearTriples(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT my.member_id, mf.section_id, my.scout_year_id
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             WHERE mf.section_id IS NOT NULL'
        );

        return array_map(
            fn(array $row) => [
                'member_id' => (int) $row['member_id'],
                'section_id' => (int) $row['section_id'],
                'scout_year_id' => (int) $row['scout_year_id'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Whether ANY period (open or closed) already exists for (member,
     * section, scout year) — the dedup guard for
     * SectionMembershipService::backfillFromFunctions(), so re-running it
     * (e.g. if it's ever invoked more than once) never creates duplicates.
     */
    public function hasAnyPeriod(int $memberId, int $sectionId, int $scoutYearId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM member_section_periods WHERE member_id = ? AND section_id = ? AND scout_year_id = ? LIMIT 1'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Every member with a period in ANY of $sectionIds for $scoutYearId —
     * the reverse of hasAnyPeriod(), for callers that start from a set of
     * sections rather than from one member. Modules\Groups' notification
     * recipient resolution needs it: a discussion group linked to sections
     * has to answer "who is in these sections this year" at dispatch time,
     * which is what makes a member who left the section between the post
     * and the send drop out of the list on their own.
     *
     * Open and closed periods alike, exactly like hasAnyPeriod() — "was a
     * member of that section that year" is the question, not "is one right
     * now on this date" (that is hasPeriodCovering()).
     *
     * @param int[] $sectionIds
     * @return int[] distinct members.id values
     */
    public function findMemberIdsForSections(array $sectionIds, int $scoutYearId): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT member_id FROM member_section_periods
             WHERE scout_year_id = ? AND section_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$scoutYearId], array_map('intval', array_values($sectionIds))));

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The same question as findMemberIdsForSections(), answered per
     * section in a single query.
     *
     * The groups list needs one headcount per group, and a group's
     * sections are its own — asking section by section is a query per
     * group on a page that already batches everything else it needs.
     *
     * @param int[] $sectionIds
     * @return array<int, list<int>> section id => distinct members.id values
     */
    public function findMemberIdsBySection(array $sectionIds, int $scoutYearId): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT section_id, member_id FROM member_section_periods
             WHERE scout_year_id = ? AND section_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$scoutYearId], array_map('intval', array_values($sectionIds))));

        $bySection = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bySection[(int) $row['section_id']][] = (int) $row['member_id'];
        }

        return $bySection;
    }

    /**
     * Every period ever recorded for this member, most recent first — the
     * member page (Core\Member\SectionDocumentPageService) walks this to
     * find every (section, scout year) the member was ever active in.
     *
     * Ordered by the scout year's own start_date, never by scout_year_id:
     * that id is the order rows were CREATED, which is not the calendar.
     * Core\Config\ScoutYearService::ensureYear() makes a year on demand,
     * and the registration module makes next year's the first time a
     * request is accepted or a passage decided — so on a real install
     * 2027-2028 can carry a LOWER id than 2026-2027, and this list came
     * back shuffled. The visible cost was on the member page, whose
     * « Parcours dans l'unité » block is this list truncated to its first
     * SECTION_HISTORY_LIMIT entries: shuffled, the truncation kept the
     * wrong ones.
     *
     * scout_years.start_date is NOT NULL, so an inner join drops nothing
     * and needs no coalesce. The secondary sort stays on the period's own
     * start_date, for the member who changed section mid-year.
     *
     * @return MemberSectionPeriod[]
     */
    public function findAllForMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT msp.* FROM member_section_periods msp
             JOIN scout_years sy ON msp.scout_year_id = sy.id
             WHERE msp.member_id = ?
             ORDER BY sy.start_date DESC, msp.start_date DESC'
        );
        $stmt->execute([$memberId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Whether any period for (member, section, scout year) covers $date —
     * Core\Member\SectionDocumentOwnershipChecker's "was active in that
     * section for that scout year" rule (module addendum: membership
     * history only, never section visibility).
     */
    public function hasPeriodCovering(int $memberId, int $sectionId, int $scoutYearId, string $date): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM member_section_periods
             WHERE member_id = ? AND section_id = ? AND scout_year_id = ?
               AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
             LIMIT 1'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId, $date, $date]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MemberSectionPeriod
    {
        return new MemberSectionPeriod(
            id: (int) $row['id'],
            memberId: (int) $row['member_id'],
            sectionId: (int) $row['section_id'],
            scoutYearId: (int) $row['scout_year_id'],
            startDate: (string) $row['start_date'],
            endDate: $row['end_date'] !== null ? (string) $row['end_date'] : null
        );
    }
}
