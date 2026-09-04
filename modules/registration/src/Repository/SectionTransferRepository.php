<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * `registration_section_transfers` — the "Passage" page's own storage for
 * a member's destination section for the target scout year (module spec:
 * planning data, never written to `member_years`, since Desk stays the
 * sole source of truth once that year is actually activated and
 * re-imported). Keyed on the permanent `member_id`, not a member_year id,
 * so the pick survives until the next Desk import regardless of exactly
 * when during the transition it was made.
 */
class SectionTransferRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findDestinationSectionId(int $memberId, int $targetScoutYearId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT destination_section_id FROM registration_section_transfers WHERE member_id = ? AND '
                . 'target_scout_year_id = ?'
        );
        $stmt->execute([$memberId, $targetScoutYearId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    /**
     * @param array<int> $memberIds
     * @return array<int, int> member_id => destination_section_id
     */
    public function findDestinationsForMembers(array $memberIds, int $targetScoutYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, destination_section_id FROM registration_section_transfers
             WHERE target_scout_year_id = ? AND member_id IN ({$placeholders})"
        );
        $stmt->execute([$targetScoutYearId, ...array_values($memberIds)]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['member_id']] = (int) $row['destination_section_id'];
        }

        return $result;
    }

    /**
     * Removes the destination a chief had picked, back to "not decided
     * yet" — the counterpart of setDestination(), reached from the
     * Passage page's own "— Non défini —" option (value 0, exactly like
     * Repository\RegistrationRequestRepository::updateIntendedSection()'s
     * own null case, so the two pickers behave the same way).
     *
     * A DELETE rather than a nullable column: `destination_section_id` is
     * NOT NULL by design (schema.sql) — the absence of a row IS the
     * "undecided" state, and everything that reads this table already
     * treats a missing row that way.
     *
     * Note that Service\PassageService::autoAssignSingleOptionDestinations()
     * will re-assign a member whose arrival branch holds exactly one
     * section on the next page load: there is no decision to preserve
     * there, so clearing only ever sticks where a real choice exists.
     */
    public function clearDestination(int $memberId, int $targetScoutYearId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM registration_section_transfers WHERE member_id = ? AND target_scout_year_id = ?'
        );
        $stmt->execute([$memberId, $targetScoutYearId]);
    }

    /**
     * Every destination of one target year, gone (roadmap IT-18's
     * « Réinitialiser »).
     *
     * A single DELETE rather than a loop over clearDestination(): the
     * button empties the year, and doing that one row at a time would make
     * a half-reset possible on the way to a reset.
     */
    public function clearAllForYear(int $targetScoutYearId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM registration_section_transfers WHERE target_scout_year_id = ?'
        );
        $stmt->execute([$targetScoutYearId]);
    }

    /**
     * SELECT-then-branch UPDATE/INSERT — never `ON DUPLICATE KEY UPDATE`,
     * same portability rule as the rest of this module (SQLite, used by
     * the test suite, doesn't support it). `updated_at` is PHP-computed
     * for the same reason (never SQL's `NOW()`).
     */
    public function setDestination(int $memberId, int $targetScoutYearId, int $destinationSectionId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->pdo->prepare(
            'SELECT id FROM registration_section_transfers WHERE member_id = ? AND target_scout_year_id = ?'
        );
        $existing->execute([$memberId, $targetScoutYearId]);
        $id = $existing->fetchColumn();

        if ($id !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE registration_section_transfers SET destination_section_id = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$destinationSectionId, $now, $id]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_section_transfers (member_id, target_scout_year_id, destination_section_id, '
                . 'updated_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $targetScoutYearId, $destinationSectionId, $now]);
    }
}
