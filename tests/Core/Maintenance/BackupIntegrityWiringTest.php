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
            // `$updateHistoryRepository->markCompleted()` is a different
            // class with the same verb, and none of its business.
            if (preg_match('/\$\w*[bB]ackupRepository->markCompleted\(/', $source) === 1) {
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

    /** And the service really is what the handlers reach for. */
    public function testEveryHandlerThatCreatesABackupUsesTheService(): void
    {
        $creators = [];
        $completers = [];

        foreach ($this->productionFiles() as $relative => $source) {
            if ($relative === self::ALLOWED) {
                continue;
            }
            if (preg_match('/\$\w*[bB]ackupRepository->create\(/', $source) === 1) {
                $creators[] = $relative;
            }
            if (str_contains($source, 'BackupIntegrity(')) {
                $completers[] = $relative;
            }
        }

        $this->assertNotSame([], $creators, 'No backup creation sites found — the scan is broken.');

        foreach ($creators as $file) {
            $this->assertContains(
                $file,
                $completers,
                $file . ' crée une sauvegarde mais ne la termine jamais via BackupIntegrity.'
            );
        }
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
