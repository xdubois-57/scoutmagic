<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;

/**
 * The Desk addresses of the members a projected year expects, for
 * `Api\ProjectedPopulationProvider::reachableRecipients()`.
 *
 * A repository rather than a query inside the adapter, for the ordinary
 * reason: this decrypts `member_years.email`, and decryption lives in
 * repositories and nowhere else (SECURITY.md §5, ARCHITECTURE.md §7.2).
 *
 * **The current year's row, not the target year's.** The whole point of a
 * projection is that the target year does not exist in Desk yet, so most
 * of these members have no row under it; the address to write to is the one
 * they are reachable at today. A member who DOES already have a target-year
 * row is answered from it, since that is the more recent of the two.
 */
class ProjectedMemberEmailRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array<int, int> $memberIds
     * @return array<int, string> member id => address, members with no
     *         usable address simply absent
     */
    public function findEmails(array $memberIds, int $currentYearId, int $targetYearId): array
    {
        $memberIds = array_values(array_unique($memberIds));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        // Ordered so the target year's row is read LAST and therefore wins
        // the overwrite below — the more recent address of the two.
        $stmt = $this->pdo->prepare(
            "SELECT member_id, scout_year_id, email_encrypted FROM member_years
             WHERE member_id IN ({$placeholders}) AND scout_year_id IN (?, ?)
             ORDER BY CASE WHEN scout_year_id = ? THEN 1 ELSE 0 END"
        );
        $stmt->execute([...$memberIds, $currentYearId, $targetYearId, $targetYearId]);

        $emails = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['email_encrypted'] === null) {
                continue;
            }

            $email = trim($this->encryption->decrypt($row['email_encrypted'], 'member_years.email'));
            if ($email === '') {
                continue;
            }

            $emails[(int) $row['member_id']] = $email;
        }

        return $emails;
    }
}
