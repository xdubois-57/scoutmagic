<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

class UserAccountRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Find a user account by email.
     * Uses blind index for lookup, decrypts email to verify exact match.
     */
    public function findByEmail(string $email): ?UserAccount
    {
        $blindIndex = $this->encryption->blindIndex(strtolower($email), 'email');

        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_accounts WHERE email_blind_index = ?'
        );
        $stmt->execute([$blindIndex]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted'], 'user_accounts.email');

        // Verify exact match (blind index collisions are theoretically possible)
        if (strtolower($decryptedEmail) !== strtolower($email)) {
            return null;
        }

        return $this->hydrate($row, $decryptedEmail);
    }

    /**
     * Find a user account by ID.
     */
    public function findById(int $id): ?UserAccount
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_accounts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted'], 'user_accounts.email');

        return $this->hydrate($row, $decryptedEmail);
    }

    /**
     * First and last name for a batch of account ids, in one query.
     *
     * Exists so a page listing many accounts (a group feed signs every
     * post with its author's real name) resolves them all at once instead
     * of one findById() per row. Returns only the two name fields — never
     * the email — and decrypts here, inside the Repository, like every
     * other encrypted read (SECURITY.md §5). An id with no account is
     * simply absent from the result.
     *
     * @param int[] $ids
     * @return array<int, array{first_name: ?string, last_name: ?string}>
     */
    public function findNamesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, first_name_encrypted, last_name_encrypted FROM user_accounts WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = [
                'first_name' => $row['first_name_encrypted'] !== null
                    ? $this->encryption->decrypt($row['first_name_encrypted'], 'user_accounts.first_name') : null,
                'last_name' => $row['last_name_encrypted'] !== null
                    ? $this->encryption->decrypt($row['last_name_encrypted'], 'user_accounts.last_name') : null,
            ];
        }

        return $names;
    }

    /**
     * The email blind index of each of these accounts — the batched twin
     * of findByBlindIndex() read the other way round, and the join key
     * between an account and the member_years rows that share its address
     * (Core\Import\MemberYearRepository::findMemberIdsByEmailBlindIndexes()).
     *
     * A blind index, never an address: nothing on this path decrypts an
     * email, exactly as Core\Security\RoleResolver and
     * Modules\Groups\Service\GroupRecipientResolver already avoid doing.
     * Batched because the caller has a whole page of authors at once and
     * one query per author is the N+1 those callers exist to avoid.
     *
     * @param int[] $ids
     * @return array<int, string> account id => email blind index
     */
    public function findEmailBlindIndexesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, email_blind_index FROM user_accounts WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $indexes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $indexes[(int) $row['id']] = (string) $row['email_blind_index'];
        }

        return $indexes;
    }

    /**
     * The account behind each of these blind indexes — the batched twin of
     * findByBlindIndex(), for a caller holding a set of them rather than
     * one. Ids only: a caller that also needs the names asks
     * findNamesByIds() for exactly the ids this returned.
     *
     * @param string[] $blindIndexes
     * @return array<string, int> blind index => account id
     */
    public function findIdsByBlindIndexes(array $blindIndexes): array
    {
        $blindIndexes = array_values(array_unique(array_filter($blindIndexes, static fn(string $i): bool => $i !== '')));
        if ($blindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($blindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, email_blind_index FROM user_accounts WHERE email_blind_index IN ({$placeholders})"
        );
        $stmt->execute($blindIndexes);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids[(string) $row['email_blind_index']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Find the first super-admin account (by id), for system-generated
     * alerts that need a human to notify but have no more specific
     * recipient (e.g. a scheduled task failure — see Core\Scheduler\TaskContext).
     */
    /**
     * Every account id — the broad candidate list for a notification
     * type whose audience is "every identified member" (e.g. a new
     * calendar event). dispatch() itself re-checks each recipient's
     * current role against the type's role_min, so passing every account
     * here is safe: recipients below the floor are silently dropped.
     *
     * @return int[]
     */
    public function findAllIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM user_accounts ORDER BY id ASC');
        \assert($stmt !== false);

        return array_map(static fn(array $row) => (int) $row['id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findFirstSuperAdmin(): ?UserAccount
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM user_accounts WHERE is_super_admin = 1 ORDER BY id ASC LIMIT 1'
        );
        $row = $stmt !== false ? $stmt->fetch() : false;

        if ($row === false) {
            return null;
        }

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted'], 'user_accounts.email');

        return $this->hydrate($row, $decryptedEmail);
    }

    /**
     * Find a user account by email blind index.
     */
    public function findByBlindIndex(string $blindIndex): ?UserAccount
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_accounts WHERE email_blind_index = ?'
        );
        $stmt->execute([$blindIndex]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted'], 'user_accounts.email');

        return $this->hydrate($row, $decryptedEmail);
    }

    /**
     * Create a new user account. Encrypts email, computes blind index.
     * Returns the created account with its ID.
     */
    public function create(string $email, bool $isSuperAdmin = false): UserAccount
    {
        $normalizedEmail = strtolower($email);
        $encryptedEmail = $this->encryption->encrypt($normalizedEmail, 'user_accounts.email');
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, ?)'
        );
        $stmt->execute([$encryptedEmail, $blindIndex, $isSuperAdmin ? 1 : 0]);

        $id = (int) $this->pdo->lastInsertId();

        return new UserAccount(
            id: $id,
            email: $normalizedEmail,
            firstName: null,
            lastName: null,
            passwordHash: null,
            isSuperAdmin: $isSuperAdmin,
            lastLoginAt: null
        );
    }

    /**
     * Update last_login_at for the given user.
     */
    public function updateLastLogin(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE user_accounts SET last_login_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $id]);
    }

    /**
     * Update profile (first name and last name), encrypted at rest.
     */
    public function updateProfile(int $id, ?string $firstName, ?string $lastName): void
    {
        $encFirstName = $firstName !== null ? $this->encryption->encrypt($firstName, 'user_accounts.first_name') : null;
        $encLastName = $lastName !== null ? $this->encryption->encrypt($lastName, 'user_accounts.last_name') : null;

        $stmt = $this->pdo->prepare(
            'UPDATE user_accounts SET first_name_encrypted = ?, last_name_encrypted = ? WHERE id = ?'
        );
        $stmt->execute([$encFirstName, $encLastName, $id]);
    }

    /**
     * Update the password hash for a user — always also stamps
     * password_changed_at (initial set, account-page change, or a
     * password-reset link all count), shown on the account page.
     *
     * The same write also bumps sessions_valid_from, which revokes every
     * session already issued for this account (Core\Security\
     * SessionRevalidator). Changing a password has to end the sessions an
     * attacker may already be sitting in, otherwise a reset "recovers" an
     * account that stays compromised. The caller doing a self-service
     * change re-stamps its OWN session afterwards (AuthSession::
     * refreshIssuedAt()) so the member isn't logged out of the tab they
     * just used; a reset link deliberately doesn't, and ends every session
     * including the one that requested it.
     *
     * Stamped from PHP rather than CURRENT_TIMESTAMP so it is directly
     * comparable to the PHP-side login timestamp held in the session, and
     * so it works identically on the SQLite test database (same convention
     * as Core\Security\MagicLinkRepository).
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE user_accounts SET password_hash = ?, password_changed_at = ?, sessions_valid_from = ? WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $now, $now, $id]);
    }

    /**
     * Check if a user has a password set.
     */
    public function hasPassword(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT password_hash FROM user_accounts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false && $row['password_hash'] !== null;
    }

    /**
     * Hydrate a UserAccount from a database row.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, string $decryptedEmail): UserAccount
    {
        $firstName = null;
        if (!empty($row['first_name_encrypted'])) {
            $firstName = $this->encryption->decrypt($row['first_name_encrypted'], 'user_accounts.first_name');
        }

        $lastName = null;
        if (!empty($row['last_name_encrypted'])) {
            $lastName = $this->encryption->decrypt($row['last_name_encrypted'], 'user_accounts.last_name');
        }

        $lastLoginAt = null;
        if (!empty($row['last_login_at'])) {
            $lastLoginAt = new \DateTimeImmutable($row['last_login_at']);
        }

        $passwordChangedAt = null;
        if (!empty($row['password_changed_at'])) {
            $passwordChangedAt = new \DateTimeImmutable($row['password_changed_at']);
        }

        $sessionsValidFrom = null;
        if (!empty($row['sessions_valid_from'])) {
            $sessionsValidFrom = new \DateTimeImmutable($row['sessions_valid_from']);
        }

        return new UserAccount(
            id: (int) $row['id'],
            email: $decryptedEmail,
            firstName: $firstName,
            lastName: $lastName,
            passwordHash: $row['password_hash'] ?? null,
            isSuperAdmin: (bool) $row['is_super_admin'],
            lastLoginAt: $lastLoginAt,
            passwordChangedAt: $passwordChangedAt,
            sessionsValidFrom: $sessionsValidFrom,
            quietHoursStart: $row['quiet_hours_start'] ?? null,
            quietHoursEnd: $row['quiet_hours_end'] ?? null,
            notificationDiscretion: (bool) ($row['notification_discretion'] ?? false)
        );
    }

    /**
     * Updates the account-level Web Push overrides (Configuration >
     * Notifications preferences page, "Mon compte") —
     * Core\Notification\NotificationService reads these back to compute a
     * push's effective quiet-hours window and whether to substitute a
     * generic title/body (discretion mode). $quietHoursStart/$quietHoursEnd
     * are both null together ("follow the global default") or both set
     * ("HH:MM" strings) — never independently null, enforced by the
     * caller (Core\Http\Controller\AccountController).
     */
    public function updateNotificationSettings(int $id, ?string $quietHoursStart, ?string $quietHoursEnd, bool $discretion): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_accounts SET quiet_hours_start = ?, quiet_hours_end = ?, notification_discretion = ? WHERE id = ?'
        );
        $stmt->execute([$quietHoursStart, $quietHoursEnd, $discretion ? 1 : 0, $id]);
    }
}
