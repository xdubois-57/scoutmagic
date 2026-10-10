<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health;

use Core\Maintenance\Health\HostCheck;
use Core\Maintenance\Health\HostFacts;
use Core\Maintenance\Health\HostHealth;
use Core\Scheduler\CronStatus;
use Core\System\CronExecutionFacts;
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
    /** The cron's facts are 30 s old in these tests. */
    private const WHEN = ' — vérifiée il y a moins d\'une minute';

    public function testAHealthyHostIsAllGreenLinesInTheOrderThePageShows(): void
    {
        $checks = HostHealth::checks($this->facts());

        $this->assertSame(
            [
                'cron', 'secure_connection', 'shell_web', 'shell_cron', 'ffmpeg', 'pdf_compression',
                'archive_encryption', 'sodium', 'gd', 'mail', 'php', 'database', 'storage',
            ],
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
            shellWorks: false,
            cronExecution: self::cron(works: false, pdfProcOpen: false),
            zipEncryption: false,
            sodium: false,
            gd: false,
            missingMailExtensions: ['iconv'],
            phpVersion: '8.3.12',
            databaseVersion: '5.7.44',
            storageWritable: false,
            lastInsecureAccessAt: self::NOW - 60,
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
     * #700: the web PHP sandboxed, the cron's free — the LWS case. Video
     * reads the cron, so it is available, and nothing is asked about the
     * web shell for it.
     */
    public function testVideoFollowsTheCronNotTheWebPhp(): void
    {
        $facts = $this->facts(shellWorks: false, cronExecution: self::cron());

        $this->assertTrue($this->line('ffmpeg', $facts)->isOk());
        $this->assertStringContainsString('/usr/bin/ffmpeg', $this->line('ffmpeg', $facts)->status);
        $web = $this->line('shell_web', $facts);
        // #804: nothing depends on this PHP any more, and the cron works — a
        // neutral line, not « Dégradé » beside « Rien à demander ».
        $this->assertSame(HostCheck::STATE_OK, $web->state);
        $this->assertStringContainsString('aucune sortie, code 127', $web->status);
        $this->assertStringContainsString('sans conséquence', $web->status);
        $this->assertSame('', $web->ask);
        $this->assertStringContainsString('compression des PDF', $web->consequence);
        $this->assertStringNotContainsString('Rien à demander', $web->consequence);
    }

    /** Neither PHP launches anything: there IS something to ask, and the line says so. */
    public function testWhenNeitherPhpRunsCommandsTheWebLineIsDegradedAndAsks(): void
    {
        $web = $this->line('shell_web', $this->facts(shellWorks: false, cronExecution: self::cron(works: false)));

        $this->assertSame(HostCheck::STATE_DEGRADED, $web->state);
        $this->assertNotSame('', $web->ask);
        $this->assertStringContainsString('ni l\'un ni l\'autre', $web->consequence);
    }

    /** Not yet measured by the cron: unknown is not « sans conséquence ». */
    public function testBeforeTheCronHasMeasuredTheWebLineDoesNotClaimItIsHarmless(): void
    {
        $web = $this->line('shell_web', $this->facts(shellWorks: false, defaultCron: false));

        $this->assertSame(HostCheck::STATE_DEGRADED, $web->state);
        $this->assertStringNotContainsString('sans conséquence', $web->status);
        // Nothing was measured, so nothing is asserted about the cron nor asked of the host.
        $this->assertStringNotContainsString('ni l\'un ni l\'autre', $web->consequence);
        $this->assertStringContainsString('pas encore mesuré', $web->consequence);
        $this->assertStringNotContainsString('hébergeur', $web->ask);
        $this->assertStringContainsString('Attendre le prochain passage du cron', $web->ask);
    }

    /** The reverse: the web runs commands, the cron cannot — video is refused. */
    public function testACronThatCannotRunCommandsRefusesVideoWithItsExactError(): void
    {
        $facts = $this->facts(cronExecution: self::cron(works: false));

        $video = $this->line('ffmpeg', $facts);
        $this->assertSame(HostCheck::STATE_MISSING, $video->state);
        $this->assertStringContainsString('le PHP du cron ne peut lancer aucun programme', $video->status);
        $this->assertStringContainsString('Erreur exacte : aucune sortie, code 127', $video->ask);
        $this->assertTrue($this->line('shell_web', $facts)->isOk());
        $this->assertSame(HostCheck::STATE_MISSING, $this->line('shell_cron', $facts)->state);
    }

    /** Commands work for the cron, ffmpeg is missing: ask for ffmpeg, not a shell. */
    public function testOnlyFfmpegMissingAsksForFfmpegAndNothingElse(): void
    {
        $line = $this->line('ffmpeg', $this->facts(shellWorks: false, cronExecution: self::cron(ffprobe: null)));

        $this->assertSame(HostCheck::STATE_MISSING, $line->state);
        $this->assertStringStartsWith('Absent pour le cron : ffprobe', $line->status);
        $this->assertSame(
            'Installer ffmpeg (le paquet fournit aussi ffprobe), exécutable par le PHP du cron.',
            $line->ask
        );
        $this->assertStringNotContainsString('disable_functions', $line->ask);
    }

    /** Before the cron has ever measured: unknown, never absent. */
    public function testBeforeTheFirstCronMeasurementVideoIsUnknownNotAbsent(): void
    {
        $facts = $this->facts(defaultCron: false);

        $video = $this->line('ffmpeg', $facts);
        $this->assertSame(HostCheck::STATE_DEGRADED, $video->state);
        $this->assertStringStartsWith('Inconnus', $video->status);
        $this->assertStringNotContainsString('Absent', $video->status);
        $this->assertStringStartsWith('Pas encore vérifiée', $this->line('shell_cron', $facts)->status);
    }

    /** The cron's line says when it measured. */
    public function testTheCronMeasurementIsDated(): void
    {
        $line = $this->line('shell_cron', $this->facts(cronExecution: self::cron(probedAt: self::NOW - 420)));

        $this->assertSame('Possible (exec) — vérifiée il y a 7 min', $line->status);
    }

    public static function pdfStates(): iterable
    {
        yield 'proc_open off for the cron' => [
            false, 'none', HostCheck::STATE_DEGRADED,
            'Impossible : la fonction proc_open est désactivée pour le PHP du cron' . self::WHEN,
        ];
        yield 'no tool' => [
            true, 'none', HostCheck::STATE_DEGRADED,
            'Aucun outil trouvé pour le PHP du cron (Ghostscript, qpdf ou pdftocairo)' . self::WHEN,
        ];
        yield 'ghostscript' => [
            true, 'ghostscript', HostCheck::STATE_OK, 'Disponible pour le cron : Ghostscript' . self::WHEN,
        ];
        yield 'qpdf' => [true, 'qpdf', HostCheck::STATE_OK, 'Disponible pour le cron : qpdf' . self::WHEN];
        yield 'pdftocairo' => [
            true, 'pdftocairo', HostCheck::STATE_OK, 'Disponible pour le cron : pdftocairo' . self::WHEN,
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pdfStates')]
    public function testThePdfCompressionLineHasOneStateForEachFactTheCronMeasured(
        bool $procOpen,
        string $backend,
        string $state,
        string $status
    ): void {
        $line = $this->line('pdf_compression', $this->facts(
            cronExecution: self::cron(pdfProcOpen: $procOpen, pdfBackend: $backend)
        ));

        $this->assertSame($state, $line->state);
        $this->assertSame($status, $line->status);
        $this->assertNotSame(HostCheck::STATE_MISSING, $line->state, 'nothing is refused without compression');
        $this->assertSame(
            'Sans outil de compression, les PDF téléversés ne sont pas compressés. Rien n\'est refusé.',
            $line->consequence
        );
    }

    /**
     * #804: the line is the CRON's answer, whatever the web PHP can do. The
     * host of the report: the web PHP launches nothing (proc_open off), the
     * cron compresses — and the page must not say « Impossible ».
     */
    public function testAWebPhpThatLaunchesNothingDoesNotMakeThePdfLineFail(): void
    {
        $line = $this->line('pdf_compression', $this->facts(
            shellWorks: false,
            cronExecution: self::cron(pdfProcOpen: true, pdfBackend: 'ghostscript')
        ));

        $this->assertSame(HostCheck::STATE_OK, $line->state);
        $this->assertStringContainsString('Ghostscript', $line->status);
    }

    /** Never measured, or measured before the cron looked at PDFs: unknown, not a verdict. */
    public function testThePdfLineIsNotAVerdictBeforeTheCronHasMeasuredIt(): void
    {
        $never = $this->line('pdf_compression', $this->facts(defaultCron: false));
        $legacy = $this->line('pdf_compression', $this->facts(
            cronExecution: self::cron(pdfProcOpen: null, pdfBackend: null)
        ));

        foreach ([$never, $legacy] as $line) {
            $this->assertSame(HostCheck::STATE_DEGRADED, $line->state);
            $this->assertSame('Pas encore vérifiée : la tâche planifiée ne l\'a jamais mesurée', $line->status);
            $this->assertStringContainsString('Attendre le prochain passage du cron', $line->ask);
        }
    }

    public function testTheInstallationAdviceForPdfIsWrittenOnceAndOnlyHere(): void
    {
        $root = dirname(__DIR__, 4);
        $staffs = (string) file_get_contents($root . '/core/View/templates/chefs/staffs.html.twig');

        $line = $this->line('pdf_compression', $this->facts(cronExecution: self::cron(pdfBackend: 'none')));
        $this->assertStringContainsString('Ghostscript', $line->ask);
        $this->assertStringNotContainsString('apt install ghostscript', $staffs);
        $this->assertStringContainsString('href="/config/maintenance">Santé de l\'hébergement</a>', $staffs);
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

    /**
     * « Connexion sécurisée » (#751) is the one state the attention point
     * and the notification read too: active for 24 hours after the last
     * insecure browser access, then green again on its own.
     */
    public function testTheSecureConnectionLineFollowsTheLastInsecureBrowserAccess(): void
    {
        $never = $this->line('secure_connection', $this->facts());
        $this->assertTrue($never->isOk());
        $this->assertSame('Aucun accès non sécurisé observé', $never->status);

        $recent = $this->line('secure_connection', $this->facts(lastInsecureAccessAt: self::NOW - 3 * 3600));
        $this->assertSame(HostCheck::STATE_MISSING, $recent->state);
        $this->assertSame('Accès non sécurisé observé il y a 3 h', $recent->status);
        $this->assertStringContainsString('https://', $recent->ask);

        $almostADay = $this->line('secure_connection', $this->facts(lastInsecureAccessAt: self::NOW - 24 * 3600 + 1));
        $this->assertFalse($almostADay->isOk());

        $aDayLater = $this->line('secure_connection', $this->facts(lastInsecureAccessAt: self::NOW - 24 * 3600));
        $this->assertTrue($aDayLater->isOk());
        $this->assertSame('Aucun accès non sécurisé depuis plus de 24 h', $aDayLater->status);
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
    private static function cron(
        bool $works = true,
        ?string $ffmpeg = '/usr/bin/ffmpeg',
        ?string $ffprobe = '/usr/bin/ffprobe',
        int $probedAt = self::NOW - 30,
        ?bool $pdfProcOpen = true,
        ?string $pdfBackend = 'ghostscript'
    ): CronExecutionFacts {
        return new CronExecutionFacts(
            $probedAt,
            'cli',
            true,
            $works,
            'exec',
            $works ? 'code 0' : 'aucune sortie, code 127',
            $works ? $ffmpeg : null,
            $works ? $ffprobe : null,
            $pdfProcOpen,
            $pdfBackend
        );
    }

    private function facts(
        ?CronStatus $cron = null,
        bool $shellDeclared = true,
        bool $shellWorks = true,
        bool $zipEncryption = true,
        bool $sodium = true,
        bool $gd = true,
        array $missingMailExtensions = [],
        string $phpVersion = '8.4.12',
        string $databaseDriver = 'mysql',
        string $databaseVersion = '8.0.39',
        bool $storageWritable = true,
        ?CronExecutionFacts $cronExecution = null,
        bool $defaultCron = true,
        ?int $lastInsecureAccessAt = null,
    ): HostFacts {
        return new HostFacts(
            $cron ?? new CronStatus(CronStatus::STATE_ACTIVE, self::NOW - 30, self::NOW - 30, 60, self::NOW),
            $shellDeclared,
            $shellWorks,
            $zipEncryption,
            $sodium,
            $gd,
            $missingMailExtensions,
            $phpVersion,
            $databaseDriver,
            $databaseVersion,
            $storageWritable,
            'exec',
            $shellWorks ? 'code 0' : 'aucune sortie, code 127',
            $cronExecution ?? ($defaultCron ? self::cron() : null),
            $lastInsecureAccessAt,
            self::NOW,
        );
    }
}
