<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\Backup;
use PHPUnit\Framework\TestCase;

/**
 * Every place that archives the gallery is accounted for in
 * {@see Backup::GALLERY_TYPES}.
 *
 * A ratchet, and it exists because the omission it guards already
 * happened. `GALLERY_TYPES` shipped listing only `full_with_gallery` —
 * the type whose NAME says gallery — while `auto_update` and `auto_reset`
 * quietly carried one too, because the handlers that record them call
 * `BackupService::createFileBackup(true)`: the operation they protect
 * against can wipe `storage/gallery/`, so their safety copy has to hold
 * it. With those two missing, an installation could sit on four
 * gallery-sized archives at once — the exact disk the cap exists to
 * defend, and nothing said a word.
 *
 * The name of a type is not evidence of its contents. The call site is.
 * So this reads the call sites, and a new one nobody has classified fails
 * the build rather than silently escaping the cap.
 */
final class GalleryTypeCoverageTest extends TestCase
{
    /**
     * Every file that asks `createFileBackup()` for the gallery, and the
     * `backups.type` it records for that archive — null where it
     * deliberately records none.
     *
     * @var array<string, string|null>
     */
    private const GALLERY_CALL_SITES = [
        'core/Maintenance/Task/InstallUpdateHandler.php' => 'auto_update',
        'core/Maintenance/Task/ResetSettingsHandler.php' => 'auto_reset',
        'core/Maintenance/Task/RestoreBackupHandler.php' => 'auto_reset',
        // Keeps the file, not the bookkeeping: a full reset empties every
        // table, so a `backups` row recording its own safety copy would
        // not survive the operation it protects. Its own comment says so.
        'core/Maintenance/Task/FullResetHandler.php' => null,
    ];

    /**
     * The second, separate way an archive ends up holding the gallery:
     * `BackupService::createFullBackup()` builds its own zip and decides
     * from the scope an administrator picked, without going through
     * `createFileBackup()` at all. Listed here so the scan below is not
     * silently assumed to be the whole story.
     */
    private const SCOPE_DRIVEN_GALLERY_TYPE = 'full_with_gallery';

    public function testEveryGalleryBearingCallSiteIsClassified(): void
    {
        $found = $this->filesArchivingTheGallery();
        sort($found);
        $declared = array_keys(self::GALLERY_CALL_SITES);
        sort($declared);

        $this->assertSame(
            $declared,
            $found,
            'A call to createFileBackup(true) was added or removed. An archive that carries the gallery weighs '
                . 'more than all the others put together: classify it in GALLERY_CALL_SITES, and if it records a '
                . '`backups` row, add its type to Backup::GALLERY_TYPES.'
        );
    }

    public function testTheScopeAnAdministratorPicksIsUnderTheCapToo(): void
    {
        $this->assertContains(self::SCOPE_DRIVEN_GALLERY_TYPE, Backup::GALLERY_TYPES);
        $this->assertStringContainsString(
            "\$includeGallery = \$scope === '" . self::SCOPE_DRIVEN_GALLERY_TYPE . "'",
            (string) file_get_contents(dirname(__DIR__, 3) . '/core/Maintenance/BackupService.php'),
            'createFullBackup() no longer decides the gallery from that scope; this test is describing the past.'
        );
    }

    public function testEveryRecordedGalleryTypeIsUnderTheCap(): void
    {
        foreach (self::GALLERY_CALL_SITES as $file => $type) {
            if ($type === null) {
                continue;
            }
            $this->assertContains(
                $type,
                Backup::GALLERY_TYPES,
                "{$file} archives the gallery under type '{$type}', which therefore escapes the cap."
            );
        }
    }

    /** And nothing in the list that no call site produces. */
    public function testTheCapListsNoTypeNobodyArchives(): void
    {
        $recorded = array_values(array_filter(self::GALLERY_CALL_SITES));
        $recorded[] = self::SCOPE_DRIVEN_GALLERY_TYPE;

        foreach (Backup::GALLERY_TYPES as $type) {
            $this->assertContains($type, $recorded, "No call site archives the gallery under '{$type}'.");
        }
    }

    /**
     * @return string[]
     */
    private function filesArchivingTheGallery(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/core', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if ($this->asksForTheGallery((string) file_get_contents($file->getPathname()))) {
                $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        return $found;
    }

    /**
     * Whether this source calls `createFileBackup()` with anything other
     * than a literal `false`.
     *
     * Comments and docblocks are stripped first, through the tokenizer
     * rather than a regular expression: a prose mention of the call — this
     * file's own docblocks, and the one in `Backup::GALLERY_TYPES` — is
     * not a call site, and a ratchet that cannot tell the difference gets
     * silenced rather than fixed. The declaration's own default is
     * dropped for the same reason.
     *
     * Anything that is not literally `false` counts, including a variable:
     * a call whose argument is computed is precisely the one nobody can
     * classify by reading it, so it should stop the build and be looked at.
     */
    private function asksForTheGallery(string $source): bool
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

        $code = str_replace('public function createFileBackup', '', $code);

        return preg_match('/createFileBackup\(\s*(?!false\s*\))[^)]*\)/', $code) === 1;
    }
}
