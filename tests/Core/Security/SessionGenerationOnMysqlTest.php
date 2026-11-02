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
}
