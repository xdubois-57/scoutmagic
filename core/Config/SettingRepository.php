<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

class SettingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM settings ORDER BY module_id, sort_order, setting_key'
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByModuleAndKey(?string $moduleId, string $key): ?array
    {
        if ($moduleId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM settings WHERE module_id IS NULL AND setting_key = ?'
            );
            $stmt->execute([$key]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM settings WHERE module_id = ? AND setting_key = ?'
            );
            $stmt->execute([$moduleId, $key]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(
        ?string $moduleId,
        string $key,
        string $value,
        string $type,
        string $label,
        string $description,
        ?string $regex,
        ?string $selectOptions,
        bool $editable,
        int $sortOrder
    ): void {
        $existing = $this->findByModuleAndKey($moduleId, $key);
        if ($existing !== null) {
            // Self-heal default_value on every boot even for a row that
            // already exists — the register() call site is the single
            // source of truth for what a setting's declared default is, and
            // Core\Maintenance\Task\ResetSettingsHandler trusts this column
            // blindly. Without this, every setting registered before this
            // column existed (or whose declared default later changed)
            // would silently reset to NULL/empty instead of its real
            // default the first time "Paramètres par défaut" runs.
            if ($moduleId === null) {
                $stmt = $this->pdo->prepare('UPDATE settings SET default_value = ? WHERE module_id IS NULL AND setting_key = ?');
                $stmt->execute([$value, $key]);
            } else {
                $stmt = $this->pdo->prepare('UPDATE settings SET default_value = ? WHERE module_id = ? AND setting_key = ?');
                $stmt->execute([$value, $moduleId, $key]);
            }
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, validation_regex, select_options, editable, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $moduleId,
            $key,
            $value,
            $value,
            $type,
            $label,
            $description,
            $regex,
            $selectOptions,
            $editable ? 1 : 0,
            $sortOrder,
        ]);
    }

    /**
     * Resets every setting's value back to its declared default — module
     * spec "Paramètres par défaut" (Core\Maintenance\Task\
     * ResetSettingsHandler). A single UPDATE covering every row (core and
     * every module), not filtered by editable — internal bookkeeping
     * settings (e.g. scheduler_last_run) tolerate their default value fine,
     * and the spec doesn't carve out an exception for them.
     */
    public function resetAllToDefaults(): void
    {
        $this->pdo->exec('UPDATE settings SET setting_value = default_value');
    }

    public function updateValue(?string $moduleId, string $key, string $value): void
    {
        if ($moduleId === null) {
            $stmt = $this->pdo->prepare(
                'UPDATE settings SET setting_value = ? WHERE module_id IS NULL AND setting_key = ?'
            );
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE settings SET setting_value = ? WHERE module_id = ? AND setting_key = ?'
            );
            $stmt->execute([$value, $moduleId, $key]);
        }
    }

    /**
     * @return array<string, array{label: string, icon: string|null, description: string|null, settings: array<int, array<string, mixed>>}>
     */
    public function findAllGrouped(): array
    {
        $all = $this->findAll();
        $groups = [];

        foreach ($all as $row) {
            $groupId = $row['module_id'] ?? 'core';
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'label' => $groupId === 'core' ? 'Paramètres généraux' : ucfirst($groupId),
                    'icon' => $groupId === 'core' ? 'bi-gear' : 'bi-puzzle',
                    'description' => null,
                    'settings' => [],
                ];
            }
            $groups[$groupId]['settings'][] = $row;
        }

        return $groups;
    }
}
