<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Support\Collector\BackgroundExecutionCollector;
use Core\Support\Collector\CommandsCollector;
use Core\Support\Collector\CronCadenceCollector;
use Core\Support\Collector\ExtensionsCollector;
use Core\Support\Collector\FilesystemCollector;
use Core\Support\Collector\LogsCollector;
use Core\Support\Collector\PhpInfoCollector;
use Core\Support\Collector\WebServerCollector;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SystemCollectorsTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private string $projectRoot;
    private string $storagePath;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-system-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0700, true);
        mkdir($this->storagePath . '/logs', 0700, true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $connection->method('dumpCredentials')->willReturn([
            'host' => '', 'port' => 0, 'dbName' => '', 'user' => '', 'password' => '',
        ]);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * @return array{entries: array<string, string>, unavailable: ?string, notes: array<int, string>}
     */
    private function runCollector(SupportCollectorInterface $collector): array
    {
        $archivePath = $this->storagePath . '/temp/system-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $context = new SupportCollectorContext(
            $archive,
            $this->connection,
            $this->settings,
            $this->projectRoot,
            $this->storagePath
        );

        $collector->collect($context);
        $archive->close();

        $entries = [];
        $reader = new \ZipArchive();
        if ($reader->open($archivePath) === true) {
            for ($i = 0; $i < $reader->numFiles; $i++) {
                $entries[(string) $reader->getNameIndex($i)] = (string) $reader->getFromIndex($i);
            }
            $reader->close();
        }
        @unlink($archivePath);

        return ['entries' => $entries, 'unavailable' => $context->unavailableReason(), 'notes' => $context->notes()];
    }

    // --- phpinfo.html: the most important assertion of this iteration ---

    public function testPhpInfoCarriesNeitherServerVariablesNorEnvironmentNorCookies(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'PHPSESSID=a-live-superadmin-session-id';
        $_COOKIE['PHPSESSID'] = 'a-live-superadmin-session-id';
        putenv('SCOUTMAGIC_PROBE_SECRET=probe-secret-value');

        try {
            $html = $this->runCollector(new PhpInfoCollector())['entries']['phpinfo.html'] ?? '';

            $this->assertNotSame('', $html);
            // The live session cookie's VALUE is what must never appear —
            // "PHPSESSID" itself also shows up as the session.name ini
            // directive, which is configuration, not a credential.
            $this->assertStringNotContainsString('a-live-superadmin-session-id', $html);
            $this->assertStringNotContainsString('HTTP_COOKIE', $html);
            $this->assertStringNotContainsString('SCOUTMAGIC_PROBE_SECRET', $html);
            $this->assertStringNotContainsString('probe-secret-value', $html);
            $this->assertStringNotContainsString('PHP Variables', $html);
            $this->assertStringNotContainsString('_SERVER["', $html);
            $this->assertStringNotContainsString('$_SERVER', $html);
            $this->assertDoesNotMatchRegularExpression('/(^|\n)\s*Environment\s*(\n|$)/', $html);
        } finally {
            unset($_SERVER['HTTP_COOKIE'], $_COOKIE['PHPSESSID']);
            putenv('SCOUTMAGIC_PROBE_SECRET');
        }
    }

    public function testPhpInfoKeepsItsDiagnosticValue(): void
    {
        $html = $this->runCollector(new PhpInfoCollector())['entries']['phpinfo.html'] ?? '';

        $this->assertStringContainsString(PHP_VERSION, $html);
        $this->assertStringContainsString('memory_limit', $html);
    }

    // --- filesystem.txt ---

    public function testFilesystemDoesNotDescendIntoVendor(): void
    {
        mkdir($this->projectRoot . '/vendor/composer/deep/deeper', 0755, true);
        file_put_contents($this->projectRoot . '/vendor/composer/deep/deeper/needle.php', '<?php');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertStringNotContainsString('needle.php', $listing);
        $this->assertStringNotContainsString('vendor/composer/deep', $listing);
        $this->assertStringContainsString('vendor/ : présent', $listing);
        $this->assertStringContainsString('entrée(s) de premier niveau', $listing);
    }

    public function testFilesystemWalksStorageInFullDepth(): void
    {
        mkdir($this->storagePath . '/core/support/deep/deeper', 0755, true);
        file_put_contents($this->storagePath . '/core/support/deep/deeper/marker.txt', 'x');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertStringContainsString('storage/core/support/deep/deeper/marker.txt', $listing);
    }

    public function testFilesystemStopsAtDepthTwoOutsideStorage(): void
    {
        mkdir($this->projectRoot . '/core/Level2/Level3', 0755, true);
        file_put_contents($this->projectRoot . '/core/Level2/visible.php', '<?php');
        file_put_contents($this->projectRoot . '/core/Level2/Level3/hidden.php', '<?php');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertStringContainsString('core/Level2/visible.php', $listing);
        $this->assertStringNotContainsString('hidden.php', $listing);
    }

    public function testFilesystemReportsPermissionsSizeAndModificationTime(): void
    {
        file_put_contents($this->storagePath . '/probe.txt', 'twelve chars');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];
        $line = null;
        foreach (explode("\n", $listing) as $candidate) {
            if (str_contains($candidate, 'storage/probe.txt')) {
                $line = $candidate;
            }
        }

        $this->assertNotNull($line);
        $this->assertMatchesRegularExpression('/^fich \| 0\d{3}\s+\|/', $line);
        $this->assertStringContainsString('12', $line);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $line);
    }

    public function testFilesystemReportsALinkAndItsTarget(): void
    {
        file_put_contents($this->storagePath . '/target.txt', 'x');
        symlink($this->storagePath . '/target.txt', $this->storagePath . '/alias.txt');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertMatchesRegularExpression('/lien .*alias\.txt -> /', $listing);
    }

    // --- commands.txt ---

    public function testAMissingBinaryIsReportedRatherThanFatal(): void
    {
        $result = $this->runCollector(new CommandsCollector());
        $commands = $result['entries']['commands.txt'];

        foreach (['ffmpeg', 'ffprobe', 'gs', 'qpdf', 'pdftocairo'] as $expected) {
            $this->assertStringContainsString('## ' . $expected, $commands);
        }
        $this->assertMatchesRegularExpression('/disponible : (oui|non)/', $commands);
    }

    /**
     * `mysql` and `mysqldump` used to be absent from this file entirely:
     * dumping and restoring became pure PHP over PDO (DatabaseDumper /
     * DatabaseRestorer) when those binaries proved unusable on the
     * production host, so probing them reported on something the
     * application never calls.
     *
     * They are probed again, and the reason is a change of question
     * rather than a reversal: the archive is read by something that
     * cannot ask a follow-up, so what the HOST has is a fact worth
     * recording even when nothing here calls it — and whether those two
     * exist at all is the first thing anyone re-litigating that decision
     * asks. What must not come back is the misreading the original
     * removal prevented, so the separation is what this pins: they appear
     * under the host inventory, never among the commands the code uses.
     */
    public function testTheMysqlBinariesAreInventoriedButNeverPresentedAsUsedByTheCode(): void
    {
        $commands = $this->runCollector(new CommandsCollector())['entries']['commands.txt'];

        $this->assertStringContainsString('## mysqldump — inventaire seulement', $commands);
        $this->assertStringContainsString('## mysql — inventaire seulement', $commands);

        // Everything before the inventory heading is the code-derived
        // list, and neither may appear in it.
        $usedByCode = substr($commands, 0, (int) strpos($commands, "# Inventaire de l'hôte"));
        $this->assertStringNotContainsString('mysqldump', $usedByCode);
        $this->assertStringNotContainsString('## mysql', $usedByCode);
    }

    /**
     * The verdict a support reader acts on is "does it WORK", not "does
     * disable_functions mention it". On a shared host the two disagree
     * often enough that merging them into one line is how five commands
     * come to be reported absent when the real answer was that nothing
     * could run at all.
     */
    public function testCommandsSeparatesDeclaredShellExecutionFromDemonstratedOne(): void
    {
        $commands = $this->runCollector(new CommandsCollector())['entries']['commands.txt'];

        $this->assertStringContainsString('Exécution de commandes déclarée : ', $commands);
        $this->assertStringContainsString('Exécution de commandes vérifiée : ', $commands);
        $this->assertStringContainsString('Détail de la vérification : ', $commands);
    }

    // --- extensions.txt ---

    public function testExtensionsNamesWhatEachOneIsNeededForAndVerdicts(): void
    {
        $result = $this->runCollector(new ExtensionsCollector());
        $extensions = $result['entries']['extensions.txt'];

        // Required entries carry their consequence, not just their name:
        // "pdo_mysql ABSENTE" alone tells a reader nothing they can act on.
        $this->assertMatchesRegularExpression('/pdo_mysql\s+(présente|ABSENTE)\s+\S/', $extensions);
        $this->assertStringContainsString('## REQUISES', $extensions);
        $this->assertStringContainsString('## OPTIONNELLES', $extensions);
        $this->assertStringContainsString('## Verdict', $extensions);

        // This test process necessarily has the ones it is running on.
        $this->assertMatchesRegularExpression('/pdo\s+présente/', $extensions);
        $this->assertStringContainsString('Toutes les extensions requises sont présentes.', $extensions);
        $this->assertSame([], $result['notes']);
    }

    /**
     * The list only earns its place if it is the one ScoutMagic needs. A
     * generic roster would be phpinfo with extra steps — and phpinfo is
     * already in the archive.
     */
    public function testExtensionsListsWhatTheCodeUsesRatherThanAGenericRoster(): void
    {
        $extensions = $this->runCollector(new ExtensionsCollector())['entries']['extensions.txt'];

        foreach (['pdo_mysql', 'gd', 'zip', 'openssl', 'curl', 'xmlreader'] as $needed) {
            $this->assertMatchesRegularExpression('/^' . $needed . '\s/m', $extensions);
        }
        // Optional ones are kept apart: their absence is a degraded
        // feature, not an incident, and the two lead to different
        // conversations.
        $this->assertStringContainsString('imagick', $extensions);
        $this->assertStringContainsString('PdfRasterizer', $extensions);
    }

    public function testAMissingRequiredExtensionWouldBeSurfacedAsANote(): void
    {
        // Rather than uninstall an extension, assert the mechanism the
        // verdict uses: the note is raised from the same list the file
        // prints, so a real absence reaches collection-status.json too.
        $collector = new ExtensionsCollector();
        $required = new \ReflectionClassConstant(ExtensionsCollector::class, 'REQUIRED');
        /** @var array<string, string> $list */
        $list = $required->getValue();

        $this->assertNotSame([], $list);
        foreach ($list as $extension => $usedBy) {
            $this->assertNotSame('', trim($usedBy), "l'extension {$extension} doit dire ce qui en dépend");
        }
        $this->assertSame('extensions', $collector->name());
    }

    // --- background-execution.txt ---

    public function testBackgroundExecutionReportsWhatIsPossibleOnThisHost(): void
    {
        $report = $this->runCollector(new BackgroundExecutionCollector())['entries']['background-execution.txt'];

        $this->assertStringContainsString('fastcgi_finish_request : ', $report);
        $this->assertStringContainsString('stream_socket_client : ', $report);
        $this->assertStringContainsString('Exécution shell vérifiée : ', $report);
        $this->assertStringContainsString('## Limites', $report);
        $this->assertStringContainsString('open_basedir : ', $report);
        $this->assertStringContainsString('disable_functions : ', $report);
        $this->assertStringContainsString('## Boucle HTTP vers soi-même', $report);
        $this->assertStringContainsString("## Réglages de l'ordonnanceur", $report);
    }

    /**
     * With no base_url there is no target, and inventing one from
     * HTTP_HOST would make a support collector connect wherever an
     * attacker-supplied header pointed — an SSRF triggerable by anyone who
     * can get a superadmin to generate a package.
     */
    public function testTheLoopbackTestRefusesToInventATargetWithoutABaseUrl(): void
    {
        $report = $this->runCollector(new BackgroundExecutionCollector())['entries']['background-execution.txt'];

        $this->assertStringContainsString('base_url vide : aucune cible à tester', $report);
    }

    /**
     * Both targets are reported, never just the first that answers:
     * loopback and the public name fail for different reasons — one is
     * firewalled off from PHP, the other goes out through a proxy or a WAF
     * — and which of the two answers is what turns a report into a fix.
     */
    public function testTheLoopbackTestReportsEveryTargetSeparately(): void
    {
        $this->settings->register('base_url', '', 'text', 'base', 'test', null, null, null, false, 900);
        // A port nothing is listening on: both targets must be attempted
        // and both must be reported as having failed, rather than the file
        // stopping at the first refusal.
        $this->settings->setInternal('base_url', 'http://127.0.0.1:' . $this->aPortNobodyIsUsing());

        $report = $this->runCollector(new BackgroundExecutionCollector())['entries']['background-execution.txt'];

        $this->assertStringContainsString('loopback (127.0.0.1 avec en-tête Host)', $report);
        $this->assertStringContainsString('nom public', $report);
        $this->assertSame(2, substr_count($report, 'ÉCHEC de connexion'));
    }

    private function aPortNobodyIsUsing(): int
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "could not reserve a local port: {$errstr}");
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        return $port;
    }

    /**
     * The archive is emailed. The continuation secret authenticates a
     * request to this site's own scheduler endpoint, so it is reported as
     * present or absent and never printed — Core\Security\CapabilityToken,
     * contract point 2.
     */
    public function testBackgroundExecutionNeverPrintsTheContinuationSecret(): void
    {
        mkdir($this->storagePath . '/keys', 0700, true);
        mkdir($this->storagePath . '/config', 0700, true);
        $secretManager = new \Core\Security\SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets(['scheduler_continuation_secret' => 'the-secret-that-must-not-travel']);

        $report = $this->runCollector(new BackgroundExecutionCollector())['entries']['background-execution.txt'];

        $this->assertStringContainsString('Secret de continuation : présent', $report);
        $this->assertStringNotContainsString('the-secret-that-must-not-travel', $report);
    }

    public function testAnInstallationWithNoContinuationSecretSaysSoRatherThanGuessing(): void
    {
        $report = $this->runCollector(new BackgroundExecutionCollector())['entries']['background-execution.txt'];

        $this->assertStringContainsString('Secret de continuation : ABSENT', $report);
    }

    // --- cron-cadence.txt ---

    private function registerCronSettings(): void
    {
        \Core\Scheduler\CronRunHistory::register($this->settings);
        $this->settings->register('cron_last_run', '0', 'number', 'cron', 'test', null, null, null, false, 999);
        $this->settings->register('scheduler_last_run', '0', 'number', 'pseudo', 'test', null, null, null, false, 999);
    }

    /**
     * The verdict is the point of the file. One stamp cannot distinguish a
     * crontab firing every minute from one the host silently dropped an
     * hour ago, and support acts very differently on those two answers.
     */
    public function testAnInstallationThatHasNeverRunARealCronSaysSoPlainly(): void
    {
        $this->registerCronSettings();

        $report = $this->runCollector(new CronCadenceCollector())['entries']['cron-cadence.txt'];

        $this->assertStringContainsString('VRAI CRON : jamais détecté.', $report);
        $this->assertStringContainsString('PSEUDO-CRON : jamais déclenché.', $report);
    }

    /**
     * The one diagnosis a single stamp could never give, and the one the
     * reference installation needed: the crontab DOES fire, and the pass
     * dies before it ever reaches the database. Through `cron_last_run`
     * alone that is indistinguishable from no crontab at all.
     */
    public function testAFiringCronWhosePassNeverReachesTheDatabaseIsCalledOut(): void
    {
        $this->registerCronSettings();
        file_put_contents($this->storagePath . '/temp/cron-heartbeat', (string) time());

        try {
            $result = $this->runCollector(new CronCadenceCollector());
            $report = $result['entries']['cron-cadence.txt'];

            $this->assertStringContainsString('battement de cœur', $report);
            $this->assertStringContainsString('aucun passage', $report);
            $this->assertNotSame([], $result['notes']);
        } finally {
            @unlink($this->storagePath . '/temp/cron-heartbeat');
        }
    }

    public function testARealCronThatHasStoppedIsDistinguishedFromOneThatNeverRan(): void
    {
        $this->registerCronSettings();
        $this->settings->setInternal('cron_last_run', (string) (time() - 10800));

        $result = $this->runCollector(new CronCadenceCollector());
        $report = $result['entries']['cron-cadence.txt'];

        $this->assertStringContainsString('VRAI CRON : configuré mais SILENCIEUX', $report);
        // And it reaches collection-status.json too, not just the file.
        $this->assertNotSame([], $result['notes']);
    }

    public function testAnActiveRealCronIsReportedWithItsMeasuredIntervals(): void
    {
        $this->registerCronSettings();
        $now = time();
        $this->settings->setInternal('cron_last_run', (string) $now);
        // Five passes, five minutes apart — a crontab doing its job.
        $history = [];
        for ($i = 4; $i >= 0; $i--) {
            $history[] = $now - ($i * 300);
        }
        $this->settings->setInternal(
            \Core\Scheduler\CronRunHistory::SETTING,
            (string) json_encode($history)
        );

        $report = $this->runCollector(new CronCadenceCollector())['entries']['cron-cadence.txt'];

        $this->assertStringContainsString('VRAI CRON : détecté et actif', $report);
        // Raw before derived, per the archive's format rule.
        $this->assertStringContainsString('Intervalles bruts (s) : 300, 300, 300, 300', $report);
        $this->assertStringContainsString('Médian : 5 min 0 s', $report);
        $this->assertStringContainsString('Maximum : 5 min 0 s', $report);
    }

    /**
     * The number that mattered. Six production update failures all read
     * "stuck at migrating for more than 15 minutes", and the cause was
     * trivial unrelated tasks running six minutes after their due time
     * against a watchdog set at fifteen — a gap only visible by
     * subtracting two columns of a spreadsheet by hand.
     */
    public function testTheSchedulingLatencyIsReportedPerTaskAndSummarised(): void
    {
        $this->registerCronSettings();

        $insert = $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at, attempts)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $due = new \DateTimeImmutable('-2 hours');
        foreach ([0, 360] as $lateBySeconds) {
            $insert->execute([
                'core',
                'check_stable_update',
                $due->format('Y-m-d H:i:s'),
                'done',
                $due->modify('+' . $lateBySeconds . ' seconds')->format('Y-m-d H:i:s'),
                1,
            ]);
        }

        $report = $this->runCollector(new CronCadenceCollector())['entries']['cron-cadence.txt'];

        $this->assertStringContainsString("## Latence d'ordonnancement", $report);
        $this->assertStringContainsString('check_stable_update', $report);
        $this->assertStringContainsString('Retard maximum : 6 min 0 s', $report);
        $this->assertStringContainsString('Exécutions considérées : 2', $report);
    }

    /**
     * A reader who does not know a list was cut concludes wrongly: "no
     * late task" and "no late task among the 200 shown" are different
     * sentences. The archive's own rule is that every truncation is
     * declared in the file AND in collection-status.json.
     */
    public function testATruncatedLatencyListSaysSoInTheFileAndInTheStatus(): void
    {
        $this->registerCronSettings();

        $insert = $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at, attempts)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $due = new \DateTimeImmutable('-3 hours');
        for ($i = 0; $i < 205; $i++) {
            $moment = $due->modify('+' . $i . ' seconds')->format('Y-m-d H:i:s');
            $insert->execute(['core', 'noisy_task', $moment, 'done', $moment, 1]);
        }

        $result = $this->runCollector(new CronCadenceCollector());

        $this->assertStringContainsString('*** TRONQUÉ', $result['entries']['cron-cadence.txt']);
        $this->assertNotSame([], $result['notes']);
    }

    // --- webserver/ ---

    public function testEveryHtaccessInTheInstallIsCopied(): void
    {
        file_put_contents($this->projectRoot . '/.htaccess', 'RewriteEngine On');
        mkdir($this->projectRoot . '/public', 0755, true);
        file_put_contents($this->projectRoot . '/public/.htaccess', 'DirectoryIndex index.php');

        $result = $this->runCollector(new WebServerCollector());

        $this->assertArrayHasKey('webserver/htaccess/.htaccess', $result['entries']);
        $this->assertArrayHasKey('webserver/htaccess/public/.htaccess', $result['entries']);
        $this->assertSame('RewriteEngine On', $result['entries']['webserver/htaccess/.htaccess']);
        $this->assertStringContainsString('- .htaccess', $result['entries']['webserver/summary.txt']);
    }

    public function testAnInstallWithoutAnyReadableConfigurationIsUnavailableNotFatal(): void
    {
        // Real, absolute host paths (never anything under $this->projectRoot)
        // that are guaranteed not to exist — the real candidate paths are
        // host-dependent (e.g. macOS ships a readable /etc/apache2/httpd.conf
        // out of the box), which would make this assertion flaky depending
        // on the machine running the suite.
        $result = $this->runCollector(new WebServerCollector([
            $this->projectRoot . '/nonexistent/apache2.conf',
            $this->projectRoot . '/nonexistent/nginx.conf',
        ]));

        $this->assertSame('no_readable_webserver_configuration', $result['unavailable']);
        $this->assertArrayHasKey('webserver/summary.txt', $result['entries']);
        $this->assertStringContainsString('indisponible', $result['entries']['webserver/summary.txt']);
    }

    public function testVendorIsNotScannedForHtaccessFiles(): void
    {
        mkdir($this->projectRoot . '/vendor/some/package', 0755, true);
        file_put_contents($this->projectRoot . '/vendor/some/package/.htaccess', 'Deny from all');

        $result = $this->runCollector(new WebServerCollector());

        $this->assertArrayNotHasKey('webserver/htaccess/vendor/some/package/.htaccess', $result['entries']);
    }

    // --- logs/ ---

    public function testALogFileIsBoundedToTheLast48Hours(): void
    {
        $recent = (new \DateTimeImmutable('-2 hours'))->format('d/M/Y:H:i:s O');
        $old = (new \DateTimeImmutable('-5 days'))->format('d/M/Y:H:i:s O');
        file_put_contents(
            $this->storagePath . '/logs/access.log',
            "203.0.113.1 - - [{$old}] \"GET /old HTTP/1.1\" 200\n"
            . "203.0.113.2 - - [{$recent}] \"GET /recent HTTP/1.1\" 200\n"
        );

        $result = $this->runCollector(new LogsCollector());
        $extract = $result['entries']['logs/access.log'];

        $this->assertStringContainsString('/recent', $extract);
        $this->assertStringNotContainsString('/old', $extract);
    }

    public function testALineWithoutAParseableTimestampIsKept(): void
    {
        file_put_contents(
            $this->storagePath . '/logs/error.log',
            "PHP Fatal error: something\n    #0 /path/to/file.php(12)\n"
        );

        $extract = $this->runCollector(new LogsCollector())['entries']['logs/error.log'];

        $this->assertStringContainsString('PHP Fatal error', $extract);
        $this->assertStringContainsString('#0 /path/to/file.php(12)', $extract);
    }

    public function testALargeLogIsTruncatedAndTheTruncationIsReported(): void
    {
        $line = str_repeat('x', 200) . "\n";
        $content = str_repeat($line, (int) ceil((LogsCollector::MAX_BYTES_PER_FILE * 1.5) / strlen($line)));
        file_put_contents($this->storagePath . '/logs/huge.log', $content);

        $result = $this->runCollector(new LogsCollector());

        $this->assertLessThanOrEqual(
            LogsCollector::MAX_BYTES_PER_FILE + 1024,
            strlen($result['entries']['logs/huge.log'])
        );
        $this->assertNotSame([], $result['notes']);
        $this->assertStringContainsString('tronqué', implode(' ', $result['notes']));
        $this->assertStringContainsString('tronqué', $result['entries']['logs/summary.txt']);
    }

    public function testAnUnreadableCandidateIsReportedNotFatal(): void
    {
        $result = $this->runCollector(new LogsCollector());

        $this->assertSame('no_readable_log_file', $result['unavailable']);
        $this->assertArrayHasKey('logs/summary.txt', $result['entries']);
    }

    public function testNoProcessOrServiceSnapshotIsEverTaken(): void
    {
        file_put_contents($this->storagePath . '/logs/error.log', "PHP Notice: something\n");

        $entries = $this->runCollector(new LogsCollector())['entries'];
        $flattened = implode("\n", array_keys($entries)) . "\n" . implode("\n", $entries);

        foreach (['ps aux', 'systemctl', 'service --status', '/proc/'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flattened);
        }
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    public static function logLineProvider(): array
    {
        return [
            ['[Wed Aug 20 03:00:00.123456 2026] [core:error] message', true],
            ['[20-Aug-2026 03:00:00 UTC] PHP Warning: message', true],
            ['203.0.113.1 - - [20/Aug/2026:03:00:00 +0200] "GET / HTTP/1.1" 200', true],
            ['2026-08-20T03:00:00+00:00 message', true],
            ['2026-08-20 03:00:00 message', true],
            ['    #0 /path/to/file.php(12): someFunction()', false],
            ['completely unstructured line', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('logLineProvider')]
    public function testTimestampParsingCoversTheFormatsHostsActuallyProduce(string $line, bool $expectParsed): void
    {
        $timestamp = LogsCollector::timestampOf($line);

        if ($expectParsed) {
            $this->assertNotNull($timestamp, "Should parse: {$line}");
        } else {
            $this->assertNull($timestamp, "Should not parse: {$line}");
        }
    }
}
