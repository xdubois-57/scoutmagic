<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Core\Security\EncryptionService;
use Modules\Fees\Value\IgnoredHousehold;

/**
 * The households a chef d'unité set aside.
 *
 * The reason is free text about a family's arrangements — a separation, a
 * shared custody, a flatshare — so it is encrypted at rest and decrypted
 * only here (SECURITY.md §5), exactly like
 * `member_years.leaving_comment_encrypted`. It is never journaled and never
 * leaves this screen.
 */
class IgnoredHouseholdRepository
{
    private const ENCRYPTION_CONTEXT = 'fees_ignored_households.reason';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /** @return array<string, IgnoredHousehold> keyed by address blind index */
    public function findAllForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, address_blind_index, composition_hash, reason_encrypted, created_at
             FROM fees_ignored_households
             WHERE scout_year_id = ?
             ORDER BY id'
        );
        $stmt->execute([$scoutYearId]);

        $ignored = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $blindIndex = (string) $row['address_blind_index'];
            $ignored[$blindIndex] = new IgnoredHousehold(
                (int) $row['id'],
                $blindIndex,
                (string) $row['composition_hash'],
                $this->encryption->decrypt((string) $row['reason_encrypted'], self::ENCRYPTION_CONTEXT),
                new \DateTimeImmutable((string) $row['created_at'])
            );
        }

        return $ignored;
    }

    public function ignore(
        int $scoutYearId,
        string $addressBlindIndex,
        string $compositionHash,
        string $reason,
        ?int $createdBy
    ): void {
        $this->forget($scoutYearId, $addressBlindIndex);

        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_ignored_households
                 (scout_year_id, address_blind_index, composition_hash, reason_encrypted, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $scoutYearId,
            $addressBlindIndex,
            $compositionHash,
            $this->encryption->encrypt($reason, self::ENCRYPTION_CONTEXT),
            date('Y-m-d H:i:s'),
            $createdBy,
        ]);
    }

    public function forget(int $scoutYearId, string $addressBlindIndex): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM fees_ignored_households WHERE scout_year_id = ? AND address_blind_index = ?'
        );
        $stmt->execute([$scoutYearId, $addressBlindIndex]);
    }
}
