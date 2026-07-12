<?php

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
        $blindIndex = $this->encryption->blindIndex(strtolower($email));

        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_accounts WHERE email_blind_index = ?'
        );
        $stmt->execute([$blindIndex]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted']);

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

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted']);

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

        $decryptedEmail = $this->encryption->decrypt($row['email_encrypted']);

        return $this->hydrate($row, $decryptedEmail);
    }

    /**
     * Create a new user account. Encrypts email, computes blind index.
     * Returns the created account with its ID.
     */
    public function create(string $email, bool $isSuperAdmin = false): UserAccount
    {
        $normalizedEmail = strtolower($email);
        $encryptedEmail = $this->encryption->encrypt($normalizedEmail);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

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
        $stmt = $this->pdo->prepare(
            'UPDATE user_accounts SET last_login_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
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
            $firstName = $this->encryption->decrypt($row['first_name_encrypted']);
        }

        $lastName = null;
        if (!empty($row['last_name_encrypted'])) {
            $lastName = $this->encryption->decrypt($row['last_name_encrypted']);
        }

        $lastLoginAt = null;
        if (!empty($row['last_login_at'])) {
            $lastLoginAt = new \DateTimeImmutable($row['last_login_at']);
        }

        return new UserAccount(
            id: (int) $row['id'],
            email: $decryptedEmail,
            firstName: $firstName,
            lastName: $lastName,
            passwordHash: $row['password_hash'] ?? null,
            isSuperAdmin: (bool) $row['is_super_admin'],
            lastLoginAt: $lastLoginAt
        );
    }
}
