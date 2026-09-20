<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\BoundedArchive;
use PHPUnit\Framework\TestCase;

/**
 * The archive a stranger sent, and what it is not allowed to do.
 */
class BoundedArchiveTest extends TestCase
{
    private BoundedArchive $archive;

    protected function setUp(): void
    {
        $this->archive = new BoundedArchive();
    }

    /** The ordinary case: Google sends its aggregate report gzipped. */
    public function testAGzippedReportIsRead(): void
    {
        $xml = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';

        $members = $this->archive->membersOf((string) gzencode($xml), 'application/gzip');

        $this->assertSame([$xml], $members);
    }

    /**
     * **The reason this class exists.**
     *
     * Forty megabytes of zeroes compress to a few dozen kilobytes, which
     * is small enough to sail through every size check upstream — the
     * attachment cap, the payload cap, the mail server. Decompressing it
     * without a ceiling is what takes the process, and the shared host it
     * sits on, with it.
     *
     * **Peak usage, reset immediately before the call, and not
     * `memory_get_usage()`.** The first version of this test measured
     * allocation before and after, which a naive implementation passes
     * comfortably: it unpacks the whole forty megabytes, measures them,
     * answers « too large », and frees everything before the second
     * reading is taken. The test looked like it pinned the ceiling and
     * pinned nothing — it was verified by replacing the streamed inflate
     * with `gzdecode()`, and it still passed. Peak is the figure that
     * cannot be handed back.
     */
    public function testADecompressionBombIsRefusedWithoutBeingUnpacked(): void
    {
        $bomb = (string) gzencode(str_repeat("\0", 40 * 1024 * 1024));
        $this->assertLessThan(
            200_000,
            strlen($bomb),
            'the premise: it is small enough that nothing upstream would stop it.'
        );

        // PHP's own accounting, not the OS arena: `memory_get_*(true)`
        // reports what the allocator holds, and it does not hand back the
        // block this test used to build the bomb in the first place.
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $members = $this->archive->membersOf($bomb, 'application/gzip');
        $grew = memory_get_peak_usage() - $before;

        $this->assertSame([], $members);
        $this->assertLessThan(
            BoundedArchive::MAX_ENTRY_BYTES * 4,
            $grew,
            'it stopped at the ceiling rather than unpacking forty megabytes to measure them.'
        );
    }

    /** A member exactly at the ceiling is still a member. */
    public function testAMemberAtTheCeilingIsAccepted(): void
    {
        $payload = str_repeat('a', BoundedArchive::MAX_ENTRY_BYTES);

        $members = $this->archive->membersOf((string) gzencode($payload), 'application/gzip');

        $this->assertCount(1, $members);
        $this->assertSame(BoundedArchive::MAX_ENTRY_BYTES, strlen($members[0]));
    }

    /** And one byte past it is not. */
    public function testAMemberOneBytePastTheCeilingIsRefused(): void
    {
        $payload = str_repeat('a', BoundedArchive::MAX_ENTRY_BYTES + 1);

        $this->assertSame([], $this->archive->membersOf((string) gzencode($payload), 'application/gzip'));
    }

    /** Bytes that are not an archive at all: nothing, and no exception. */
    public function testRubbishIsRefusedRatherThanThrown(): void
    {
        $this->assertSame([], $this->archive->membersOf('not an archive', 'application/gzip'));
        $this->assertSame([], $this->archive->membersOf('not an archive', 'application/zip'));
        $this->assertSame([], $this->archive->membersOf('', 'application/gzip'));
    }

    /** A type this class does not open is simply not opened. */
    public function testAnUnknownTypeYieldsNothing(): void
    {
        $this->assertSame([], $this->archive->membersOf('anything', 'application/pdf'));
    }

    /** Some reporters send the XML with no wrapper at all. */
    public function testPlainXmlPassesThrough(): void
    {
        $xml = '<?xml version="1.0"?><feedback/>';

        $this->assertSame([$xml], $this->archive->membersOf($xml, 'application/xml'));
        $this->assertSame([$xml], $this->archive->membersOf($xml, 'text/xml'));
    }

    public function testPlainXmlPastTheCeilingIsRefused(): void
    {
        $this->assertSame(
            [],
            $this->archive->membersOf(str_repeat('a', BoundedArchive::MAX_ENTRY_BYTES + 1), 'application/xml')
        );
    }
}
