<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\OpcodeCache;
use PHPUnit\Framework\TestCase;

/**
 * The contract here is mostly "never gets in the way": OPcache is off for
 * CLI by default (`opcache.enable_cli`), so this suite usually runs with
 * no cache to talk to at all — which is exactly the shared-hosting case
 * the class has to survive. What is pinned is that every entry point is
 * safe to call blind, returns something honest, and never throws.
 */
final class OpcodeCacheTest extends TestCase
{
    public function testAvailabilityIsAnswerableWithoutTheExtension(): void
    {
        // No assertion on WHICH answer: it depends on how the test runner's
        // PHP is built. That it answers at all, without a warning or a
        // fatal, is the invariant.
        $this->assertIsBool(OpcodeCache::isAvailable());
    }

    public function testInvalidatingFilesIsSafeWhenThereIsNoCache(): void
    {
        $dropped = OpcodeCache::invalidateFiles([
            __FILE__,
            '/nonexistent/path/that/was/never/compiled.php',
        ]);

        $this->assertGreaterThanOrEqual(0, $dropped);
        $this->assertLessThanOrEqual(2, $dropped);
    }

    public function testInvalidatingNothingIsNotAnError(): void
    {
        $this->assertSame(0, OpcodeCache::invalidateFiles([]));
    }

    public function testResetReportsWhetherItActuallyHappened(): void
    {
        // false on a CLI run with opcache.enable_cli off — a truthful "no",
        // not a failure the caller has to handle.
        $this->assertIsBool(OpcodeCache::reset());
    }

    /**
     * invalidateFiles() takes an iterable so the caller can stream a list
     * it built while copying, without materialising a second array.
     */
    public function testInvalidateAcceptsAGenerator(): void
    {
        $paths = (static function (): \Generator {
            yield __FILE__;
        })();

        $this->assertIsInt(OpcodeCache::invalidateFiles($paths));
    }
}
