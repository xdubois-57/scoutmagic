<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The watcher's own filter, asserted on entries this class creates.
 *
 * Until now the filter was only ever exercised by mutation, which proves it
 * on the day somebody runs one and never again. What made it worth pinning
 * is that both of its failure directions have already happened here:
 *
 * - **too strict** — the first version compared the whole directory, so a
 *   foreign process DELETING one of its own files failed a test about a PDF
 *   renderer (issue #535);
 * - **too lax** — the correction after that asserted only on what appeared,
 *   which a `runc-process…` created inside the render window promptly broke
 *   on a pull request touching neither this module nor this test.
 *
 * A filter written against both has to be checked against both, and the
 * only honest way is to create the three shapes and look.
 *
 * **The entries are created between start() and the assertion**, which is
 * the window that matters: an entry that existed beforehand is in `before`
 * and would be excluded by the difference rather than by the filter, so a
 * test that created them first would pass with no filter at all.
 */
final class TemporaryDirectoryWatchTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->created = [];
    }

    public function testTheTwoForeignShapesDoNotFailTheWatch(): void
    {
        $watch = TemporaryDirectoryWatch::start();

        $this->createDuringTheWindow('runc-process');
        $this->createDuringTheWindow('scoutmagic-e2e-cov-');

        $watch->assertNothingAppeared();
    }

    public function testAnOrdinaryEntryStillFailsIt(): void
    {
        $watch = TemporaryDirectoryWatch::start();

        $ordinary = $this->createDuringTheWindow('sm-watch-fixture-');

        try {
            $watch->assertNothingAppeared();
            $this->fail('an entry a renderer could have written did not fail the watch');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString(
                basename($ordinary),
                $failure->getMessage(),
                'the watch failed without naming the entry that appeared'
            );
        }
    }

    /**
     * And the two together: a foreign entry alongside a real one fails,
     * naming ONLY the real one.
     *
     * This is the assertion the filter could most easily have satisfied the
     * wrong way. A filter that swallowed everything would pass the test
     * above and this one's failure too; what it cannot do is fail while
     * naming exactly one of the three.
     */
    public function testAForeignEntryDoesNotHideARealOne(): void
    {
        $watch = TemporaryDirectoryWatch::start();

        $foreign = $this->createDuringTheWindow('runc-process');
        $ordinary = $this->createDuringTheWindow('sm-watch-fixture-');

        try {
            $watch->assertNothingAppeared();
            $this->fail('a real entry was hidden by the foreign one beside it');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString(basename($ordinary), $failure->getMessage());
            $this->assertStringNotContainsString(
                basename($foreign),
                $failure->getMessage(),
                'the foreign entry was reported, so the filter did not apply'
            );
        }
    }

    /**
     * A directory that cannot be read FAILS, it does not read as empty.
     *
     * `scandir()` documents `false` among its answers, and the watch used
     * to turn it into `[]` — which makes the closing difference empty and
     * `assertNothingAppeared()` pass without ever having looked. A reviewer
     * named it, and it is the defect this whole pull request is about: a
     * test that goes green for a reason unrelated to what it guards.
     *
     * Reached through the directory seam, because the machine's real
     * temporary directory is always readable and a guard nobody can see
     * work is a guard nobody can trust.
     */
    public function testADirectoryThatCannotBeReadFailsRatherThanReadingAsEmpty(): void
    {
        $missing = sys_get_temp_dir() . '/sm-watch-absent-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->assertDirectoryDoesNotExist($missing, 'this test needs a path that is not there');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($missing);

        TemporaryDirectoryWatch::start($missing);
    }

    private function createDuringTheWindow(string $prefix): string
    {
        // Named per process and per call: this runs against the machine's
        // real shared directory, so two runs must not be able to collide,
        // and neither must two entries of one run.
        $path = sys_get_temp_dir() . '/' . $prefix . getmypid() . '-' . bin2hex(random_bytes(4));

        file_put_contents($path, 'fixture');
        $this->created[] = $path;

        return $path;
    }
}
