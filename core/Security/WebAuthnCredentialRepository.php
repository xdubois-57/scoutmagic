<?php

declare(strict_types=1);

namespace Core\Security;

class WebAuthnCredentialRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM webauthn_credentials WHERE credential_id = ?'
        );
        $stmt->execute([$credentialId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByUserAccountId(int $userAccountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM webauthn_credentials WHERE user_account_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userAccountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(int $userAccountId, string $credentialId, string $publicKey, string $deviceLabel): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO webauthn_credentials (user_account_id, credential_id, public_key, device_label)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userAccountId, $credentialId, $publicKey, $deviceLabel]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateSignCount(int $id, int $signCount): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webauthn_credentials SET sign_count = ? WHERE id = ?'
        );
        $stmt->execute([$signCount, $id]);
    }

    public function updateLastUsed(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE webauthn_credentials SET last_used_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM webauthn_credentials WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function countByUserAccountId(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM webauthn_credentials WHERE user_account_id = ?'
        );
        $stmt->execute([$userAccountId]);
        return (int) $stmt->fetchColumn();
    }
}
