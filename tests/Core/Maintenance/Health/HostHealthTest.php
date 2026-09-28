<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health;

use Core\Maintenance\Health\HostCheck;
use Core\Maintenance\Health\HostFacts;
use Core\Maintenance\Health\HostHealth;
use Core\Scheduler\CronStatus;
use PHPUnit\Framework\TestCase;

/**
 * Santé de l'hébergement, line by line (issue #619, IT-02): every state
 * of every dependency, from facts rather than from this host — which has
 * ffmpeg, sodium and a writable storage/, and so could only ever show the
 * green half.
 */
final class HostHealthTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testAHealthyHostIsNineGreenLinesInTheOrderThePageShows(): void
    {
        $checks = HostHealth::checks($this->facts());

        $this->assertSame(
            ['cron', 'ffmpeg', 'archive_encryption', 'sodium', 'gd', 'mail', 'php', 'database', 'storage'],
            array_map(static fn(HostCheck $c): string => $c->key, $checks)
        );
        foreach ($checks as $check) {
            $this->assertTrue($check->isOk(), "{$check->key} should be ok");
        }
    }

    /**
     * The three things each line says: a line whose consequence or whose
     * request to the host is empty would be « ffmpeg : absent » again.
     */
    public function testEveryLineThatIsNotOkSaysWhatStopsAndWhatToAsk(): void
    {
        $checks = HostHealth::checks($this->facts(
            cron: new CronStatus(CronStatus::STATE_NEVER, null, null, null, self::NOW),
            ffmpegPath: null,
            ffprobePath: null,
            zipEncryption: false,
            sodium: false,
            gd: false,
            missingMailExtensions: ['iconv'],
            phpVersion: '8.3.12',
            databaseVersion: '5.7.44',
            storageWritable: false,
        ));

        foreach ($checks as $check) {
            $this->assertFalse($check->isOk(), "{$check->key} should not be ok");
            $this->assertNotSame('', $check->status, $check->key);
            $this->assertNotSame('', $check->consequence, $check->key);
            $this->assertNotSame('', $check->ask, $check->key);
        }
    }

    public function testTheCronLineSaysWhenItLastRanAndAtWhatCadence(): void
    {
        $active = HostHealth::cronCheck(
            new CronStatus(CronStatus::STATE_ACTIVE, self::NOW - 40, self::NOW - 40, 60, self::NOW)
        );
        $stale = HostHealth::cronCheck(
            new CronStatus(CronStatus::STATE_STALE, self::NOW - 10800, self::NOW - 10800, 60, self::NOW)
        );

        $this->assertSame('Active — dernier passage il y a 40 s, cadence ~1 min', $active->status);
        $this->assertSame(HostCheck::STATE_MISSING, $stale->state);
        $this->assertSame('Plus détectée — dernier passage il y a 180 min', $stale->status);
    }

    /**
     * A host where PHP may launch nothing reports that, rather than two
     * binaries « absent » that may well be installed.
     */
    public function testNoShellIsNamedAsTheCauseRatherThanMissingBinaries(): void
    {
        $line = $this->line('ffmpeg', $this->facts(shellAvailable: false, ffmpegPath: null, ffprobePath: null));

        $this->assertSame(HostCheck::STATE_MISSING, $line->state);
        $this->assertStringContainsString('aucun programme', $line->status);
        $this->assertStringContainsString('disable_functions', $line->ask);
    }

    public function testOneMissingBinaryIsNamed(): void
    {
        $line = $this->line('ffmpeg', $this->facts(ffprobePath: null));

        $this->assertSame(HostCheck::STATE_MISSING, $line->state);
        $this->assertSame('Absent : ffprobe', $line->status);
        $this->assertStringContainsString('téléversement de vidéos', $line->consequence);
    }

    /** The fallback works, so the line is degraded — not missing. */
    public function testSodiumMissingIsADegradedLineThatNamesTheFallback(): void
    {
        $line = $this->line('sodium', $this->facts(sodium: false));

        $this->assertSame(HostCheck::STATE_DEGRADED, $line->state);
        $this->assertStringContainsString('PBKDF2', $line->status);
        $this->assertStringContainsString('ne peut pas être restaurée ici', $line->consequence);
    }

    public function testTheMailLineNamesTheMissingExtensions(): void
    {
        $line = $this->line('mail', $this->facts(missingMailExtensions: ['iconv', 'fileinfo']));

        $this->assertSame('Extensions absentes : iconv, fileinfo', $line->status);
        $this->assertSame('Activer les extensions PHP iconv, fileinfo.', $line->ask);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function databases(): iterable
    {
        yield 'MariaDB tested' => ['mysql', '10.11.6-MariaDB-0+deb12u1', HostCheck::STATE_OK, 'MariaDB 10.11.6'];
        yield 'MariaDB behind a MySQL prefix' => ['mysql', '5.5.5-10.6.18-MariaDB', HostCheck::STATE_DEGRADED, 'MariaDB 10.6.18'];
        yield 'MySQL tested' => ['mysql', '8.0.39', HostCheck::STATE_OK, 'MySQL 8.0.39'];
        yield 'MySQL too old' => ['mysql', '5.7.44-log', HostCheck::STATE_DEGRADED, 'MySQL 5.7.44'];
        yield 'another driver' => ['sqlite', '3.45.1', HostCheck::STATE_OK, 'sqlite 3.45.1'];
        yield 'version unreadable' => ['mysql', '', HostCheck::STATE_DEGRADED, 'Moteur ou version illisible'];
        yield 'driver unreadable' => ['', '8.0.39', HostCheck::STATE_DEGRADED, 'Moteur ou version illisible'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('databases')]
    public function testTheDatabaseLineReadsTheEngineAndItsVersion(
        string $driver,
        string $version,
        string $state,
        string $statusStart
    ): void {
        $line = $this->line('database', $this->facts(databaseDriver: $driver, databaseVersion: $version));

        $this->assertSame($state, $line->state);
        $this->assertStringStartsWith($statusStart, $line->status);
    }

    public function testAnOlderPhpIsMissingNotDegraded(): void
    {
        $this->assertSame(HostCheck::STATE_MISSING, $this->line('php', $this->facts(phpVersion: '8.3.12'))->state);
        $this->assertSame(HostCheck::STATE_OK, $this->line('php', $this->facts(phpVersion: '8.4.0'))->state);
    }

    public function testStorageIsWritableOnlyIfAFileCanActuallyBeCreated(): void
    {
        $dir = sys_get_temp_dir() . '/host-health-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->assertTrue(HostHealth::isWritableDirectory($dir));
            $this->assertSame([], glob($dir . '/.host-health-*'), 'The probe file must not be left behind.');
            $this->assertFalse(HostHealth::isWritableDirectory($dir . '/missing'));
        } finally {
            rmdir($dir);
        }
    }

    private function line(string $key, HostFacts $facts): HostCheck
    {
        foreach (HostHealth::checks($facts) as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }
        $this->fail("No line {$key}");
    }

    /** @param list<string> $missingMailExtensions */
    private function facts(
        ?CronStatus $cron = null,
        bool $shellAvailable = true,
        ?string $ffmpegPath = '/usr/bin/ffmpeg',
        ?string $ffprobePath = '/usr/bin/ffprobe',
        bool $zipEncryption = true,
        bool $sodium = true,
        bool $gd = true,
        array $missingMailExtensions = [],
        string $phpVersion = '8.4.12',
        string $databaseDriver = 'mysql',
        string $databaseVersion = '8.0.39',
        bool $storageWritable = true,
    ): HostFacts {
        return new HostFacts(
            $cron ?? new CronStatus(CronStatus::STATE_ACTIVE, self::NOW - 30, self::NOW - 30, 60, self::NOW),
            $shellAvailable,
            $ffmpegPath,
            $ffprobePath,
            $zipEncryption,
            $sodium,
            $gd,
            $missingMailExtensions,
            $phpVersion,
            $databaseDriver,
            $databaseVersion,
            $storageWritable,
        );
    }
}
