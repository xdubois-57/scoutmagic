<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Core\Security\EncryptionService;
use Core\Service\DeskDateParser;
use Modules\Fees\Invoice\PersonMatchKey;

/**
 * Builds the index an invoice's names are matched against: for one scout
 * year, every member keyed by folded surname + folded first name + ISO
 * birth date.
 *
 * **Why the whole year is decrypted rather than looked up by a blind
 * index**: `member_years` carries a blind index for the e-mail and for the
 * address, but none for name + birth date — that pair is only ever needed
 * here, and adding a column to a core table for one optional module's
 * import would be the wrong trade. An import is an admin action on a few
 * hundred rows, once every few weeks; a per-name query would be one
 * round trip per person on the invoice.
 *
 * Decryption happens here and nowhere else in the module (SECURITY.md §5),
 * and what leaves this class is a map of keys to `members.id` — never a
 * name.
 */
class InvoiceMemberMatchRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return array<string, int> match key => members.id
     */
    public function buildIndex(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted
             FROM member_years
             WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);

        $index = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $birthDate = DeskDateParser::toIso($this->decrypt($row['birth_date_encrypted'], 'member_years.birth_date'));
            if ($birthDate === null) {
                // Without a birth date there is no key: matching on the two
                // names alone would tie an invoice line to a namesake, and
                // a wrong match is worse than none.
                continue;
            }

            $key = PersonMatchKey::for(
                $this->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                $this->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                $birthDate
            );
            // A key already taken belongs to two members the site cannot
            // tell apart. Neither is matched — the same refusal to guess
            // between two candidates the registration module's
            // reconciliation makes (ARCHITECTURE.md §8.36).
            $index[$key] = array_key_exists($key, $index) ? null : (int) $row['member_id'];
        }

        return array_filter($index, static fn(?int $id): bool => $id !== null);
    }

    private function decrypt(mixed $value, string $context): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return $this->encryption->decrypt((string) $value, $context);
        } catch (\Throwable) {
            return '';
        }
    }
}
