<?php

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
 * distorted counts, everything about a member is decided from a SINGLE function:
 * their principal one (is_main_function, then lowest id for determinism).
 *
 * Only *animés* (the children) are returned. A section contains both animés and
 * their animateurs/chefs; a member is treated as staff — and excluded — when
 * their PRINCIPAL function has an elevated role (intendant/chief/admin/superadmin).
 * A member whose principal function is an animé role is counted even if they also
 * hold a secondary leadership function (e.g. a pionnier who is also an assistant).
 * The branch is the principal function's branch; a member whose principal function
 * carries no branch is not counted.
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
     * @return array<int, array{branch_label: string, branch_sort_order: int, birth_date: ?string, gender: ?string}>
     */
    public function getMemberBranchData(int $scoutYearId): array
    {
        $pdo = $this->connection->getPdo();

        // One row per member: their principal function (is_main_function first,
        // then lowest id for a deterministic pick). LEFT JOIN age_branches so a
        // principal function without a branch is still seen (and then skipped).
        $stmt = $pdo->prepare(
            'SELECT my.id AS member_year_id,
                    my.birth_date_encrypted,
                    my.gender_encrypted,
                    f.role AS function_role,
                    ab.label AS branch_label,
                    ab.sort_order AS branch_sort_order
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN age_branches ab ON mf.age_branch_id = ab.id
             WHERE my.scout_year_id = ? AND my.is_active = 1
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC'
        );
        $stmt->execute([$scoutYearId]);

        // Keep only the first row seen per member = their principal function.
        $principal = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $memberYearId = (int) $r['member_year_id'];
            if (!isset($principal[$memberYearId])) {
                $principal[$memberYearId] = $r;
            }
        }

        $rows = [];
        foreach ($principal as $r) {
            // Staff (their principal function is elevated) and members whose
            // principal function has no branch are not counted.
            if (in_array((string) $r['function_role'], self::STAFF_ROLES, true)) {
                continue;
            }
            if ($r['branch_label'] === null) {
                continue;
            }
            $rows[] = [
                'branch_label' => (string) $r['branch_label'],
                'branch_sort_order' => (int) $r['branch_sort_order'],
                'birth_date' => $this->decryptNullable($r['birth_date_encrypted']),
                'gender' => $this->decryptNullable($r['gender_encrypted']),
            ];
        }

        return $rows;
    }

    private function decryptNullable(mixed $value): ?string
    {
        return $value ? $this->encryption->decrypt($value) : null;
    }
}
