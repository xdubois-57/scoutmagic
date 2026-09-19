<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;

/**
 * The settings table, in an array.
 *
 * **Its own file rather than the foot of a test case**, which is the
 * fragility `InMemorySettingService` was moved out of for the same
 * reason: a class declared under a `TestCase` is only found when PHPUnit
 * happens to have loaded that file first, so running its neighbour alone
 * fails — and PHPStan never sees it at all.
 */
final class RemoteBackupSettingsDouble extends SettingService
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
        parent::__construct(new \Core\Config\SettingRepository(new \PDO('sqlite::memory:')));
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        $this->values[$key] = $value;
    }
}
