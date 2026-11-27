<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\CronPassEnd;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The one stamp a cron pass writes at its END (issue #1439). The others
 * are all written before the work, so this is the only one that can say a
 * pass went all the way through.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CronPassEndTest extends TestCase
{
    private \PDO $pdo;
    private SettingRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SettingRepository($this->pdo);
        CronPassEnd::register(new SettingService($this->repository));
    }

    public function testNoPassRecordedReadsAsNull(): void
    {
        $this->assertNull(CronPassEnd::read(new SettingService($this->repository)));
    }

    public function testARecordedEndIsReadBack(): void
    {
        CronPassEnd::record($this->repository, 1_790_000_000);

        $this->assertSame(1_790_000_000, CronPassEnd::read(new SettingService($this->repository)));
    }

    /** Read-only on Configuration > Réglages: only public/cron.php writes it. */
    public function testTheSettingIsRegisteredReadOnly(): void
    {
        $row = $this->repository->findByModuleAndKey(null, CronPassEnd::SETTING);

        $this->assertIsArray($row);
        $this->assertSame('0', (string) $row['setting_value']);
        $this->assertFalse((bool) $row['editable']);
    }

    /** Best effort: a diagnostic stamp must never turn a finished pass into a failed one. */
    public function testRecordingNeverThrows(): void
    {
        $this->pdo->exec('DROP TABLE settings');

        CronPassEnd::record($this->repository, time());

        $this->addToAssertionCount(1);
    }
}
