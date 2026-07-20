<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Repository;

use Core\Security\EncryptionService;

class ProviderRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return array<int, array{id: int, name: string, driver: string, api_endpoint: string, is_active: bool, created_at: string, updated_at: string}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, driver, api_endpoint, is_active, created_at, updated_at FROM llm_providers ORDER BY name');
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'driver' => (string) $row['driver'],
                'api_endpoint' => (string) $row['api_endpoint'],
                'is_active' => (bool) $row['is_active'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $rows;
    }

    /**
     * Find a provider by ID, including decrypted API key.
     *
     * @return array{id: int, name: string, driver: string, api_endpoint: string, api_key: string, is_active: bool, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'driver' => (string) $row['driver'],
            'api_endpoint' => (string) $row['api_endpoint'],
            'api_key' => $this->encryption->decrypt($row['api_key']),
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Find the first active provider (with decrypted API key).
     *
     * @return array{id: int, name: string, driver: string, api_endpoint: string, api_key: string}|null
     */
    public function findFirstActive(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_providers WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'driver' => (string) $row['driver'],
            'api_endpoint' => (string) $row['api_endpoint'],
            'api_key' => $this->encryption->decrypt($row['api_key']),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, driver: string, api_endpoint: string, api_key: string, is_active: bool}>
     */
    public function findAllActive(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_providers WHERE is_active = 1 ORDER BY id ASC');
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'driver' => (string) $row['driver'],
                'api_endpoint' => (string) $row['api_endpoint'],
                'api_key' => $this->encryption->decrypt($row['api_key']),
                'is_active' => true,
            ];
        }
        return $rows;
    }

    public function create(string $name, string $driver, string $apiEndpoint, string $apiKey, bool $isActive = true): int
    {
        $now = date('Y-m-d H:i:s');
        $encryptedKey = $this->encryption->encrypt($apiKey);

        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_providers (name, driver, api_endpoint, api_key, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $driver, $apiEndpoint, $encryptedKey, $isActive ? 1 : 0, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $driver, string $apiEndpoint, ?string $apiKey, bool $isActive): void
    {
        $now = date('Y-m-d H:i:s');

        if ($apiKey !== null) {
            $encryptedKey = $this->encryption->encrypt($apiKey);
            $stmt = $this->pdo->prepare(
                'UPDATE llm_providers SET name = ?, driver = ?, api_endpoint = ?, api_key = ?, is_active = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$name, $driver, $apiEndpoint, $encryptedKey, $isActive ? 1 : 0, $now, $id]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE llm_providers SET name = ?, driver = ?, api_endpoint = ?, is_active = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$name, $driver, $apiEndpoint, $isActive ? 1 : 0, $now, $id]);
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_providers WHERE id = ?');
        $stmt->execute([$id]);
    }
}
