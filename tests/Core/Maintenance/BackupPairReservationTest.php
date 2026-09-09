<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use PHPUnit\Framework\TestCase;

/**
 * A ratchet, not a unit test: **no caller may take the dump/archive pair
 * without reserving both first.**
 *
 * `BackupService::createFullBackup()` has documented the trap since before
 * this guard existed — « checking them one at a time would let the dump
 * succeed and the archive run out of room half-written » — and four task
 * handlers walked straight into it anyway, each calling
 * `createDatabaseDump()` then `createFileBackup()` with no combined
 * reservation. They were not careless: `DiskBudget::measure()` caches its
 * walk, so the archive's own check reads the same pre-dump figure the dump
 * already passed, and each per-write check looks complete on its own.
 *
 * That is exactly the mistake a review catches once and a fifth handler
 * makes again next year. So the rule is asserted over the source rather
 * than left to reviewers: a file that calls both must also call
 * `ensureRoomForDumpAndArchive()`, before the first of them.
 *
 * The check is textual, and deliberately so — the alternative is a runtime
 * test per handler, each needing a live database, which is how the four
 * came to have no such test at all.
 */
class BackupPairReservationTest extends TestCase
{
    /** Directories whose PHP files are scanned. */
    private const ROOTS = ['core', 'modules', 'public'];

    private const RESERVATION = 'ensureRoomForDumpAndArchive';

    public function testEveryCallerOfTheDumpAndArchivePairReservesBothFirst(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            $dumpAt = strpos($source, '->createDatabaseDump(');
            $archiveAt = strpos($source, '->createFileBackup(');
            if ($dumpAt === false || $archiveAt === false) {
                continue;
            }

            $reserveAt = strpos($source, '->' . self::RESERVATION . '(');
            if ($reserveAt === false || $reserveAt > min($dumpAt, $archiveAt)) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Ces fichiers écrivent le dump ET l\'archive sans réserver les deux d\'abord : '
            . implode(', ', $offenders)
        );
    }

    /**
     * The rule above is worthless if its subject stops existing, and a
     * renamed method would make every file trivially pass.
     */
    public function testTheReservationMethodExists(): void
    {
        $this->assertTrue(
            method_exists(\Core\Maintenance\BackupService::class, self::RESERVATION),
            'BackupService::' . self::RESERVATION . '() a disparu — la règle ci-dessus ne vérifie plus rien.'
        );
        $this->assertTrue(
            method_exists(\Core\Maintenance\BackupServiceInterface::class, self::RESERVATION),
            'BackupServiceInterface ne déclare plus ' . self::RESERVATION . '() — un remplaçant peut l\'omettre.'
        );
    }

    /** The scan has to actually reach the handlers, or it proves nothing. */
    public function testTheScanSeesTheHandlersItIsAbout(): void
    {
        $seen = [];
        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, '->createDatabaseDump(') && str_contains($source, '->createFileBackup(')) {
                $seen[] = basename($file);
            }
        }

        foreach (
            [
                'AutoBackupHandler.php',
                'FullResetHandler.php',
                'ResetSettingsHandler.php',
                'RestoreBackupHandler.php',
                'InstallUpdateHandler.php',
            ] as $handler
        ) {
            $this->assertContains($handler, $seen, $handler . ' n\'est plus vu par ce contrôle.');
        }
    }

    /** @return iterable<string> */
    private function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 3);
        foreach (self::ROOTS as $directory) {
            $path = $root . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        $root = dirname(__DIR__, 3) . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
