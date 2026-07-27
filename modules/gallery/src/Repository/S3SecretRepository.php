<?php

declare(strict_types=1);

namespace Modules\Gallery\Repository;

use Core\Security\EncryptionService;

/**
 * Singleton row (id=1) holding the S3 secret access key, encrypted at rest
 * — same convention as Modules\LlmConnector\Repository\ProviderRepository's
 * api_key column. Kept out of the generic settings system, which stores
 * plain-text values.
 */
class S3SecretRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function get(): ?string
    {
        $stmt = $this->pdo->query('SELECT secret_key_encrypted FROM gallery_s3_secret WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        if ($row === false || $row['secret_key_encrypted'] === null) {
            return null;
        }

        $encrypted = $row['secret_key_encrypted'];
        if (is_resource($encrypted)) {
            $encrypted = stream_get_contents($encrypted);
        }

        return $this->encryption->decrypt((string) $encrypted);
    }

    public function set(string $secretKey): void
    {
        $encrypted = $this->encryption->encrypt($secretKey);
        $now = date('Y-m-d H:i:s');

        // Portable insert-or-update (no MySQL-only ON DUPLICATE KEY —
        // this repository is exercised against SQLite in tests too).
        $exists = (bool) $this->pdo->query('SELECT 1 FROM gallery_s3_secret WHERE id = 1')->fetchColumn();

        if ($exists) {
            $stmt = $this->pdo->prepare('UPDATE gallery_s3_secret SET secret_key_encrypted = ?, updated_at = ? WHERE id = 1');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO gallery_s3_secret (id, secret_key_encrypted, updated_at) VALUES (1, ?, ?)');
        }
        $stmt->execute([$encrypted, $now]);
    }

    public function clear(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM gallery_s3_secret WHERE id = 1');
        $stmt->execute();
    }
}
