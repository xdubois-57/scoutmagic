<?php

declare(strict_types=1);

namespace Tests;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\SessionGeneration;

final class SessionGenerationFixture
{
    public static function forPdo(?\PDO $pdo = null): SessionGeneration
    {
        return new SessionGeneration(new SettingService(new SettingRepository(
            $pdo ?? DatabaseTestHelper::createTestDatabase()
        )));
    }
}
