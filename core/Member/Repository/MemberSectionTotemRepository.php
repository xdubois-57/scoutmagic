<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

use Core\Security\EncryptionService;

/**
 * The totem a staff member carries in one section for one scout year — the
 * Louveteaux' « Akela » (issue #722).
 *
 * **Apart from the Desk totem, on purpose.** `member_years.totem_encrypted`
 * is rewritten by every import; this table is written by a chief on the
 * Staffs page and by nothing else, so an import never erases it. Keyed on
 * the member-YEAR: a new scout year has new member-year rows and therefore
 * no section totem at all — nothing is carried over, which is the rule.
 *
 * Encrypted at rest like the Desk totem, under its own purpose string.
 */
final class MemberSectionTotemRepository
{
    private const CONTEXT = 'member_section_totems.totem';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Sets, replaces or — given null or blank — removes one section totem.
     */
    public function set(int $memberYearId, int $sectionId, ?string $totem, ?int $updatedBy = null): void
    {
        $totem = trim((string) $totem);

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM member_section_totems WHERE member_year_id = ? AND section_id = ?'
            );
            $delete->execute([$memberYearId, $sectionId]);

            if ($totem !== '') {
                $insert = $this->pdo->prepare(
                    'INSERT INTO member_section_totems (member_year_id, section_id, totem_encrypted, updated_at, '
                    . 'updated_by) VALUES (?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $memberYearId,
                    $sectionId,
                    $this->encryption->encrypt($totem, self::CONTEXT),
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $updatedBy,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Every section totem of the given member-years, in one query.
     *
     * @param int[] $memberYearIds
     * @return array<int, array<int, string>> member-year id => section id => totem
     */
    public function forMemberYears(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_year_id, section_id, totem_encrypted FROM member_section_totems "
                . "WHERE member_year_id IN ({$placeholders}) ORDER BY section_id"
        );
        $stmt->execute($memberYearIds);

        $totems = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $totems[(int) $row['member_year_id']][(int) $row['section_id']] = $this->encryption->decrypt(
                (string) $row['totem_encrypted'],
                self::CONTEXT
            );
        }

        return $totems;
    }
}
