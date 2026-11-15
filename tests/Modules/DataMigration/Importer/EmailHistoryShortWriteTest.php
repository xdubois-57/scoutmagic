<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Modules\DataMigration\Importer\EmailHistoryImporter;
use PHPUnit\Framework\TestCase;

/**
 * An attachment is streamed out of the archive into a temporary file, and
 * fwrite() may write fewer bytes than asked — a full disk, a quota. The
 * importer's writer retries a short write and reports a write that can go
 * no further, so that `extract()` discards the file instead of handing a
 * truncated one to the upload pipeline (whose checks are of type and size,
 * not of the declared fingerprint).
 *
 * The short writes are real ones: a stream wrapper that accepts only as
 * many bytes per call as its plan says, which is how PHP's own fwrite()
 * comes to return less than it was given.
 */
final class EmailHistoryShortWriteTest extends TestCase
{
    private const SCHEME = 'smshortwrite';

    protected function setUp(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, ShortWriteStream::class);
        }
        ShortWriteStream::$plan = [];
        ShortWriteStream::$written = '';
    }

    public function testShortWritesAreRetriedUntilEveryByteIsWritten(): void
    {
        // 3 bytes, then a stall; 4 more, then a stall; the rest at once.
        ShortWriteStream::$plan = [3, 0, 4, 0];

        $this->assertTrue($this->writeThrough('abcdefghij'));
        $this->assertSame('abcdefghij', ShortWriteStream::$written);
    }

    public function testAWriteThatCanGoNoFurtherIsReported(): void
    {
        // 2 bytes, then nothing more is ever accepted.
        ShortWriteStream::$plan = [2, 0, 0, 0, 0];

        $this->assertFalse($this->writeThrough('abcdefghij'));
        $this->assertSame('ab', ShortWriteStream::$written);
    }

    private function writeThrough(string $chunk): bool
    {
        $handle = fopen(self::SCHEME . '://attachment', 'wb');
        $this->assertIsResource($handle);
        try {
            $writer = new \ReflectionMethod(EmailHistoryImporter::class, 'writeAll');

            return (bool) $writer->invoke(null, $handle, $chunk);
        } finally {
            fclose($handle);
        }
    }
}

/**
 * A writable stream that accepts, per write call, the number of bytes its
 * plan gives next (all of them once the plan is exhausted).
 */
final class ShortWriteStream
{
    /** @var resource|null set by PHP */
    public $context;

    /** @var list<int> */
    public static array $plan = [];

    public static string $written = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $accepted = min(array_shift(self::$plan) ?? strlen($data), strlen($data));
        self::$written .= substr($data, 0, $accepted);

        return $accepted;
    }
}
