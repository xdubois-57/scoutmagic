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
     * Every period ever recorded for this member, most recent first — the
     * member page (Core\Member\SectionDocumentPageService) walks this to
     * find every (section, scout year) the member was ever active in.
     *
     * @return MemberSectionPeriod[]
     */
    public function findAllForMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_section_periods WHERE member_id = ? ORDER BY scout_year_id DESC, start_date DESC'
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
