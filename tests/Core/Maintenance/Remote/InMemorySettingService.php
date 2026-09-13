<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Config\SettingService;

/**
 * The settings table, in an array.
 *
 * `SettingService` is final-ish in spirit but not in fact, and what these
 * tests need from it is two methods over a map. A real one would drag in
 * a repository and a database for rows whose whole content is under this
 * test's control anyway.
 *
 * **Not `final`**: one test needs a table where a single named row
 * refuses to be written — a blanket `refuseWrites` would fail the
 * connection's own writes long before the line under test was reached —
 * and a subclass is the cheapest way to say that without a flag here for
 * every future variation ({@see RefusingSettingService}).
 *
 * **In its own file, named after the class**, so that Composer's PSR-4
 * autoloader finds it. It began at the foot of `GoogleDriveTargetTest`,
 * which worked only as long as PHPUnit happened to have loaded that file
 * first: a second test class using it passed or fatally errored
 * depending on the order the suite ran in, and static analysis could not
 * see it at all.
 */
class InMemorySettingService extends SettingService
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
        parent::__construct(new \Core\Config\SettingRepository(new \PDO('sqlite::memory:')));
    }

    /**
     * Registration seeds the default without a database — and never
     * overwrites a value the test has already set, which is exactly what
     * the real one does for a key that already has a row.
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
        $this->values[$key] ??= $defaultValue;
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Makes every write fail, for the tests that need something to go
     * wrong AFTER the network part has already succeeded.
     */
    public bool $refuseWrites = false;

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        if ($this->refuseWrites) {
            throw new \RuntimeException('the settings table is unavailable');
        }

        $this->values[$key] = $value;
    }
}
