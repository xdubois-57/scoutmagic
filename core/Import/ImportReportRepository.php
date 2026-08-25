<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Security\EncryptionService;

/**
 * Turns the frozen diff's foreign keys into something a chef d'unité can
 * read.
 *
 * The diff stores ids on purpose (no personal data at rest beyond what
 * `member_years` already holds). This is where the names come back, at
 * display time, for the handful of people one report actually names —
 * bounded by what a single import moved, never by the size of the roster.
 *
 * Deliberately **not** filtered on `is_active`: half of what a report
 * names are people the import made inactive, and a report that could not
 * say who left would be missing its most useful half. The scout year
 * scope stays, because a name is a fact about a member IN a year.
 */
class ImportReportRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Readable identities for the given members, in the given scout year.
     *
     * Each entry is the array shape `display_name_full` expects (totem,
     * first_name, last_name), so the template names people the same way
     * every other admin screen does.
     *
     * @param int[] $memberIds
     * @return array<int, array{totem: ?string, first_name: string, last_name: string}>
     */
    public function findMemberIdentities(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_filter($memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, first_name_encrypted, last_name_encrypted, totem_encrypted
             FROM member_years
             WHERE member_id IN ({$placeholders}) AND scout_year_id = ?"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $identities = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $identities[(int) $row['member_id']] = [
                'totem' => $this->decrypt($row['totem_encrypted'], 'member_years.totem'),
                'first_name' => $this->decrypt($row['first_name_encrypted'], 'member_years.first_name') ?? '',
                'last_name' => $this->decrypt($row['last_name_encrypted'], 'member_years.last_name') ?? '',
            ];
        }

        return $identities;
    }

    /**
     * Section labels by id — the configured name when there is one, the
     * Desk code otherwise, which is what every other screen falls back to.
     *
     * @param int[] $sectionIds
     * @return array<int, string>
     */
    public function findSectionLabels(array $sectionIds): array
    {
        return $this->fetchLabels(
            'SELECT id, desk_code, name AS label FROM sections WHERE id IN (%s)',
            $sectionIds
        );
    }

    /**
     * @param int[] $functionIds
     * @return array<int, string>
     */
    public function findFunctionLabels(array $functionIds): array
    {
        return $this->fetchLabels(
            'SELECT id, desk_code, label FROM functions WHERE id IN (%s)',
            $functionIds
        );
    }

    /**
     * @param int[] $feeCategoryIds
     * @return array<int, string>
     */
    public function findFeeCategoryLabels(array $feeCategoryIds): array
    {
        return $this->fetchLabels(
            'SELECT id, desk_code, label FROM fee_categories WHERE id IN (%s)',
            $feeCategoryIds
        );
    }

    /**
     * @param int[] $branchIds
     * @return array<int, string>
     */
    public function findBranchLabels(array $branchIds): array
    {
        return $this->fetchLabels(
            'SELECT id, desk_code, label FROM age_branches WHERE id IN (%s)',
            $branchIds
        );
    }

    /**
     * Which of these functions still await a role on Config Desk.
     *
     * A new function is imported at role `identified`, unconfirmed
     * (SECURITY.md §3). A report months old must not keep saying "to
     * qualify" about one somebody has since qualified — that is the one
     * thing on this page that is allowed to be current, because it is a
     * call to action rather than a statement about the past.
     *
     * @param int[] $functionIds
     * @return int[]
     */
    public function findStillUnconfirmed(array $functionIds): array
    {
        $functionIds = array_values(array_unique(array_filter($functionIds)));
        if ($functionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($functionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id FROM functions WHERE id IN ({$placeholders}) AND confirmed = 0"
        );
        $stmt->execute($functionIds);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Run one of the four literal label queries above.
     *
     * $sql is a constant from this class, carrying a single `%s` where
     * the id placeholders go — the ids themselves are always bound, never
     * interpolated. Four near-identical literal queries rather than one
     * query with an interpolated table name: a table name assembled at
     * runtime is exactly the shape `Tests\Security\SqlInjectionAuditTest`
     * exists to refuse, and it would be refusing it for a good reason
     * even though these particular values are literals today.
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    private function fetchLabels(string $sql, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $stmt->execute($ids);

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $label = $row['label'] !== null && (string) $row['label'] !== ''
                ? (string) $row['label']
                : (string) $row['desk_code'];
            $labels[(int) $row['id']] = $label;
        }

        return $labels;
    }

    private function decrypt(mixed $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $this->encryption->decrypt(is_resource($value) ? (string) stream_get_contents($value) : (string) $value, $context);
        } catch (\Throwable) {
            // A report that cannot read one name still has to render the
            // rest: the alternative is a page that fails entirely because
            // of one unreadable row.
            return null;
        }
    }
}
