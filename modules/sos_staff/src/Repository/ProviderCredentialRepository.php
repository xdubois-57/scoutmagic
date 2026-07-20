<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

use Core\Security\EncryptionService;

class ProviderCredentialRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findActive(): ?ProviderCredential
    {
        $stmt = $this->pdo->query('SELECT * FROM sos_provider_credentials WHERE is_active = 1 LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByProvider(string $provider): ?ProviderCredential
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sos_provider_credentials WHERE provider = ?');
        $stmt->execute([$provider]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return ProviderCredential[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sos_provider_credentials ORDER BY provider ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    /**
     * Create or fully replace a provider's config. Never changes is_active
     * — see setActive().
     *
     * @param array<string, mixed> $config
     */
    public function save(string $provider, array $config): void
    {
        $encrypted = $this->encryption->encrypt((string) json_encode($config));

        if ($this->findByProvider($provider) === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sos_provider_credentials (provider, is_active, config_encrypted) VALUES (?, 0, ?)'
            );
            $stmt->execute([$provider, $encrypted]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE sos_provider_credentials SET config_encrypted = ?, updated_at = CURRENT_TIMESTAMP WHERE provider = ?'
        );
        $stmt->execute([$encrypted, $provider]);
    }

    /**
     * Mark $provider as the single active provider, deactivating every
     * other row — enforced here (module spec §1.1/§7: only one
     * provider/number active at a time), not by a DB constraint (a
     * partial unique index on is_active isn't portable to the SQLite test
     * database).
     */
    public function setActive(string $provider): void
    {
        $this->pdo->prepare('UPDATE sos_provider_credentials SET is_active = 0')->execute();
        $stmt = $this->pdo->prepare('UPDATE sos_provider_credentials SET is_active = 1 WHERE provider = ?');
        $stmt->execute([$provider]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProviderCredential
    {
        $decrypted = $this->encryption->decrypt($row['config_encrypted']);
        $config = json_decode($decrypted, true);

        return new ProviderCredential(
            provider: (string) $row['provider'],
            isActive: (bool) $row['is_active'],
            config: is_array($config) ? $config : []
        );
    }
}
