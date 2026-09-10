<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use PHPUnit\Framework\TestCase;

/**
 * A ratchet: **no production code completes a backup except through
 * `Core\Maintenance\BackupIntegrity`.**
 *
 * `BackupRepository::markCompleted()` takes its two digests as trailing
 * nullable parameters, so a caller that reaches past the service does not
 * fail to compile, does not fail a test, and logs nothing — it simply
 * writes a row that looks finished and that no verification pass can ever
 * check, for the life of that backup. That is the same shape of hole
 * `Tests\Core\Storage\DiskBudgetWiringTest` was written for, and it has
 * six existing sites to go wrong at: the controller's synchronous database
 * dump and five task handlers.
 *
 * Textual, like its two siblings, and for the reason they give: the
 * alternative is a runtime test per composition root, which is how these
 * sites came to have none.
 */
final class BackupIntegrityWiringTest extends TestCase
{
    /** Directories whose PHP files are production code. */
    private const ROOTS = ['core', 'modules', 'public'];

    /**
     * The one file allowed to call it: the service that exists precisely
     * to make sure the digests are written with it.
     */
    private const ALLOWED = 'core/Maintenance/BackupIntegrity.php';

    public function testOnlyBackupIntegrityCompletesABackup(): void
    {
        $offenders = [];

        foreach ($this->productionFiles() as $relative => $source) {
            if ($relative === self::ALLOWED) {
                continue;
            }
            if ($this->completesABackup($source)) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "Ces fichiers marquent une sauvegarde terminée sans passer par Core\\Maintenance\\BackupIntegrity, "
                . "donc sans enregistrer les empreintes qui permettront de la vérifier plus tard. La ligne aura "
                . "l'air normale et rien ne pourra jamais la relire. Appelez BackupIntegrity::complete()."
        );
    }

    /**
     * The ratchet can see the call however it is spelled.
     *
     * A first version anchored on `\$\w*[bB]ackupRepository->markCompleted\(`,
     * which requires the repository's name to start right after the `$`.
     * That matches a handler's local variable and **not** a controller's
     * promoted property, `$this->backupRepository->...` — so the one site
     * this class's docblock names first was scanned and invisible. It also
     * assumed the variable would always be called `backupRepository`.
     *
     * A ratchet whose blind spot is the riskiest call site is worse than
     * no ratchet, because it is believed. These fixtures are what stop the
     * pattern from narrowing again.
     */
    public function testTheScanSeesEveryWayOfSpellingTheCall(): void
    {
        $seen = [
            'local variable' => '$backupRepository->markCompleted($id, $a, $b);',
            'promoted property' => '$this->backupRepository->markCompleted($id, $a, null);',
            'renamed property' => '$this->backups->markCompleted($id, $a, null);',
            'a different name entirely' => '$repo->markCompleted($id, $a, null);',
        ];
        foreach ($seen as $spelling => $code) {
            $this->assertTrue($this->completesABackup($code), 'blind to a ' . $spelling);
        }

        // And the one it must NOT see: a different class with the same verb.
        $this->assertFalse(
            $this->completesABackup('$updateHistoryRepository->markCompleted($historyId);'),
            'update_history is not a backup and never was'
        );
        $this->assertFalse(
            $this->completesABackup('$this->updateHistoryRepository->markCompleted($historyId);'),
            'nor is it, as a property'
        );
    }

    /** And the service really is what every creation site reaches for. */
    public function testEveryFileThatCreatesABackupUsesTheService(): void
    {
        $creators = [];
        $completers = [];

        foreach ($this->productionFiles() as $relative => $source) {
            if ($relative === self::ALLOWED) {
                continue;
            }
            if ($this->createsABackup($source)) {
                $creators[] = $relative;
            }
            if (str_contains($source, 'BackupIntegrity(')) {
                $completers[] = $relative;
            }
        }

        $this->assertNotSame([], $creators, 'No backup creation sites found — the scan is broken.');
        $this->assertContains(
            'core/Http/Controller/MaintenanceController.php',
            $creators,
            'The controller creates backups; a scan that cannot see it is not scanning.'
        );

        foreach ($creators as $file) {
            $this->assertContains(
                $file,
                $completers,
                $file . ' crée une sauvegarde mais ne la termine jamais via BackupIntegrity.'
            );
        }
    }

    /**
     * Whether this source completes a backup row.
     *
     * Every `->markCompleted(` counts, whatever the receiver is called —
     * the receiver's NAME is not evidence, and pinning the pattern to one
     * spelling is what made the first version blind. The only thing
     * excluded is the other class that happens to share the verb:
     * `UpdateHistoryRepository`, which finishes an update, not a backup.
     */
    private function completesABackup(string $source): bool
    {
        $offset = 0;
        while (($at = strpos($source, '->markCompleted(', $offset)) !== false) {
            $offset = $at + 1;
            // The receiver chain, however long: `$this->updateHistoryRepository`
            // and `$updateHistoryRepository` both have to be recognised.
            $receiver = substr($source, max(0, $at - 60), min(60, $at));
            if (stripos($receiver, 'updatehistory') !== false) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Whether this source creates a `backups` row.
     *
     * Two signals, because neither alone covers the real call sites: the
     * repository reached by name, and a `create()` whose first argument is
     * one of the backup types. The controller uses both forms — a literal
     * `'database'` and a `$scope` variable — and a scan keyed on only one
     * of them would miss half of what it is looking at.
     */
    private function createsABackup(string $source): bool
    {
        if (preg_match('/[bB]ackupRepository->create\(/', $source) === 1) {
            return true;
        }

        foreach (\Core\Maintenance\Backup::TYPES as $type) {
            if (str_contains($source, "->create('" . $type . "'")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function productionFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (self::ROOTS as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
