<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\SessionGeneration;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class SessionGenerationTest extends TestCase
{
    public function testThePersistedGenerationIsStableAndInternal(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new SettingService(new SettingRepository($pdo));
        $generation = new SessionGeneration($settings);
        $first = $generation->current();
        $row = (new SettingRepository($pdo))->findByModuleAndKey(null, SessionGeneration::SETTING);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        $this->assertSame([$first, 'secret', 0], [
            (new SessionGeneration(new SettingService(new SettingRepository($pdo))))->current(),
            $row['setting_type'],
            (int) $row['editable'],
        ]);
    }

    public function testALosingInitializerAdoptsTheConcurrentWinner(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new class (new SettingRepository($pdo)) extends SettingService {
            public function claimIfEmpty(string $key, string $value, ?string $moduleId = null): bool
            {
                parent::claimIfEmpty($key, str_repeat('a', 64), $moduleId);

                return parent::claimIfEmpty($key, $value, $moduleId);
            }
        };

        $this->assertSame(str_repeat('a', 64), (new SessionGeneration($settings))->current());
    }

    public function testRotationReplacesACachedOrRestoredGeneration(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new SettingService(new SettingRepository($pdo));
        $generation = new SessionGeneration($settings);
        $first = $generation->current();
        $pdo->exec('DELETE FROM settings');
        $next = $generation->rotate();

        $this->assertNotSame($first, $next);
        $this->assertSame($next, (new SessionGeneration(new SettingService(new SettingRepository($pdo))))->current());
    }

    public function testAnInvalidStoredGenerationCannotBeAdopted(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new SettingService(new SettingRepository($pdo));
        SessionGeneration::register($settings);
        $settings->setInternal(SessionGeneration::SETTING, 'invalid');

        $this->expectException(\RuntimeException::class);
        (new SessionGeneration($settings))->current();
    }
}
