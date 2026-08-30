<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\CronRunHistory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `cron_last_run` answers "did a real crontab ever run here". It cannot
 * answer "how often", because it is one stamp overwritten on every pass —
 * and a crontab configured hourly on a host that silently drops it looks
 * exactly like one firing every minute through a single stamp. Only the
 * intervals tell them apart, and an interval needs two timestamps.
 */
class CronRunHistoryTest extends TestCase
{
    private \PDO $pdo;
    private SettingRepository $repository;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->repository);
        CronRunHistory::register($this->settings);
    }

    /**
     * SettingService caches what it reads, and the collector that reads
     * this buffer always runs in a process that has just started — so a
     * reader built after the write is the honest shape to assert against.
     */
    private function freshService(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    public function testEachPassIsAppendedInOrder(): void
    {
        foreach ([1000, 1300, 1600] as $moment) {
            CronRunHistory::record($this->repository, $moment);
        }

        $this->assertSame([1000, 1300, 1600], CronRunHistory::read($this->freshService()));
    }

    /**
     * The buffer is bounded so the `settings` row stays a few hundred
     * bytes; the newest entries are the ones worth keeping, since the
     * question is always "what is the cadence now".
     */
    public function testTheBufferKeepsTheNewestEntriesAndDropsTheOldest(): void
    {
        for ($i = 1; $i <= CronRunHistory::MAX_ENTRIES + 5; $i++) {
            CronRunHistory::record($this->repository, 1000 + $i);
        }

        $history = CronRunHistory::read($this->freshService());

        $this->assertCount(CronRunHistory::MAX_ENTRIES, $history);
        $this->assertSame(1000 + CronRunHistory::MAX_ENTRIES + 5, $history[count($history) - 1]);
        $this->assertSame(1006, $history[0]);
    }

    /**
     * A corrupted row reads as "no history" rather than throwing — the
     * same rule the schema-hash cache follows. This runs at the top of
     * every cron pass, and a support diagnostic must never be the reason
     * a cron pass does not happen.
     */
    public function testAMalformedRowReadsAsEmptyRatherThanThrowing(): void
    {
        $this->repository->updateValue(null, CronRunHistory::SETTING, 'not json at all');

        $this->assertSame([], CronRunHistory::read($this->freshService()));

        // And recording still works afterwards: a corrupted row heals.
        CronRunHistory::record($this->repository, 4242);
        $this->assertSame([4242], CronRunHistory::read($this->freshService()));
    }

    public function testNonNumericEntriesAreDiscardedRatherThanCoercedToZero(): void
    {
        $this->repository->updateValue(
            null,
            CronRunHistory::SETTING,
            (string) json_encode([1000, 'garbage', ['nested'], 2000])
        );

        // A 'garbage' entry coerced to 0 would invent a gap of 1000
        // seconds that never happened, and the whole file's value is that
        // its intervals are real.
        $this->assertSame([1000, 2000], CronRunHistory::read($this->freshService()));
    }
}
