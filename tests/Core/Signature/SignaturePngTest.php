<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use Core\Signature\SignaturePng;
use PHPUnit\Framework\TestCase;

/**
 * The bytes of a kept signature (issue #926): PNG only, and never printed
 * by a dump or a trace.
 */
final class SignaturePngTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nSECRET-PIXELS";

    public function testItHoldsPngBytes(): void
    {
        $png = new SignaturePng(self::PNG);

        $this->assertSame(self::PNG, $png->bytes());
        $this->assertSame(strlen(self::PNG), $png->byteLength());
    }

    public function testItRefusesBytesThatAreNotAPng(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SignaturePng("\xFF\xD8\xFF a JPEG");
    }

    /** A dump — a log line, a trace — shows the size and never the image. */
    public function testADumpNeverShowsTheBytes(): void
    {
        $dump = print_r(new SignaturePng(self::PNG), true);

        $this->assertStringNotContainsString('SECRET-PIXELS', $dump);
        $this->assertStringContainsString((string) strlen(self::PNG), $dump);
    }
}
