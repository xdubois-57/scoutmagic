<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Import\MemberYearRepository;

class RoleResolver
{
    public function __construct(
        private MemberYearRepository $memberYearRepo,
        private EncryptionService $encryption,
        private \PDO $pdo
    ) {
    }

    /**
     * Resolve the effective role for an email address in the current scout year.
     *
     * 1. Check if user_accounts has is_super_admin=true → return superadmin.
     * 2. Compute email blind index.
     * 3. Find all member_years for the current scout year matching this blind index.
     * 4. For each member_year, load their member_functions.
     * 5. For each function, look up the role in the functions table.
     * 6. Return the highest role found.
     * 7. If no member_years found → return 'identified'.
     */
    public function resolve(string $email, int $currentScoutYearId): string
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        // Check super admin
        $stmt = $this->pdo->prepare(
            'SELECT is_super_admin FROM user_accounts WHERE email_blind_index = ?'
        );
        $stmt->execute([$blindIndex]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($userRow !== false && (bool) $userRow['is_super_admin']) {
            return 'superadmin';
        }

        // Find member years
        $memberYears = $this->memberYearRepo->findAllByEmail($blindIndex, $currentScoutYearId);

        if (count($memberYears) === 0) {
            return 'identified';
        }

        // Find highest role from functions
        $highestLevel = Role::IDENTIFIED->level();

        foreach ($memberYears as $my) {
            $stmt = $this->pdo->prepare(
                'SELECT f.role FROM member_functions mf
                 JOIN functions f ON mf.function_id = f.id
                 WHERE mf.member_year_id = ?'
            );
            $stmt->execute([$my['id']]);

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $role = Role::fromString((string) $row['role']);
                if ($role->level() > $highestLevel) {
                    $highestLevel = $role->level();
                }
            }
        }

        // Map level back to role string
        foreach (Role::cases() as $role) {
            if ($role->level() === $highestLevel) {
                return $role->value;
            }
        }

        return 'identified';
    }

    /**
     * Get all member_year IDs linked to an email for the current year.
     *
     * @return int[]
     */
    public function getLinkedMemberYears(string $email, int $currentScoutYearId): array
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);
        $memberYears = $this->memberYearRepo->findAllByEmail($blindIndex, $currentScoutYearId);

        return array_map(fn(array $my) => $my['id'], $memberYears);
    }

    /**
     * Login gate (module addendum): a user_accounts row with a valid
     * password/passkey/magic-link is not enough on its own — the email
     * must also correspond to a real member of the unit this scout year,
     * UNLESS the account is a super-admin (site operators are not
     * necessarily Desk members themselves, and must never be locked out
     * by this check). Deliberately the same current-year matching
     * resolve() itself uses — a member who dropped out has no current-year
     * row and is correctly rejected here too.
     */
    public function isEmailAuthorizedToLogin(string $email, int $currentScoutYearId): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        $stmt = $this->pdo->prepare('SELECT is_super_admin FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($userRow !== false && (bool) $userRow['is_super_admin']) {
            return true;
        }

        return count($this->memberYearRepo->findAllByEmail($blindIndex, $currentScoutYearId)) > 0;
    }
}
