<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Support\Collector\CommandsCollector;
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

    public function testCommandsDoesNotProbeBinariesTheApplicationNoLongerUses(): void
    {
        // Dumping and restoring are pure PHP now (DatabaseDumper /
        // DatabaseRestorer), so probing the MySQL CLI would report on
        // something the application never calls.
        $commands = $this->runCollector(new CommandsCollector())['entries']['commands.txt'];

        $this->assertStringNotContainsString('## mysqldump', $commands);
        $this->assertStringNotContainsString('## mysql', $commands);
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
