<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MemberStats\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;

/**
 * Reads the raw member data needed for the statistics page.
 *
 * This is the ONLY layer that touches encrypted personal data: it decrypts each
 * animé's birth date and gender and returns a minimal per-member row (branch +
 * birth date + gender — no names, no contact info). The Service turns these rows
 * into anonymous aggregate counts before anything reaches the View.
 *
 * A member can have several rows in the Desk export (one per address and/or per
 * function), which the import turns into several member_functions rows. To avoid
 * distorted counts, a member is counted ONCE, from a single function.
 *
 * Only *animés* (the children) are returned. A section contains both animés and
 * their animateurs/chefs; a member is treated as staff — and excluded — when
 * their PRINCIPAL function (is_main_function, then lowest id) has an elevated role
 * (intendant/chief/admin/superadmin). A member whose principal function is an
 * animé role is counted even if they also hold a secondary leadership function
 * (e.g. a pionnier who is also an assistant).
 *
 * The branch comes from the first function that can place the member: a
 * non-staff function with a branch, its own or that of its section. So a
 * principal function without a branch (a unit function, say) does not hide the
 * section function that makes the member an animé — the same member the
 * « Animés de la section » roster lists (#834).
 */
class MemberStatsRepository
{
    /** Function roles that mark a member as staff (animateur), not an animé. */
    private const STAFF_ROLES = ['intendant', 'chief', 'admin', 'superadmin'];

    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return array<
     *     int,
     *     array{
     *         branch_label: string,
     *         branch_sort_order: int,
     *         birth_date: ?string,
     *         gender: ?string,
     *         scout_year_offset: int
     *     }
     * >
     */
    public function getMemberBranchData(int $scoutYearId): array
    {
        $pdo = $this->connection->getPdo();

        // All functions of the year's active members, principal first. The branch
        // is the function's own, else its section's. LEFT JOINs so a function
        // without either is still seen (and skipped below, not fatal).
        $stmt = $pdo->prepare(
            'SELECT my.id AS member_year_id,
                    my.birth_date_encrypted,
                    my.gender_encrypted,
                    my.scout_year_offset,
                    f.role AS function_role,
                    ab.label AS branch_label,
                    ab.sort_order AS branch_sort_order
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN sections s ON mf.section_id = s.id
             LEFT JOIN age_branches ab ON ab.id = COALESCE(mf.age_branch_id, s.age_branch_id)
             WHERE my.scout_year_id = ? AND my.is_active = 1
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC'
        );
        $stmt->execute([$scoutYearId]);

        $rows = [];
        $decided = [];
        $isFirst = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $memberYearId = (int) $r['member_year_id'];
            if (isset($decided[$memberYearId])) {
                continue;
            }
            $isStaff = in_array((string) $r['function_role'], self::STAFF_ROLES, true);
            if (!isset($isFirst[$memberYearId])) {
                $isFirst[$memberYearId] = true;
                if ($isStaff) {
                    // Principal function is elevated: this member is staff.
                    $decided[$memberYearId] = true;
                    continue;
                }
            }
            if ($isStaff || $r['branch_label'] === null) {
                continue; // cannot place the member with this function; try the next one
            }
            $decided[$memberYearId] = true;
            $rows[] = [
                'branch_label' => (string) $r['branch_label'],
                'branch_sort_order' => (int) $r['branch_sort_order'],
                'birth_date' => $this->decryptNullable($r['birth_date_encrypted'], 'member_years.birth_date'),
                'gender' => $this->decryptNullable($r['gender_encrypted'], 'member_years.gender'),
                'scout_year_offset' => (int) $r['scout_year_offset'],
            ];
        }

        return $rows;
    }

    private function decryptNullable(mixed $value, string $context): ?string
    {
        return $value ? $this->encryption->decrypt($value, $context) : null;
    }
}
