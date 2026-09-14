<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every production `new BackupService(...)` says what this installation
 * has declared as a storage location.
 *
 * **A ratchet on the quiet direction, and it replaces one.** Until IT-03
 * the same file was guarded by `GalleryTypeCoverageTest`, which read the
 * call sites of `createFileBackup(true)` because the name of a backup
 * type said nothing about whether the archive held photographs. D10
 * removed that flag: an archive now leaves out every directory declared
 * as a storage location, and the only thing it needs in order to do so is
 * the list — so the failure that can happen silently moved from the CALL
 * to the CONSTRUCTION.
 *
 * It is worth a ratchet for the reason its predecessor was. The
 * constructor's fifth argument is optional, because exactly one caller
 * legitimately has nothing to pass (`SetupController`, running before the
 * table exists). A new caller that simply forgets it produces an archive
 * that silently resumes carrying gigabytes of somebody's photographs:
 * nothing fails, nothing is logged, and the only symptom is a bill from
 * whoever stores the result. So the construction sites are read, and one
 * nobody has classified stops the build rather than shipping.
 *
 * Reading the sites rather than trusting a convention is the same choice
 * `GalleryTypeCoverageTest` made and for the same reason: what a piece of
 * code is called is not evidence of what it does.
 */
final class BackupServiceWiringTest extends TestCase
{
    /**
     * Every production file that constructs a `BackupService`, and what it
     * is expected to pass for the declared locations.
     *
     * `'none'` is the deliberate exception and there is exactly one: the
     * setup wizard restores an archive into a site that is still being
     * installed, so `storage_locations` holds no row and the honest
     * answer is an empty list — spelled as
     * `DeclaredStorageDirectories::none()` rather than omitted, so that
     * "nothing is declared" and "nobody wired this" cannot look alike.
     *
     * @var array<string, 'declared'|'none'>
     */
    private const CONSTRUCTION_SITES = [
        'core/Http/Controller/SetupController.php' => 'none',
        'core/Maintenance/Task/AutoBackupHandler.php' => 'declared',
        'core/Maintenance/Task/CreateBackupHandler.php' => 'declared',
        'core/Maintenance/Task/FullResetHandler.php' => 'declared',
        'core/Maintenance/Task/InstallUpdateHandler.php' => 'declared',
        'core/Maintenance/Task/ResetSettingsHandler.php' => 'declared',
        'core/Maintenance/Task/RestoreBackupHandler.php' => 'declared',
        'core/Maintenance/Task/SendRemoteBackupHandler.php' => 'declared',
        'public/index.php' => 'declared',
    ];

    public function testEveryConstructionSiteIsClassified(): void
    {
        $found = $this->filesConstructingABackupService();
        sort($found);
        $declared = array_keys(self::CONSTRUCTION_SITES);
        sort($declared);

        $this->assertSame(
            $declared,
            $found,
            'A `new BackupService(...)` was added or removed in production code. An archive built by a service '
                . 'that was not told what this installation declares silently carries every storage location in '
                . 'it: classify the file in CONSTRUCTION_SITES and pass DeclaredStorageDirectories to it.'
        );
    }

    public function testEverySiteActuallyPassesTheDeclaredLocations(): void
    {
        foreach (self::CONSTRUCTION_SITES as $file => $expectation) {
            $code = $this->codeWithoutComments($this->read($file));

            $this->assertStringContainsString(
                'DeclaredStorageDirectories',
                $code,
                "{$file} constructs a BackupService without naming DeclaredStorageDirectories at all."
            );

            if ($expectation === 'none') {
                $this->assertStringContainsString(
                    'DeclaredStorageDirectories::none()',
                    $code,
                    "{$file} is classified as having nothing to declare, so it must say so explicitly."
                );
                continue;
            }

            $this->assertStringContainsString(
                'DeclaredStorageDirectories::fromDatabase(',
                $code,
                "{$file} must read this installation's declared locations rather than assuming there are none."
            );
        }
    }

    /**
     * @return string[] repository-relative paths
     */
    private function filesConstructingABackupService(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach ([$root . '/core', $root . '/public'] as $tree) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = $this->codeWithoutComments((string) file_get_contents($file->getPathname()));
                if (str_contains($code, 'new BackupService(') || str_contains($code, 'new \Core\Maintenance\BackupService(')) {
                    $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
                }
            }
        }

        return $found;
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path, "{$relativePath} is listed as a construction site but does not exist.");

        return (string) file_get_contents($path);
    }

    /**
     * The source with comments and docblocks removed, through the
     * tokenizer rather than a regular expression.
     *
     * A prose mention of the class — and this iteration wrote several,
     * because the rule is worth explaining where it applies — is not a
     * construction site, and a ratchet that cannot tell the difference
     * gets silenced rather than fixed.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
