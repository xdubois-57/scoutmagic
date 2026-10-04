<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\System\CronExecutionFacts;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What the cron's PHP can execute (#700): stored with its time by
 * public/cron.php, refreshed at most every ten minutes, read back by the
 * web — and « never measured » until it has been.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CronExecutionFactsTest extends TestCase
{
    private SettingRepository $repository;
    private SettingService $settings;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SettingRepository($pdo);
        $this->settings = new SettingService($this->repository);
        CronExecutionFacts::register($this->settings);
    }

    private static function facts(int $at, bool $works = true): CronExecutionFacts
    {
        return new CronExecutionFacts(
            $at,
            'cli',
            true,
            $works,
            'exec',
            $works ? 'code 0' : 'aucune sortie, code 127',
            $works ? '/usr/bin/ffmpeg' : null,
            $works ? '/usr/bin/ffprobe' : null
        );
    }

    private function read(): ?CronExecutionFacts
    {
        return CronExecutionFacts::read(new SettingService($this->repository));
    }

    public function testNothingIsKnownBeforeTheCronHasMeasured(): void
    {
        $this->assertNull($this->read());
    }

    public function testTheCronStoresWhatItFoundWithTheTime(): void
    {
        CronExecutionFacts::recordIfDue(
            $this->repository,
            1_800_000_000,
            static fn(int $now): CronExecutionFacts => self::facts($now, false)
        );

        $facts = $this->read();
        $this->assertNotNull($facts);
        $this->assertSame(1_800_000_000, $facts->probedAt);
        $this->assertFalse($facts->shellWorks);
        $this->assertSame('exec', $facts->shellFunction);
        $this->assertSame('aucune sortie, code 127', $facts->shellDetail);
        $this->assertFalse($facts->videoReady());
    }

    public function testItMeasuresAgainOnlyOnceTheRefreshIntervalHasPassed(): void
    {
        $probes = 0;
        $probe = static function (int $now) use (&$probes): CronExecutionFacts {
            $probes++;

            return self::facts($now);
        };

        CronExecutionFacts::recordIfDue($this->repository, 1_800_000_000, $probe);
        CronExecutionFacts::recordIfDue($this->repository, 1_800_000_000 + 60, $probe);
        $this->assertSame(1, $probes);

        CronExecutionFacts::recordIfDue(
            $this->repository,
            1_800_000_000 + CronExecutionFacts::REFRESH_SECONDS,
            $probe
        );
        $this->assertSame(2, $probes);
        $this->assertTrue($this->read()?->videoReady());
    }

    /**
     * A refusal in a legacy charset (here Latin-1 « é ») is not valid
     * UTF-8: it must neither wipe the stored facts nor switch the
     * throttle off by leaving an empty row.
     */
    public function testARefusalInALegacyCharsetIsStoredAndKeepsTheThrottle(): void
    {
        $probes = 0;
        $probe = static function (int $now) use (&$probes): CronExecutionFacts {
            $probes++;

            return new CronExecutionFacts($now, 'cli', true, false, 'exec', "acc\xE8s refus\xE9", null, null);
        };

        CronExecutionFacts::recordIfDue($this->repository, 1_800_000_000, $probe);
        CronExecutionFacts::recordIfDue($this->repository, 1_800_000_000 + 60, $probe);

        $facts = $this->read();
        $this->assertNotNull($facts, 'the facts were stored');
        $this->assertSame(1_800_000_000, $facts->probedAt);
        $this->assertTrue(mb_check_encoding($facts->shellDetail, 'UTF-8'));
        $this->assertSame(1, $probes, 'the second pass is still throttled');
    }

    /** A diagnostic must never be why a cron pass fails. */
    public function testAFailingProbeIsSwallowed(): void
    {
        CronExecutionFacts::recordIfDue($this->repository, 1_800_000_000, static function (): CronExecutionFacts {
            throw new \RuntimeException('boom');
        });

        $this->assertNull($this->read());
    }

    public function testAMalformedRowReadsAsNeverMeasured(): void
    {
        $this->assertNull(CronExecutionFacts::parse('{"probed_at":"yesterday"}'));
        $this->assertNull(CronExecutionFacts::parse('not json'));
    }

    public function testCronPhpRecordsItOnEveryPass(): void
    {
        $cron = (string) file_get_contents(dirname(__DIR__, 3) . '/public/cron.php');

        $record = strpos($cron, '\Core\System\CronExecutionFacts::recordIfDue($cronSettingRepository, time());');
        $this->assertNotFalse($record);
        // After the scheduled work: a stalled probe never delays a task.
        $work = strpos($cron, '$processed = $runner->processOverdue();');
        $this->assertNotFalse($work);
        $this->assertGreaterThan($work, $record);
    }
}
