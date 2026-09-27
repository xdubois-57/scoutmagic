<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\SettingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A never-customised setting value follows its moved default (issue #355),
 * against the REAL engine — because the two things that decide it only
 * exist there:
 *
 * - `settings.setting_value` carries a case- and trailing-space-insensitive
 *   collation, so a plain `=` would call a URL typed in other capitals
 *   « never customised » and overwrite it. SQLite's `=` is binary and
 *   could never show it;
 * - MySQL and MariaDB evaluate a single-table UPDATE's assignments left to
 *   right, so the value's CASE must be assigned before `default_value` or
 *   it compares against the NEW default. SQLite gives every assignment the
 *   old row and would hide that too.
 *
 * The table is the one the real migration builds from schema/core.sql
 * (`Tests\UsesProductionEngine`), collation included, so it cannot drift
 * from what an installed site has — and the connection is the site's.
 */
#[Group('database')]
class SettingDefaultFollowsMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private SettingRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SettingRepository($this->productionEngine());
    }

    private function valueOf(?string $moduleId, string $key): mixed
    {
        return $this->repository->findByModuleAndKey($moduleId, $key)['setting_value'] ?? null;
    }

    public function testANeverCustomisedValueFollowsAndACustomisedOneStays(): void
    {
        $this->repository->insert('fees', 'untouched', 'https://old.example/p', 'url', 'L', 'D', null, null, true, 0);
        $this->repository->insert('fees', 'chosen', 'https://old.example/p', 'url', 'L', 'D', null, null, true, 0);
        $this->repository->updateValue('fees', 'chosen', 'https://unit.example/p');

        $this->repository->updateDefaultValue('fees', 'untouched', 'https://new.example/p');
        $this->repository->updateDefaultValue('fees', 'chosen', 'https://new.example/p');

        $this->assertSame('https://new.example/p', $this->valueOf('fees', 'untouched'));
        $this->assertSame('https://unit.example/p', $this->valueOf('fees', 'chosen'));
        $this->assertSame(
            'https://new.example/p',
            $this->repository->findByModuleAndKey('fees', 'chosen')['default_value'] ?? null
        );
    }

    public function testAValueDifferingOnlyByCaseOrTrailingSpaceIsCustomised(): void
    {
        $this->repository->insert(null, 'cased', 'https://old.example/p', 'url', 'L', 'D', null, null, true, 0);
        $this->repository->updateValue(null, 'cased', 'HTTPS://OLD.EXAMPLE/P');
        $this->repository->insert(null, 'padded', 'https://old.example/p', 'url', 'L', 'D', null, null, true, 0);
        $this->repository->updateValue(null, 'padded', 'https://old.example/p ');

        $this->repository->updateDefaultValue(null, 'cased', 'https://new.example/p');
        $this->repository->updateDefaultValue(null, 'padded', 'https://new.example/p');

        $this->assertSame('HTTPS://OLD.EXAMPLE/P', $this->valueOf(null, 'cased'));
        $this->assertSame('https://old.example/p ', $this->valueOf(null, 'padded'));
    }

    public function testASecretIsNeverMoved(): void
    {
        $this->repository->insert('support_dashboard', 'hash', '', 'secret', 'L', 'D', null, null, false, 0);

        $this->repository->updateDefaultValue('support_dashboard', 'hash', 'other');

        $this->assertSame('', $this->valueOf('support_dashboard', 'hash'));
    }
}
