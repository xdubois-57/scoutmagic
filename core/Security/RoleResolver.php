<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\ScoutYear\AuthorizationYears;

/**
 * Login-by-email resolution matches against BOTH the Desk-imported
 * address (member_years.email_blind_index, as always) and any currently-
 * 'valid' secondary address (Core\Member\MemberEmailRepository) — a
 * 'pending'/'inactive' secondary row never resolves a login. Module
 * addendum: unsubscribing an address (Core\Member\MemberEmailService::
 * unsubscribe()) revokes its ability to log in too, even for a 'desk'-
 * sourced status-override row — the one case where a Desk-address rule
 * isn't "always usable, no exceptions" (see schema/core.sql's
 * member_emails comment). A direct Desk-address match is therefore
 * excluded here whenever an inactive override row exists for that exact
 * (member, address) pair.
 *
 * ## Which scout year the question is asked in
 *
 * Every method here comes in two shapes: one taking a single
 * `$scoutYearId`, and one taking a `Core\ScoutYear\AuthorizationYears`
 * set. Both are current, and the difference is not a migration.
 *
 * The **single-year** shape answers "what is this address in THAT year",
 * which is what a caller holding a record already scoped to a year needs
 * — the staff-year eligibility test in `Core\ScoutYear\ScoutYearResolver`,
 * a personal ICS feed generated for one year, a notification about one
 * year's content. It is also the primitive the set shape is built on.
 *
 * The **set** shape answers "may this person come in at all, and as
 * what", which is a question about a person rather than about a year.
 * During the annual transition the answer is not the same in every year
 * of the set: the animateur of a section in the year that is ending has
 * no row at all in the year being prepared, and the animateur recruited
 * for the year being prepared has none in the year that is ending.
 * Resolving against one year answers exactly one of them, which is the
 * circularity this shape exists to break — the staff year used not to be
 * served to anyone who was not already staff *by the public year*, so the
 * person that year was prepared for could never reach it.
 *
 * The rule the set shape applies, and it is deliberately asymmetric:
 *
 * - each candidate year is resolved **independently**;
 * - a role obtained in the **public year** counts as it is;
 * - a role obtained in **any other** year of the set counts only if it
 *   reaches `intendant` — below that it is ignored, and the person keeps
 *   whatever the public year gives them. An animé enrolled for next year
 *   only can therefore sign in, and finds an empty space until the site
 *   switches over;
 * - the effective role is the **highest** role retained.
 *
 * The threshold is `intendant` because that is the one
 * `ScoutYearResolver::getEffectiveYear()` already applies to the staff
 * year: a newly appointed intendant has to be able to prepare enrolments
 * and fees before the season starts, exactly like a newly appointed
 * chief.
 *
 * A **super-admin short-circuits all of it** and always has, in both
 * shapes: that role comes from `user_accounts.is_super_admin` and depends
 * on no scout year whatsoever (SECURITY.md § The roster-replacement
 * barrier).
 *
 * The session preview is nowhere in here. It is chosen by the person it
 * would authorise, over any year that ever existed, so a role resolved
 * through it would hand the site back to whoever was Staff d'U in
 * 2019-2020. `Core\ScoutYear\AuthorizationYearService` builds the set out
 * of the installation's own state only, and cannot be handed a preview.
 */
class RoleResolver
{
    public function __construct(
        private MemberYearRepository $memberYearRepo,
        private EncryptionService $encryption,
        private \PDO $pdo,
        private ?MemberEmailRepository $memberEmailRepo = null
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
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        // Check super admin
        if ($this->isSuperAdmin($blindIndex)) {
            return 'superadmin';
        }

        return self::roleForLevel($this->highestFunctionLevel($blindIndex, $currentScoutYearId));
    }

    /**
     * The effective role over every year an access decision may use.
     *
     * Same contract as resolve(), and the same floor of `identified`; the
     * class docblock states the rule and why it is asymmetric. An empty
     * set — an installation with no scout year on record at all — answers
     * `identified` for anyone who is not a super-admin, exactly as a year
     * holding no member of theirs already did.
     */
    public function resolveAcrossYears(string $email, AuthorizationYears $years): string
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        if ($this->isSuperAdmin($blindIndex)) {
            return 'superadmin';
        }

        $highestLevel = Role::IDENTIFIED->level();

        foreach ($years->ids() as $scoutYearId) {
            $level = $this->highestFunctionLevel($blindIndex, $scoutYearId);

            // A role obtained outside the public year counts only from
            // `intendant` up. Below it, the person keeps what the public
            // year gives them — which for an animé enrolled next year only
            // is the `identified` floor, and an empty space until the site
            // switches over.
            if (!$years->isPublicYear($scoutYearId) && $level < Role::INTENDANT->level()) {
                continue;
            }

            $highestLevel = max($highestLevel, $level);
        }

        return self::roleForLevel($highestLevel);
    }

    /**
     * The highest role level $blindIndex reaches through its linked
     * members in ONE scout year, floored at `identified`. The primitive
     * both public shapes are built on.
     */
    private function highestFunctionLevel(string $blindIndex, int $scoutYearId): int
    {
        // Find member years — Desk-imported address, plus any member
        // reachable only through a valid secondary email.
        $memberYears = $this->findAllMatchingMemberYears($blindIndex, $scoutYearId);

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

        return $highestLevel;
    }

    private static function roleForLevel(int $level): string
    {
        foreach (Role::cases() as $role) {
            if ($role->level() === $level) {
                return $role->value;
            }
        }

        return 'identified';
    }

    private function isSuperAdmin(string $blindIndex): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_super_admin FROM user_accounts WHERE email_blind_index = ?'
        );
        $stmt->execute([$blindIndex]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $userRow !== false && (bool) $userRow['is_super_admin'];
    }

    /**
     * Get all member_year IDs linked to an email for one scout year.
     *
     * **Single-year on purpose, and there is no set shape of this one.**
     * It answers which member records this address reaches, which is a
     * question about ROWS rather than about a person: who may open a
     * screen and what is listed on it are two different questions, and
     * only the first one is what the year set exists for. Answering it
     * over two years would put a member of the year that is ending and a
     * member of the year being prepared side by side in the same list —
     * two rows for the same child in the "ses membres" navigation, and a
     * picker offering a section that no longer exists.
     *
     * Callers pass the year the person is actually SERVED (their
     * effective year), which under the transition rules is the one year
     * where they really have members. `Core\Http\Controller\AuthController`
     * does exactly that.
     *
     * @return int[]
     */
    public function getLinkedMemberYears(string $email, int $currentScoutYearId): array
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');
        $memberYears = $this->findAllMatchingMemberYears($blindIndex, $currentScoutYearId);

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
     *
     * This is also where a deactivated account is refused, and it is the
     * only place it needs to be: the five ways into the site all come
     * through here — AuthController::verifyMagicLink(), pollMagicLink(),
     * loginWithPassword() and passkeyVerify() via isMemberAuthorized(),
     * and SessionRevalidator::revalidate() on every request of a session
     * already open.
     *
     * The is_active check comes BEFORE the super-admin shortcut, and the
     * order is the whole point: the other way round, `is_super_admin` would
     * return true first and a deactivated super admin — exactly the account
     * an operator most wants to be able to shut out — would keep logging
     * in.
     */
    public function isEmailAuthorizedToLogin(string $email, int $currentScoutYearId): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        $stmt = $this->pdo->prepare('SELECT is_super_admin, is_active FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($userRow !== false && !(bool) $userRow['is_active']) {
            return false;
        }

        if ($userRow !== false && (bool) $userRow['is_super_admin']) {
            return true;
        }

        return count($this->findAllMatchingMemberYears($blindIndex, $currentScoutYearId)) > 0;
    }

    /**
     * The same gate, over every year an access decision may use.
     *
     * **No threshold here, deliberately, unlike resolveAcrossYears().**
     * The threshold decides how much someone may do; this decides whether
     * they may sign in at all, and the two are different questions. An
     * animé enrolled for the year being prepared and for no other is a
     * member of the unit — they sign in, and their space stays empty until
     * the site switches over. Refusing them instead would mean telling a
     * family already enrolled that the site does not know them.
     *
     * The deactivated-account and super-admin checks keep the order they
     * have in isEmailAuthorizedToLogin(), for the reason written there:
     * the other way round, a deactivated super-admin — the account an
     * operator most wants to shut out — would keep signing in.
     */
    public function isEmailAuthorizedToLoginAcrossYears(string $email, AuthorizationYears $years): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        $stmt = $this->pdo->prepare('SELECT is_super_admin, is_active FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($userRow !== false && !(bool) $userRow['is_active']) {
            return false;
        }

        if ($userRow !== false && (bool) $userRow['is_super_admin']) {
            return true;
        }

        foreach ($years->ids() as $scoutYearId) {
            if (count($this->findAllMatchingMemberYears($blindIndex, $scoutYearId)) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Desk-imported match (member_years.email_blind_index, as
     * always — minus any member whose Desk address was itself
     * unsubscribed) unioned with every member reachable only through a
     * currently-'valid' secondary email — deduplicated by member_year id,
     * since a member could in principle match both paths (their Desk
     * email re-added as their own secondary address).
     *
     * @return array<int, array<string, mixed>>
     */
    private function findAllMatchingMemberYears(string $blindIndex, int $currentScoutYearId): array
    {
        $direct = $this->memberYearRepo->findAllByEmail($blindIndex, $currentScoutYearId);

        if ($this->memberEmailRepo === null) {
            return $direct;
        }

        $direct = array_values(array_filter(
            $direct,
            fn(array $row) => !$this->memberEmailRepo->isBlindIndexInactiveForMember(
                (int) $row['member_id'],
                $blindIndex
            )
        ));

        $memberIds = $this->memberEmailRepo->findMemberIdsByValidBlindIndex($blindIndex);
        $viaSecondary = $this->memberYearRepo->findAllByMemberIds($memberIds, $currentScoutYearId);

        $merged = [];
        foreach ([...$direct, ...$viaSecondary] as $row) {
            $merged[(int) $row['id']] = $row;
        }

        return array_values($merged);
    }
}
