<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Config\ScoutYearService;

/**
 * What this installation is currently failing to recognise in its Desk
 * data (issue #356) — the one place the question is answered, for the
 * three screens that ask it: the box at the top of Correspondances Desk,
 * the support package, and the daily report.
 *
 * ## Why this is computed and not recorded
 *
 * An import could perfectly well write a row saying « I did not know what
 * to do with this ». It would then have to be deleted when somebody
 * qualifies the function, and when a later version learns the branch, and
 * when a unit maps the tariff by hand — three unmaking paths, each of
 * which is a way for the list to keep announcing a problem that no longer
 * exists. Recomputing has one: the value is either still unrecognised or
 * it is not.
 *
 * ## What counts as unrecognised, per kind
 *
 * - **Function** — `functions.confirmed = 0`, which is the site's own
 *   mark for « created by an import, qualified by nobody ». Whoever holds
 *   one sees no more than an ordinary member (SECURITY.md §3).
 * - **Branch** — `sort_order = 99`, what
 *   {@see AgeBranchRepository::canonicalSortOrder()} returns when none of
 *   its seven needles matched. No default logo, and an arbitrary rank in
 *   every picker.
 *
 * The CSV header kind has no entry here on purpose: an unexpected column
 * is not a state this installation is in, it is an import that stopped.
 * {@see DeskCsvParser} journals it at the moment it happens, because
 * afterwards there is nothing left to observe.
 *
 * **A Desk tariff is deliberately not a kind at all.** One outside the
 * three household ones is an expected state, not a defect — see
 * {@see DeskMappingGapKind} for the rule and the wordings that pin it.
 */
class DeskMappingGapService
{
    public function __construct(
        private \PDO $pdo,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * Every gap, most-carried first, so a value affecting the whole unit
     * is read before a single typo.
     *
     * @return list<DeskMappingGap>
     */
    public function gaps(): array
    {
        $gaps = array_merge($this->functionGaps(), $this->branchGaps());

        usort($gaps, static function (DeskMappingGap $a, DeskMappingGap $b): int {
            return [$b->affectedCount, $a->rawValue] <=> [$a->affectedCount, $b->rawValue];
        });

        return $gaps;
    }

    /**
     * @return list<DeskMappingGap>
     */
    private function functionGaps(): array
    {
        $scoutYearId = $this->currentScoutYearId();

        $stmt = $this->pdo->query(
            'SELECT id, desk_code, label FROM functions WHERE confirmed = 0 ORDER BY desk_code'
        );
        if ($stmt === false) {
            return [];
        }

        $gaps = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $gaps[] = new DeskMappingGap(
                DeskMappingGapKind::FUNCTION,
                self::displayValue($row),
                $scoutYearId === null ? 0 : $this->countMembersWithFunction((int) $row['id'], $scoutYearId)
            );
        }

        return $gaps;
    }

    /**
     * @return list<DeskMappingGap>
     */
    private function branchGaps(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, desk_code, label FROM age_branches WHERE sort_order = ? ORDER BY desk_code'
        );
        $stmt->execute([AgeBranchRepository::UNKNOWN_SORT_ORDER]);

        $gaps = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $gaps[] = new DeskMappingGap(
                DeskMappingGapKind::BRANCH,
                self::displayValue($row),
                $this->countSectionsInBranch((int) $row['id'])
            );
        }

        return $gaps;
    }

    /**
     * Today's scout year, read and never created.
     *
     * `ScoutYearService::getCurrentYear()` would do it in one call, but it
     * writes: it creates the year when the table has no row for it. This
     * list is read by a page render and by a support package, neither of
     * which has any business inserting a row — and « no year on record »
     * is a perfectly good answer here, which the counts then state as
     * zero rather than inventing.
     *
     * Deliberately not the PINNED public year either. That setting exists
     * so a unit can show next September before it happens; « how many
     * people does this affect right now » is a question about now.
     */
    private function currentScoutYearId(): ?int
    {
        $year = $this->scoutYears->findByLabel(ScoutYearService::labelForDate(new \DateTimeImmutable()));

        return $year === null ? null : $year['id'];
    }

    private function countMembersWithFunction(int $functionId, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT mf.member_year_id)
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             WHERE mf.function_id = ? AND my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$functionId, $scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    private function countSectionsInBranch(int $branchId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sections WHERE age_branch_id = ? AND is_active = 1');
        $stmt->execute([$branchId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The label when there is one, the Desk code otherwise. An import
     * creates both from the same string, so they are usually equal — but a
     * function relabelled on this page keeps its code, and the code is
     * what the federation and a later version of this software talk about.
     *
     * @param array<string, mixed> $row
     */
    private static function displayValue(array $row): string
    {
        $label = trim((string) $row['label']);

        return $label !== '' ? $label : (string) $row['desk_code'];
    }
}
