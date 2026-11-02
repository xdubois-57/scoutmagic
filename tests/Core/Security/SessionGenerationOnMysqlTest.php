<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\SessionGeneration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class SessionGenerationOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testConcurrentCoreRegistrationsAdoptOnePersistedGeneration(): void
    {
        $pdo = $this->productionEngine();
        $repository = new SettingRepository($pdo);
        $late = new SettingService($repository);
        $this->assertNull($late->get(SessionGeneration::SETTING));

        $first = (new SessionGeneration(new SettingService($repository)))->current();

        $this->assertSame($first, (new SessionGeneration($late))->current());
        $this->assertSame($first, (new SessionGeneration(new SettingService($repository)))->current());
        $this->assertCount(1, array_filter(
            $repository->findAll(),
            static fn(array $row): bool => $row['setting_key'] === SessionGeneration::SETTING
        ));
    }

    public function testRepositoryReadsKeepTheOldestCoreRowAndEveryModuleScope(): void
    {
        $pdo = $this->productionEngine();
        $repository = new SettingRepository($pdo);
        foreach ([[null, 'a', 90], [null, 'b', 10], ['test_module', 'c', 20]] as [$scope, $value, $order]) {
            $repository->insert($scope, SessionGeneration::SETTING, str_repeat($value, 64),
                'secret', 'Génération de test', '', null, null, false, $order);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE module_id IS NULL AND setting_key = ?');
        $count->execute([SessionGeneration::SETTING]);
        $this->assertSame(2, (int) $count->fetchColumn(), 'The fixture must contain real duplicate core rows.');

        $all = array_values(array_filter($repository->findAll(),
            static fn(array $row): bool => $row['setting_key'] === SessionGeneration::SETTING));
        $this->assertSame([str_repeat('a', 64), str_repeat('c', 64)], array_column($all, 'setting_value'));
        $this->assertSame(str_repeat('a', 64), $repository->findByModuleAndKey(null, SessionGeneration::SETTING)['setting_value']);
        $this->assertSame(str_repeat('c', 64), $repository->findByModuleAndKey('test_module', SessionGeneration::SETTING)['setting_value']);
    }
}
