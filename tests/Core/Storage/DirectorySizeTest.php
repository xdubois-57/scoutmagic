<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Storage\DirectorySize;
use Core\Storage\DirectoryWalk;
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
            $this->assertSame(5064, DirectorySize::measure($this->root, [], DirectoryWalk::Archive));
        } finally {
            @unlink($this->root . '/linked.bin');
            $this->removeDirectory($outside);
        }
    }

    /**
     * ...and a symlinked **directory** too, which is the case that matters
     * and the one the first version of this enum silently failed.
     *
     * `RecursiveDirectoryIterator::hasChildren()` refuses to descend into a
     * linked directory without `FilesystemIterator::FOLLOW_SYMLINKS`,
     * whatever the filter returns — so the entry surfaced as a leaf, failed
     * `isFile()` and vanished. Symlinked files went through none of that,
     * which is exactly why the test above passed while the promise was
     * broken for the scenario the docblock names: a host with
     * `storage/gallery` symlinked onto another volume, whose archive
     * contained none of it and whose size estimate agreed with the wrong
     * number, so nothing looked inconsistent before a restore needed it.
     */
    public function testASymlinkedDirectoryIsFollowedIntoForAnArchive(): void
    {
        $this->write('real/a.txt', str_repeat('x', 64));

        $outside = sys_get_temp_dir() . '/directory_size_outside_dir_' . uniqid();
        mkdir($outside . '/nested', 0755, true);
        file_put_contents($outside . '/nested/big.bin', str_repeat('x', 5000));

        if (!@symlink($outside, $this->root . '/linked_dir')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(
                5064,
                DirectorySize::measure($this->root, [], DirectoryWalk::Archive),
                'An archive must contain a symlinked directory, not only a symlinked file.'
            );
            $this->assertSame(
                64,
                DirectorySize::measure($this->root, [], DirectoryWalk::Measurement),
                'A measurement still must not, or bytes outside this account are counted as its own.'
            );
        } finally {
            @unlink($this->root . '/linked_dir');
            $this->removeDirectory($outside);
        }
    }

    /**
     * Following links makes cycles reachable, and PHP's recursive iterator
     * has none of its own detection: it would walk until the pathname
     * limit, producing an archive that never closes. Each directory is
     * entered once, by resolved path.
     */
    public function testASymlinkCycleIsWalkedOnceRatherThanForever(): void
    {
        $this->write('branch/leaf.txt', str_repeat('x', 12));

        if (!@symlink($this->root, $this->root . '/branch/up')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(12, DirectorySize::measure($this->root, [], DirectoryWalk::Archive));
        } finally {
            @unlink($this->root . '/branch/up');
        }
    }

    /** A link pointing nowhere is not a directory to walk and not bytes to archive. */
    public function testABrokenSymlinkIsSkippedRatherThanFatal(): void
    {
        $this->write('kept.txt', str_repeat('x', 7));

        if (!@symlink($this->root . '/does-not-exist', $this->root . '/dangling')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(7, DirectorySize::measure($this->root, [], DirectoryWalk::Archive));
        } finally {
            @unlink($this->root . '/dangling');
        }
    }

    /**
     * **An exclusion has to survive a symbolic link, or it is not an
     * exclusion** — and this is the one that hurts: `BackupService` uses
     * these prefixes to keep `storage/keys/master.key` and
     * `storage/config/secrets.enc` OUT of every archive (SECURITY.md:
     * secrets never leave the server in a backup, encrypted or not).
     *
     * Matching only the textual pathname was enough while linked
     * directories were never descended into. The moment they are — which
     * is what `DirectoryWalk::Archive` now does, deliberately —
     * `storage/link -> storage/keys` yields entries named
     * `storage/link/master.key`, and no prefix beginning `storage/keys`
     * will ever match that. The secret would have gone straight into the
     * zip, past an exclusion list that looked complete.
     *
     * So the resolved path is matched too, against resolved prefixes.
     */
    public function testAnExcludedDirectoryStaysExcludedWhenReachedThroughASymlink(): void
    {
        $this->write('keys/master.key', 'SUPER-SECRET-MASTER-KEY');
        $this->write('uploads/doc.pdf', 'ordinary');

        if (!@symlink($this->root . '/keys', $this->root . '/link')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $names = [];
            foreach (
                DirectorySize::files(
                    $this->root,
                    [$this->root . '/keys'],
                    DirectoryWalk::Archive
                ) as $file
            ) {
                $names[] = str_replace($this->root . '/', '', $file->getPathname());
            }

            $this->assertSame(['uploads/doc.pdf'], $names);
            $this->assertSame(
                strlen('ordinary'),
                DirectorySize::measure($this->root, [$this->root . '/keys'], DirectoryWalk::Archive)
            );
        } finally {
            @unlink($this->root . '/link');
        }
    }

    /**
     * And resolving the exclusions must not start excluding what they
     * never covered: `temp` still does not exclude `temperatures`, reached
     * directly or through a link.
     *
     * The link also shows the cycle guard doing its second job — a tree
     * symlinked twice is walked once, so `temperatures/` appears under its
     * own name and not a second time under `readings/`. The two rules meet
     * here and the assertion pins both: what is excluded stays out, and
     * what is not excluded appears exactly once.
     */
    public function testResolvingAnExclusionStillMatchesOnAPathBoundary(): void
    {
        $this->write('temp/scratch.txt', 'x');
        $this->write('temperatures/reading.txt', str_repeat('y', 9));

        if (!@symlink($this->root . '/temperatures', $this->root . '/readings')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $names = [];
            foreach (
                DirectorySize::files(
                    $this->root,
                    [$this->root . '/temp'],
                    DirectoryWalk::Archive
                ) as $file
            ) {
                $names[] = str_replace($this->root . '/', '', $file->getPathname());
            }

            $this->assertSame(['temperatures/reading.txt'], $names);
            $this->assertSame(9, DirectorySize::measure(
                $this->root,
                [$this->root . '/temp'],
                DirectoryWalk::Archive
            ), 'Nine bytes once: the link must not count them twice.');
        } finally {
            @unlink($this->root . '/readings');
        }
    }

    /**
     * A directory the iterator cannot even OPEN must not escape the
     * handling written for it.
     *
     * `RecursiveDirectoryIterator` opens the directory in its constructor
     * and throws there. Built above the try/catch, behind an `is_dir()`
     * that had already returned true, that throw escaped: under
     * `Measurement` an unreadable root became an uncaught 500 on the
     * Maintenance page — and on every upload surface once a quota was
     * declared — which is exactly what a lenient measurement exists to
     * prevent.
     *
     * The `is_dir()` pre-check could never have closed it: between the
     * check and the open there is always a window, and a directory removed
     * inside it lands in the constructor anyway. So the check is gone and
     * the constructor is inside the guard, which is what these two assert:
     * an absent tree weighs nothing under BOTH intents, rather than
     * throwing under either.
     */
    public function testAnAbsentDirectoryWeighsNothingRatherThanThrowing(): void
    {
        $gone = $this->root . '/never-created';

        $this->assertSame(0, DirectorySize::measure($gone, [], DirectoryWalk::Measurement));
        $this->assertSame(0, DirectorySize::measure($gone, [], DirectoryWalk::Archive));
    }

    /** And it yields no files either, rather than throwing on the first step. */
    public function testAnAbsentDirectoryYieldsNoFilesUnderEitherIntent(): void
    {
        $gone = $this->root . '/never-created';

        foreach ([DirectoryWalk::Measurement, DirectoryWalk::Archive] as $intent) {
            $this->assertSame(
                [],
                iterator_to_array(DirectorySize::files($gone, [], $intent), false),
                $intent->name . ' should yield nothing for a directory that is not there.'
            );
        }
    }

    /**
     * An archive that skips what it cannot read is worse than one that
     * fails: only the failure is visible before the restore. A
     * measurement, feeding a warning and a refusal-to-write, is the
     * opposite — it must never turn a configuration page into a 500.
     */
    public function testAnUnreadableSubdirectoryIsFatalForAnArchiveAndSkippedForAMeasurement(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads unreadable directories anyway.');
        }

        $this->write('readable/a.txt', str_repeat('x', 10));
        mkdir($this->root . '/locked', 0755, true);
        file_put_contents($this->root . '/locked/secret.txt', 'x');
        chmod($this->root . '/locked', 0000);

        try {
            // The measurement carries on and reports what it could read.
            $this->assertSame(10, DirectorySize::measure($this->root));

            // The archive refuses rather than quietly omitting the subtree.
            $this->expectException(\UnexpectedValueException::class);
            iterator_to_array(DirectorySize::files($this->root, [], DirectoryWalk::Archive));
        } finally {
            chmod($this->root . '/locked', 0755);
        }
    }

    /**
     * The permission test above cannot run as root, which CI is — so this
     * pins the same contract where nothing can skip it. It is the weaker
     * of the two (it checks the intent, not the walk), and it exists
     * because the stronger one is silent exactly where it matters most.
     */
    public function testTheTwoIntentsDifferOnBothBehavioursTogether(): void
    {
        $this->assertFalse(DirectoryWalk::Measurement->followsLinks());
        $this->assertFalse(DirectoryWalk::Measurement->failsOnUnreadable());

        $this->assertTrue(DirectoryWalk::Archive->followsLinks());
        $this->assertTrue(DirectoryWalk::Archive->failsOnUnreadable());
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
