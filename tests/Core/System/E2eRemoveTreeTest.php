<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

/**
 * `e2eRemoveTree()` is a cleanup, not a requirement that the thing exists
 * (issue #1384). The runner calls it on `storage/temp/twig_cache`, a derived
 * cache a fresh checkout does not have yet: it must pass in silence, because
 * a runner log full of `scandir` / `unlink` / `rmdir` warnings sends the
 * developer looking for a failure that is not there.
 *
 * Warnings are turned into exceptions here, as the ticket's reproduction
 * does, so a warning cannot go unnoticed in a test run.
 */
class E2eRemoveTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/e2e_remove_tree_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        if (is_dir($this->root)) {
            // Plain PHP, not the function under test: the cleanup must not depend on it.
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testAPathThatDoesNotExistIsRemovedInSilence(): void
    {
        $absent = $this->root . '/twig_cache';

        \e2eRemoveTree($absent);

        $this->assertDirectoryDoesNotExist($absent);
    }

    public function testAPathWhoseParentDoesNotExistEitherIsRemovedInSilence(): void
    {
        \e2eRemoveTree($this->root . '/storage/temp/twig_cache');

        $this->assertDirectoryDoesNotExist($this->root . '/storage');
    }

    public function testATreeThatExistsIsStillRemovedWhole(): void
    {
        $tree = $this->root . '/cache';
        mkdir($tree . '/a/b', 0o755, true);
        file_put_contents($tree . '/top.php', 'x');
        file_put_contents($tree . '/a/mid.php', 'x');
        file_put_contents($tree . '/a/b/leaf.php', 'x');
        mkdir($tree . '/empty');

        \e2eRemoveTree($tree);

        $this->assertDirectoryDoesNotExist($tree);
    }

    /**
     * An instance's `core`, `modules` and `vendor` are symlinks into the
     * repository itself: they are unlinked, never followed, or the cleanup
     * would empty the checkout it is running in.
     */
    public function testASymlinkInsideTheTreeIsUnlinkedNotFollowed(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.php', 'x');
        $tree = $this->root . '/instance';
        mkdir($tree);
        symlink($outside, $tree . '/vendor');

        \e2eRemoveTree($tree);

        $this->assertDirectoryDoesNotExist($tree);
        $this->assertFileExists($outside . '/keep.php', 'the cleanup followed a symlink out of its tree');
    }

    /** A real failure to delete is still a failure, not something to swallow. */
    public function testARealFailureToRemoveStillWarns(): void
    {
        $tree = $this->root . '/locked';
        mkdir($tree);
        file_put_contents($tree . '/file.php', 'x');
        // A non-empty directory the function cannot empty: its file is made a directory it cannot recurse into.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can remove anything: a failed deletion cannot be provoked');
        }
        chmod($tree, 0o555);

        try {
            $this->expectException(\ErrorException::class);
            \e2eRemoveTree($tree);
        } finally {
            chmod($tree, 0o755);
        }
    }
}
