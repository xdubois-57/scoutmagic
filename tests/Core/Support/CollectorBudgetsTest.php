<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Support\Collector\FilesystemCollector;
use Core\Support\Collector\LogsCollector;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The support package's one hard contract is that it is **always
 * produced** (ARCHITECTURE.md §8.48). The two collectors that read
 * unbounded amounts of the filesystem are the two that can break it: a
 * shared host's `~/logs` routinely holds one rotated access and error log
 * per domain per day, and `storage/` on a unit with an active gallery holds
 * tens of thousands of entries. Both used to be read whole, as PHP strings,
 * inside one scheduled task.
 *
 * Every bound here reports what it dropped rather than truncating quietly —
 * an archive that silently answers half the question is worse than one that
 * says which half it answered.
 */
class CollectorBudgetsTest extends TestCase
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

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-budgets-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0700, true);
        mkdir($this->storagePath . '/logs', 0700, true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
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
        $archivePath = $this->storagePath . '/temp/budget-' . bin2hex(random_bytes(6)) . '.zip';
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

    // ---------------------------------------------------------------
    // LogsCollector
    // ---------------------------------------------------------------

    public function testTheNumberOfLogFilesCopiedIsBounded(): void
    {
        for ($i = 0; $i < LogsCollector::MAX_FILES + 10; $i++) {
            file_put_contents(
                sprintf('%s/logs/access-%03d.log', $this->storagePath, $i),
                date('Y-m-d H:i:s') . " une ligne récente\n"
            );
        }

        $result = $this->runCollector(new LogsCollector());

        // The collector writes two files of its own alongside the copies —
        // the summary and the grouped-errors digest — and neither is a
        // host log file the budget applies to.
        $generated = ['logs/summary.txt', 'logs/errors-resume.txt'];
        $copied = array_filter(
            array_keys($result['entries']),
            static fn(string $name): bool => !in_array($name, $generated, true) && str_starts_with($name, 'logs/')
        );

        $this->assertCount(LogsCollector::MAX_FILES, $copied);
    }

    /**
     * Copying the logs is not the same as making them readable. The
     * archive that prompted this carried 233 error-log lines, 230 of them
     * one deprecation repeating and exactly one an uncaught exception that
     * had been returning 500 on every route. The fatal goes on top; the
     * noise becomes a number.
     */
    public function testTheFatalIsSurfacedAboveTheNoiseThatBuriesIt(): void
    {
        $now = date('Y-m-d H:i:s');
        $lines = [];
        for ($i = 0; $i < 40; $i++) {
            $lines[] = "{$now} PHP Deprecated: something is deprecated in /htdocs/core/Thing.php on line {$i}";
        }
        $lines[] = "{$now} PHP message: Uncaught Core\\Help\\HelpException: Help topic declares an invalid path "
            . "'/mes-locations/*/calendrier' in /htdocs/core/Help/HelpFrontMatterParser.php:193";
        file_put_contents($this->storagePath . '/logs/error.log', implode("\n", $lines) . "\n");

        $result = $this->runCollector(new LogsCollector());
        $digest = $result['entries']['logs/errors-resume.txt'];

        $this->assertStringContainsString('HelpException', $digest);
        $this->assertStringContainsString('40 ×  PHP Deprecated', $digest);
        // The fatal is stated before the noise, not after it.
        $this->assertLessThan(
            strpos($digest, 'DÉPRÉCIATIONS'),
            (int) strpos($digest, 'HelpException'),
            'the fault must come before the volume of noise'
        );
        $this->assertNotEmpty(array_filter(
            $result['notes'],
            static fn(string $note): bool => str_contains($note, 'fatale')
        ));
    }

    /**
     * One fault repeating a thousand times must be one line, or the digest
     * is as unreadable as the log it summarises.
     */
    public function testRepeatsOfTheSameFaultAreOneGroupedLine(): void
    {
        $now = date('Y-m-d H:i:s');
        $lines = [];
        for ($i = 0; $i < 25; $i++) {
            // Request ids and line numbers vary between occurrences; the
            // fault does not.
            $lines[] = "{$now} [req " . bin2hex(random_bytes(8)) . "] PHP Fatal error: "
                . "Allowed memory size exhausted in /htdocs/core/Thing.php on line {$i}";
        }
        file_put_contents($this->storagePath . '/logs/error.log', implode("\n", $lines) . "\n");

        $digest = $this->runCollector(new LogsCollector())['entries']['logs/errors-resume.txt'];

        $this->assertSame(1, substr_count($digest, 'Allowed memory size'));
        $this->assertStringContainsString('25 ×', $digest);
    }

    public function testAHealthyLogSaysSoRatherThanShowingAnEmptySection(): void
    {
        file_put_contents(
            $this->storagePath . '/logs/error.log',
            date('Y-m-d H:i:s') . " tout va bien\n"
        );

        $digest = $this->runCollector(new LogsCollector())['entries']['logs/errors-resume.txt'];

        $this->assertStringContainsString('Aucune erreur fatale', $digest);
    }

    public function testWhatWasNotCopiedIsReportedRatherThanSilentlyDropped(): void
    {
        for ($i = 0; $i < LogsCollector::MAX_FILES + 3; $i++) {
            file_put_contents(
                sprintf('%s/logs/access-%03d.log', $this->storagePath, $i),
                date('Y-m-d H:i:s') . " une ligne récente\n"
            );
        }

        $result = $this->runCollector(new LogsCollector());

        $this->assertStringContainsString('non copié (budget global atteint)', $result['entries']['logs/summary.txt']);
        $this->assertNotSame([], array_filter(
            $result['notes'],
            static fn(string $note): bool => str_contains($note, 'budget global atteint')
        ));
    }

    /**
     * A host log file that happens to be named `summary.txt` used to be
     * copied to `logs/summary.txt` and then overwritten by this collector's
     * own summary, which is written last.
     */
    public function testAHostLogNamedSummaryTxtDoesNotOverwriteTheCollectorsOwnSummary(): void
    {
        file_put_contents(
            $this->storagePath . '/logs/summary.txt',
            date('Y-m-d H:i:s') . " contenu du journal de l'hébergeur\n"
        );

        $result = $this->runCollector(new LogsCollector());

        $summary = $result['entries']['logs/summary.txt'];
        $this->assertStringContainsString('# Journaux serveur', $summary);
        $this->assertStringNotContainsString("contenu du journal de l'hébergeur", $summary);

        $this->assertArrayHasKey('logs/summary.txt.2', $result['entries']);
        $this->assertStringContainsString(
            "contenu du journal de l'hébergeur",
            $result['entries']['logs/summary.txt.2']
        );
    }

    public function testAnOrdinaryHandfulOfLogsIsCopiedInFullWithNoBudgetNote(): void
    {
        for ($i = 0; $i < 3; $i++) {
            file_put_contents(
                sprintf('%s/logs/error-%d.log', $this->storagePath, $i),
                date('Y-m-d H:i:s') . " ligne " . $i . "\n"
            );
        }

        $result = $this->runCollector(new LogsCollector());

        $this->assertNull($result['unavailable']);
        $this->assertStringNotContainsString('budget global atteint', $result['entries']['logs/summary.txt']);
    }

    public function testAFileLargerThanThePerFileCapIsTruncatedAndSaidToBe(): void
    {
        file_put_contents(
            $this->storagePath . '/logs/huge.log',
            str_repeat(date('Y-m-d H:i:s') . " remplissage\n", 90000)
        );

        $result = $this->runCollector(new LogsCollector());

        $this->assertLessThanOrEqual(
            LogsCollector::MAX_BYTES_PER_FILE + 4096,
            strlen($result['entries']['logs/huge.log'])
        );
        $this->assertNotSame([], array_filter(
            $result['notes'],
            static fn(string $note): bool => str_contains($note, 'tronqué')
        ));
    }

    /**
     * The whole point of the global bound: whatever the candidate list
     * holds, the collector cannot assemble an archive nobody can email.
     */
    public function testTheTotalBytesCopiedStayWithinTheGlobalBudget(): void
    {
        for ($i = 0; $i < 10; $i++) {
            file_put_contents(
                sprintf('%s/logs/big-%d.log', $this->storagePath, $i),
                str_repeat(date('Y-m-d H:i:s') . " remplissage\n", 90000)
            );
        }

        $result = $this->runCollector(new LogsCollector());

        $total = 0;
        foreach ($result['entries'] as $name => $content) {
            if ($name !== 'logs/summary.txt') {
                $total += strlen($content);
            }
        }

        $this->assertLessThanOrEqual(
            LogsCollector::MAX_TOTAL_BYTES + LogsCollector::MAX_BYTES_PER_FILE,
            $total
        );
    }

    // ---------------------------------------------------------------
    // FilesystemCollector
    // ---------------------------------------------------------------

    public function testTheProjectRootHeadingSaysTheDepthItActuallyLists(): void
    {
        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        // The root section lists its first level only; core/, modules/ &co.
        // each get their own depth-2 section right after it.
        $this->assertStringContainsString('## Racine du projet (premier niveau)', $listing);
        $this->assertStringNotContainsString('## Racine du projet (profondeur 2)', $listing);
    }

    public function testStorageIsStillWalkedInFullDepthForAnOrdinaryInstall(): void
    {
        mkdir($this->storagePath . '/core/support/deep/deeper', 0700, true);
        file_put_contents($this->storagePath . '/core/support/deep/deeper/marker.txt', 'x');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertStringContainsString('storage/core/support/deep/deeper/marker.txt', $listing);
        $this->assertStringNotContainsString('liste tronquée', $listing);
    }

    public function testVendorIsNeverWalked(): void
    {
        mkdir($this->projectRoot . '/vendor/some-package', 0700, true);
        file_put_contents($this->projectRoot . '/vendor/some-package/File.php', '<?php');

        $listing = $this->runCollector(new FilesystemCollector())['entries']['filesystem.txt'];

        $this->assertStringContainsString('vendor/ : présent', $listing);
        $this->assertStringNotContainsString('vendor/some-package/File.php', $listing);
    }

    /**
     * The `storage/` cap, exercised against the number of entries that
     * actually trips it. Slow-ish by the standards of this file (a few
     * thousand `touch`es) and worth it: the branch it covers is the one
     * standing between a gallery-sized install and no archive at all.
     */
    public function testAnEnormousStorageTreeIsTruncatedAndSaysSo(): void
    {
        $bulk = $this->storagePath . '/gallery';
        mkdir($bulk, 0700, true);
        for ($i = 0; $i < 5100; $i++) {
            file_put_contents(sprintf('%s/photo-%05d.bin', $bulk, $i), '');
        }

        $result = $this->runCollector(new FilesystemCollector());
        $listing = $result['entries']['filesystem.txt'];

        $this->assertStringContainsString('liste tronquée', $listing);
        $this->assertNotSame([], array_filter(
            $result['notes'],
            static fn(string $note): bool => str_contains($note, 'storage/ tronqué')
        ));

        // Still a usable diagnostic: the sections after storage/ are there.
        $this->assertStringContainsString('## vendor/', $listing);
    }
}
