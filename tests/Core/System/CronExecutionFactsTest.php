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

    private static function withPdf(?bool $procOpen, ?string $backend): CronExecutionFacts
    {
        return new CronExecutionFacts(
            1_800_000_000, 'cli', true, true, 'exec', 'code 0', '/usr/bin/ffmpeg', '/usr/bin/ffprobe', $procOpen, $backend
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

    /** #804: proc_open, not exec, is what PdfCompressor uses — measured by the cron itself. */
    public function testThePdfCompressionToolAndProcOpenAreStoredAndReadBack(): void
    {
        CronExecutionFacts::recordIfDue(
            $this->repository,
            1_800_000_000,
            static fn(int $now): CronExecutionFacts => self::withPdf(true, 'ghostscript')
        );

        $facts = $this->read();
        $this->assertNotNull($facts);
        $this->assertTrue($facts->pdfMeasured());
        $this->assertTrue($facts->pdfProcOpen);
        $this->assertSame('ghostscript', $facts->pdfBackend);
        $this->assertTrue($facts->pdfReady());
    }

    /** What was stored before the cron looked at PDFs: « not measured yet », never a verdict. */
    public function testAFactsRowStoredBeforeTheCronMeasuredPdfsReadsAsNotMeasured(): void
    {
        $legacy = json_encode([
            'probed_at' => 1_800_000_000, 'sapi' => 'cli', 'shell_declared' => true, 'shell_works' => true,
            'shell_function' => 'exec', 'shell_detail' => 'code 0',
            'ffmpeg' => '/usr/bin/ffmpeg', 'ffprobe' => '/usr/bin/ffprobe',
        ]);
        $this->repository->updateValue(null, CronExecutionFacts::SETTING, (string) $legacy);

        $facts = $this->read();

        $this->assertNotNull($facts, 'the old format still reads');
        $this->assertTrue($facts->videoReady(), 'what it did say is kept');
        $this->assertFalse($facts->pdfMeasured());
        $this->assertNull($facts->pdfProcOpen);
        $this->assertNull($facts->pdfBackend);
        $this->assertFalse($facts->pdfReady());
    }

    /**
     * @return iterable<string, array{?bool, ?string, bool, bool}>
     */
    public static function pdfCombinations(): iterable
    {
        yield 'never measured' => [null, null, false, false];
        yield 'proc_open off' => [false, 'none', true, false];
        yield 'proc_open on, no tool' => [true, 'none', true, false];
        yield 'ghostscript' => [true, 'ghostscript', true, true];
        yield 'qpdf' => [true, 'qpdf', true, true];
        yield 'pdftocairo' => [true, 'pdftocairo', true, true];
        // Cannot happen from the probe (a tool needs proc_open), but a hand-edited row must not read as ready.
        yield 'a tool without proc_open' => [false, 'ghostscript', true, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pdfCombinations')]
    public function testPdfReadyForEveryCombination(?bool $procOpen, ?string $backend, bool $measured, bool $ready): void
    {
        $facts = CronExecutionFacts::parse((string) json_encode(self::withPdf($procOpen, $backend)->toArray()));

        $this->assertNotNull($facts);
        $this->assertSame($measured, $facts->pdfMeasured());
        $this->assertSame($ready, $facts->pdfReady());
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
