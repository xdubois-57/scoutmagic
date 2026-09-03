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

    public function insert(
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, '
                . 'description, validation_regex, select_options, editable, sort_order)
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
     * Self-heal default_value on an existing row — the register() call
     * site is the single source of truth for what a setting's declared
     * default is, and Core\Maintenance\Task\ResetSettingsHandler trusts
     * this column blindly. Without this, every setting registered before
     * this column existed (or whose declared default later changed) would
     * silently reset to NULL/empty instead of its real default the first
     * time "Paramètres par défaut" runs. SettingService::register() only
     * calls this when the stored default actually differs.
     */
    public function updateDefaultValue(?string $moduleId, string $key, string $defaultValue): void
    {
        if ($moduleId === null) {
            $stmt = $this->pdo->prepare('UPDATE settings SET default_value = ? WHERE module_id IS NULL AND '
                . 'setting_key = ?');
            $stmt->execute([$defaultValue, $key]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE settings SET default_value = ? WHERE module_id = ? AND setting_key = '
                . '?');
            $stmt->execute([$defaultValue, $moduleId, $key]);
        }
    }

    /**
     * Resets every setting's value back to its declared default — module
     * spec "Paramètres par défaut" (Core\Maintenance\Task\
     * ResetSettingsHandler). A single UPDATE covering every row (core and
     * every module), not filtered by editable — internal bookkeeping
     * settings (e.g. cron_last_run) tolerate their default value fine, and
     * the spec doesn't carve out an exception for them.
     */
    public function resetAllToDefaults(): void
    {
        $this->pdo->exec('UPDATE settings SET setting_value = default_value');
    }

    /**
     * Deletes named CORE settings rows (`module_id IS NULL`) outright.
     *
     * Core settings have no pruning mechanism at all: the one that exists
     * (deleteUndeclaredEditable() above, driven by
     * Core\Module\ModuleManager) is scoped to a module_id, and nothing
     * anywhere removes a `module_id IS NULL` row that stopped being
     * registered by the composition root. So a core setting that is
     * removed from public/index.php lives on forever in every installed
     * site's table — and, when it was editable, keeps rendering as a row
     * on Configuration > Réglages that no longer does anything.
     *
     * This is the deliberate, named counterpart: the caller lists exactly
     * the keys it is retiring, in a one-time guarded block (the
     * `scheduler_chain_settings_pruned` cleanup in public/index.php is the
     * first). Never call it with a list built by difference against what
     * is currently registered — the registration order in the composition
     * root is not a manifest, and half of it runs behind module-enabled
     * conditions.
     *
     * Returns the number of rows actually removed.
     *
     * @param string[] $keys
     */
    public function deleteCoreSettings(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM settings WHERE module_id IS NULL AND setting_key IN ({$placeholders})"
        );
        $stmt->execute(array_values($keys));

        return $stmt->rowCount();
    }

    /**
     * Deletes a module's `settings` rows that its manifest no longer
     * declares — the rows a removed setting leaves behind. Without this, a
     * setting dropped from a module.json stayed in the table forever and
     * kept rendering as an editable row on Configuration > Réglages, doing
     * nothing: the very confusion that dropping it was meant to end (the
     * calendar module's `event_reminder_hour` is the case that surfaced it).
     *
     * **Only editable rows**, and that restriction is what makes this safe:
     * every setting a module registers at RUNTIME rather than from its
     * manifest — finance's `..._seeded`/`..._running` bookkeeping flags,
     * and any future one — is registered with `editable = false` precisely
     * because it must never show up as a row someone can hand-edit. A
     * non-editable row is therefore never a candidate here, so an internal
     * flag can never be deleted for the crime of not being in the manifest.
     * Keep that contract: a runtime-registered module setting is always
     * non-editable.
     *
     * Returns the number of rows deleted. An empty $declaredKeys is a
     * no-op rather than "delete everything" — a manifest with no settings
     * at all is far more likely to be a caller mistake than a deliberate
     * "clear this module's settings".
     *
     * @param string[] $declaredKeys the keys the manifest declares
     */
    public function deleteUndeclaredEditable(string $moduleId, array $declaredKeys): int
    {
        if ($declaredKeys === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($declaredKeys), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM settings
             WHERE module_id = ? AND editable = 1 AND setting_key NOT IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$moduleId], array_values($declaredKeys)));

        return $stmt->rowCount();
    }

    /**
     * Conditional write: set the value only while the setting is still
     * empty, and report whether THIS call is the one that wrote it.
     *
     * Needed for lazily-generated, write-once values (Core\Statistics\
     * InstallationIdentityService's installation id): two concurrent
     * requests both finding the setting empty would otherwise both write,
     * and the installation would change identity depending on which UPDATE
     * landed last. A single `WHERE ... AND (setting_value IS NULL OR
     * setting_value = '')` statement makes the claim atomic — the loser
     * gets rowCount() 0 and adopts the winner's value on re-read.
     *
     * Returns false when the row doesn't exist at all, which the caller
     * must treat as a wiring error rather than as "already claimed".
     */
    public function claimIfEmpty(?string $moduleId, string $key, string $value): bool
    {
        if ($moduleId === null) {
            $stmt = $this->pdo->prepare(
                "UPDATE settings SET setting_value = ? WHERE module_id IS NULL AND setting_key = ? AND (setting_value "
                    . "IS NULL OR setting_value = '')"
            );
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE settings SET setting_value = ? WHERE module_id = ? AND setting_key = ? AND (setting_value IS "
                    . "NULL OR setting_value = '')"
            );
            $stmt->execute([$value, $moduleId, $key]);
        }

        return $stmt->rowCount() === 1;
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
     * @return array<
     *     string,
     *     array{label: string, icon: string|null, description: string|null, settings: array<int, array<string, mixed>>}
     * >
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
