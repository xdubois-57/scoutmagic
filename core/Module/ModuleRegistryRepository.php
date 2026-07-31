<?php

declare(strict_types=1);

namespace Core\Module;

class ModuleRegistryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, array{id: int, module_id: string, enabled: bool, installed_version: string, sort_order: int, enabled_at: ?string, enabled_by: ?int}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM module_registry ORDER BY sort_order, module_id');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    /**
     * @return array{id: int, module_id: string, enabled: bool, installed_version: string, sort_order: int, enabled_at: ?string, enabled_by: ?int}|null
     */
    public function findByModuleId(string $moduleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM module_registry WHERE module_id = ?');
        $stmt->execute([$moduleId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * A newly-created entry is appended after the current highest
     * sort_order (i.e. it lands at the end of the admin's reordered list
     * rather than jumping to the top via the column's own DEFAULT 0) — an
     * existing entry's sort_order is left untouched here, since reordering
     * is a separate, explicit action (see reorder()).
     */
    public function upsert(string $moduleId, bool $enabled, string $version, ?int $userId): void
    {
        $existing = $this->findByModuleId($moduleId);

        if ($existing === null) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $nextOrder = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM module_registry')->fetchColumn();
            $stmt = $this->pdo->prepare(
                'INSERT INTO module_registry (module_id, enabled, installed_version, sort_order, enabled_at, enabled_by) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$moduleId, $enabled ? 1 : 0, $version, $nextOrder, $enabled ? $now : null, $userId]);
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
     * Persist a new module display/menu order — every id in $orderedModuleIds
     * must already have a registry row (see ModuleManager::reorder(), which
     * lazily creates one for a module that was never toggled before letting
     * it be dragged).
     *
     * @param string[] $orderedModuleIds
     */
    public function reorder(array $orderedModuleIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE module_registry SET sort_order = ? WHERE module_id = ?');
        foreach (array_values($orderedModuleIds) as $position => $moduleId) {
            $stmt->execute([$position, $moduleId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, module_id: string, enabled: bool, installed_version: string, sort_order: int, enabled_at: ?string, enabled_by: ?int}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'module_id' => (string) $row['module_id'],
            'enabled' => (bool) $row['enabled'],
            'installed_version' => (string) $row['installed_version'],
            'sort_order' => (int) $row['sort_order'],
            'enabled_at' => $row['enabled_at'] !== null ? (string) $row['enabled_at'] : null,
            'enabled_by' => $row['enabled_by'] !== null ? (int) $row['enabled_by'] : null,
        ];
    }
}
