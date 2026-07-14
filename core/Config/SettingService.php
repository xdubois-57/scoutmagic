<?php

declare(strict_types=1);

namespace Core\Config;

class SettingService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private SettingRepository $repository)
    {
    }

    /**
     * Get a setting value.
     */
    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        $this->loadCache();
        $cacheKey = ($moduleId ?? '_core_') . '::' . $key;
        return $this->cache[$cacheKey] ?? $default;
    }

    /**
     * Set a setting value. Only works if the setting is editable.
     *
     * @throws SettingException
     */
    public function set(string $key, string $value, ?string $moduleId = null): void
    {
        $setting = $this->repository->findByModuleAndKey($moduleId, $key);
        if ($setting === null) {
            throw new SettingException("Setting '{$key}' not found.");
        }
        if (!(bool) $setting['editable']) {
            throw new SettingException("Setting '{$key}' is not editable.");
        }
        if (!$this->validateValue($value, $setting)) {
            throw new SettingException("Invalid value for setting '{$key}'.");
        }

        $this->repository->updateValue($moduleId, $key, $value);
        $this->clearCache();
    }

    /**
     * Set a setting value programmatically, bypassing the `editable` guard.
     *
     * For settings managed by the application itself (e.g. the active scout year,
     * scheduler bookkeeping) rather than hand-edited through the settings UI. The
     * value is still validated against the setting's type and regex.
     *
     * @throws SettingException
     */
    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        $setting = $this->repository->findByModuleAndKey($moduleId, $key);
        if ($setting === null) {
            throw new SettingException("Setting '{$key}' not found.");
        }
        if (!$this->validateValue($value, $setting)) {
            throw new SettingException("Invalid value for setting '{$key}'.");
        }

        $this->repository->updateValue($moduleId, $key, $value);
        $this->clearCache();
    }

    /**
     * Register a setting if it doesn't exist yet.
     *
     * @param array<int, string>|null $selectOptions
     */
    public function register(
        string $key,
        string $defaultValue,
        string $type,
        string $label,
        string $description,
        ?string $moduleId = null,
        ?string $validationRegex = null,
        ?array $selectOptions = null,
        bool $editable = true,
        int $sortOrder = 0
    ): void {
        $this->repository->upsert(
            $moduleId,
            $key,
            $defaultValue,
            $type,
            $label,
            $description,
            $validationRegex,
            $selectOptions !== null ? json_encode($selectOptions) : null,
            $editable,
            $sortOrder
        );
    }

    /**
     * Get all settings grouped by module_id.
     *
     * @return array<string, array{label: string, icon: string|null, description: string|null, settings: array<int, array<string, mixed>>}>
     */
    public function getAllGrouped(): array
    {
        return $this->repository->findAllGrouped();
    }

    /**
     * Validate a value against a setting's type and optional regex.
     */
    public function validate(string $key, string $value, ?string $moduleId = null): bool
    {
        $setting = $this->repository->findByModuleAndKey($moduleId, $key);
        if ($setting === null) {
            return false;
        }
        return $this->validateValue($value, $setting);
    }

    /**
     * Clear the in-memory cache.
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    private function loadCache(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = [];
        $all = $this->repository->findAll();
        foreach ($all as $row) {
            $cacheKey = ($row['module_id'] ?? '_core_') . '::' . $row['setting_key'];
            $this->cache[$cacheKey] = $row['setting_value'];
        }
    }

    /**
     * @param array<string, mixed> $setting
     */
    private function validateValue(string $value, array $setting): bool
    {
        $type = $setting['setting_type'] ?? 'text';

        $valid = match ($type) {
            'email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => $value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false,
            'number' => $value === '' || is_numeric($value),
            'boolean' => in_array($value, ['0', '1'], true),
            'select' => $this->validateSelect($value, $setting['select_options'] ?? null),
            'tel' => $value === '' || (bool) preg_match('/^[+\d\s\-().]+$/', $value),
            'date' => $value === '' || (bool) strtotime($value),
            'color' => $value === '' || (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value),
            default => true, // text, textarea
        };

        if (!$valid) {
            return false;
        }

        // Additional regex validation if present
        $regex = $setting['validation_regex'] ?? null;
        if ($regex !== null && $regex !== '' && $value !== '') {
            return (bool) preg_match('/' . $regex . '/', $value);
        }

        return true;
    }

    private function validateSelect(string $value, ?string $optionsJson): bool
    {
        if ($optionsJson === null) {
            return true;
        }
        $options = json_decode($optionsJson, true);
        if (!is_array($options)) {
            return true;
        }
        return in_array($value, $options, true);
    }
}
