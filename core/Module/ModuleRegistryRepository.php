<?php

declare(strict_types=1);

namespace Core\Module;

class ModuleRegistryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, array{id: int, module_id: string, enabled: bool, installed_version: string, enabled_at: ?string, enabled_by: ?int}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM module_registry ORDER BY module_id');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    /**
     * @return array{id: int, module_id: string, enabled: bool, installed_version: string, enabled_at: ?string, enabled_by: ?int}|null
     */
    public function findByModuleId(string $moduleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM module_registry WHERE module_id = ?');
        $stmt->execute([$moduleId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function upsert(string $moduleId, bool $enabled, string $version, ?int $userId): void
    {
        $existing = $this->findByModuleId($moduleId);

        if ($existing === null) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO module_registry (module_id, enabled, installed_version, enabled_at, enabled_by) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$moduleId, $enabled ? 1 : 0, $version, $enabled ? $now : null, $userId]);
        } else {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'UPDATE module_registry SET enabled = ?, installed_version = ?, enabled_at = ?, enabled_by = ? WHERE module_id = ?'
            );
            $stmt->execute([$enabled ? 1 : 0, $version, $enabled ? $now : null, $userId, $moduleId]);
        }
    }

    public function setEnabled(string $moduleId, bool $enabled): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE module_registry SET enabled = ?, enabled_at = ? WHERE module_id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $enabled ? $now : null, $moduleId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, module_id: string, enabled: bool, installed_version: string, enabled_at: ?string, enabled_by: ?int}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'module_id' => (string) $row['module_id'],
            'enabled' => (bool) $row['enabled'],
            'installed_version' => (string) $row['installed_version'],
            'enabled_at' => $row['enabled_at'] !== null ? (string) $row['enabled_at'] : null,
            'enabled_by' => $row['enabled_by'] !== null ? (int) $row['enabled_by'] : null,
        ];
    }
}
