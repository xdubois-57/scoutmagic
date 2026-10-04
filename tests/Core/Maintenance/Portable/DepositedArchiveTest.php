<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\Portable\DepositedArchive;
use Core\Security\BootstrapHandoff;
use PHPUnit\Framework\TestCase;

/**
 * The archive waiting on the server for a restore (#719, C): kept after a
 * wrong passphrase, gone once restored, abandoned or a week old.
 */
final class DepositedArchiveTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $root;
    private DepositedArchive $deposit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/deposited_archive_' . uniqid();
        mkdir($this->root, 0755, true);
        $this->deposit = new DepositedArchive($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function upload(string $content = 'PK-archive'): string
    {
        $path = $this->root . '/upload-' . uniqid() . '.zip';
        file_put_contents($path, $content);

        return $path;
    }

    public function testItLivesAtTheAddressTheBootstrapWritesTo(): void
    {
        $this->assertSame($this->root . '/' . BootstrapHandoff::ARCHIVE_PATH, $this->deposit->path());
        $this->assertFalse($this->deposit->exists());
        $this->assertNull($this->deposit->depositedAt());
        $this->assertSame(0, $this->deposit->sizeBytes());
    }

    public function testAnUploadIsAdoptedIntoAProtectedDirectory(): void
    {
        $uploaded = $this->upload('abcdef');

        $path = $this->deposit->adopt($uploaded);

        $this->assertSame($this->deposit->path(), $path);
        $this->assertFileDoesNotExist($uploaded);
        $this->assertSame('abcdef', file_get_contents($path));
        $this->assertSame(6, $this->deposit->sizeBytes());
        // storage/ can sit under the document root (layout A).
        $this->assertSame("Require all denied\n", file_get_contents(dirname($path) . '/.htaccess'));
    }

    public function testAdoptingAnotherFileReplacesThePreviousDeposit(): void
    {
        $this->deposit->adopt($this->upload('first'));
        $this->deposit->adopt($this->upload('second'));

        $this->assertSame('second', file_get_contents($this->deposit->path()));
    }

    public function testDiscardingRemovesTheArchiveAndAnyChunkOnItsWay(): void
    {
        $this->deposit->adopt($this->upload());
        $incoming = $this->root . '/' . BootstrapHandoff::INCOMING_DIR;
        mkdir($incoming, 0700, true);
        file_put_contents($incoming . '/partial.part', 'half');

        $this->deposit->discard();

        $this->assertFalse($this->deposit->exists());
        $this->assertFileDoesNotExist($incoming . '/partial.part');
    }

    public function testAWeekOldArchiveIsPurgedAndAFreshOneIsKept(): void
    {
        $this->deposit->adopt($this->upload());
        touch($this->deposit->path(), self::NOW - BootstrapHandoff::ABANDONED_AFTER_SECONDS + 60);

        $this->assertFalse($this->deposit->purgeAbandoned(self::NOW));
        $this->assertTrue($this->deposit->exists());

        touch($this->deposit->path(), self::NOW - BootstrapHandoff::ABANDONED_AFTER_SECONDS);

        $this->assertTrue($this->deposit->purgeAbandoned(self::NOW));
        $this->assertFalse($this->deposit->exists());
    }

    public function testStaleChunksArePurgedWithoutTouchingRecentOnes(): void
    {
        $incoming = $this->root . '/' . BootstrapHandoff::INCOMING_DIR;
        mkdir($incoming, 0700, true);
        file_put_contents($incoming . '/old.part', 'x');
        file_put_contents($incoming . '/new.part', 'y');
        touch($incoming . '/old.part', self::NOW - BootstrapHandoff::ABANDONED_AFTER_SECONDS - 1);
        touch($incoming . '/new.part', self::NOW - 60);

        $this->deposit->purgeAbandoned(self::NOW);

        $this->assertFileDoesNotExist($incoming . '/old.part');
        $this->assertFileExists($incoming . '/new.part');
    }

    /** A file that is not a portable archive says nothing, rather than failing the page. */
    public function testAFileThatIsNotAnArchiveHasNoHints(): void
    {
        $this->assertNull($this->deposit->hints());

        $this->deposit->adopt($this->upload('not a zip at all'));

        $this->assertNull($this->deposit->hints());
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
