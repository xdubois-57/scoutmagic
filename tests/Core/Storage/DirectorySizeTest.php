<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Storage\DirectorySize;
use PHPUnit\Framework\TestCase;

class DirectorySizeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/directory_size_test_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingDirectoryIsZeroRatherThanAnError(): void
    {
        $this->assertSame(0, DirectorySize::measure($this->root . '/nope'));
    }

    public function testSumsEveryFileInEverySubdirectory(): void
    {
        $this->write('a.txt', str_repeat('x', 100));
        $this->write('sub/b.txt', str_repeat('x', 250));
        $this->write('sub/deeper/c.txt', str_repeat('x', 7));

        $this->assertSame(357, DirectorySize::measure($this->root));
    }

    public function testExcludedPrefixesAreLeftOutEntirely(): void
    {
        $this->write('keep/a.txt', str_repeat('x', 10));
        $this->write('drop/b.txt', str_repeat('x', 1000));

        $this->assertSame(10, DirectorySize::measure($this->root, [$this->root . '/drop']));
    }

    /**
     * An exclusion prefix names a directory, not a string prefix: excluding
     * `temp` must not also exclude `temperatures`. The check is written to
     * compare on a path boundary for exactly this reason.
     */
    public function testAnExclusionDoesNotSwallowASiblingWithTheSamePrefix(): void
    {
        $this->write('temp/a.txt', str_repeat('x', 1000));
        $this->write('temperatures/b.txt', str_repeat('x', 40));

        $this->assertSame(40, DirectorySize::measure($this->root, [$this->root . '/temp']));
    }

    /**
     * The measurement the disk budget uses must not follow links: bytes
     * behind one are counted twice when it points inside the tree, and are
     * not on this account's quota at all when it points outside.
     */
    public function testSymbolicLinksAreNotFollowedByDefault(): void
    {
        $this->write('real/a.txt', str_repeat('x', 64));

        $outside = sys_get_temp_dir() . '/directory_size_outside_' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/big.bin', str_repeat('x', 5000));

        if (!@symlink($outside . '/big.bin', $this->root . '/linked.bin')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(64, DirectorySize::measure($this->root));
        } finally {
            @unlink($this->root . '/linked.bin');
            $this->removeDirectory($outside);
        }
    }

    /**
     * A backup wants the opposite answer, and gets it explicitly: an
     * archive that silently left out a tree the host symlinked elsewhere
     * would be a backup missing what it was taken for.
     */
    public function testSymbolicLinksAreFollowedWhenAskedFor(): void
    {
        $this->write('real/a.txt', str_repeat('x', 64));

        $outside = sys_get_temp_dir() . '/directory_size_outside_' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/big.bin', str_repeat('x', 5000));

        if (!@symlink($outside . '/big.bin', $this->root . '/linked.bin')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(5064, DirectorySize::measure($this->root, [], true));
        } finally {
            @unlink($this->root . '/linked.bin');
            $this->removeDirectory($outside);
        }
    }

    public function testFilesYieldsEveryFileTheMeasurementCounted(): void
    {
        $this->write('a.txt', 'x');
        $this->write('sub/b.txt', 'x');
        $this->write('drop/c.txt', 'x');

        $names = [];
        foreach (DirectorySize::files($this->root, [$this->root . '/drop']) as $file) {
            $names[] = $file->getFilename();
        }
        sort($names);

        $this->assertSame(['a.txt', 'b.txt'], $names);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                @unlink((string) $item);
                continue;
            }
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }
}
