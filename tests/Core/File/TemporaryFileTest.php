<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\TemporaryFile;
use PHPUnit\Framework\TestCase;

/**
 * A temporary file lands in the directory asked for, or nowhere — never in
 * the system's temporary directory, where `tempnam()` silently falls back
 * when the one it is given is unusable (issue #1259).
 */
final class TemporaryFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sm_tmpfile_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheFileIsCreatedInTheDirectoryAndReadableByTheOwnerAlone(): void
    {
        $path = TemporaryFile::createIn($this->root, 'essai_');

        $this->assertSame(realpath($this->root), realpath(dirname($path)));
        $this->assertStringStartsWith('essai_', basename($path));
        $this->assertSame(0, filesize($path));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testAMissingDirectoryIsCreatedPrivate(): void
    {
        $directory = $this->root . '/a/b';

        $path = TemporaryFile::createIn($directory . '/', 'essai_');

        $this->assertSame(realpath($directory), realpath(dirname($path)));
        $this->assertSame(0700, fileperms($directory) & 0777);
    }

    public function testADirectoryThatCannotExistIsRefusedRatherThanReplacedByTheSystemOne(): void
    {
        // A regular file where the directory should be: mkdir() fails, and
        // tempnam() would have fallen back to sys_get_temp_dir().
        file_put_contents($this->root . '/occupied', 'x');
        $before = glob(sys_get_temp_dir() . '/essai_refuse_*') ?: [];

        try {
            TemporaryFile::createIn($this->root . '/occupied', 'essai_refuse_');
            $this->fail('A file was created somewhere else.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame($before, glob(sys_get_temp_dir() . '/essai_refuse_*') ?: []);
    }
}
