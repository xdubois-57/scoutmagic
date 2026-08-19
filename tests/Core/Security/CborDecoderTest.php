<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\CborDecoder;
use PHPUnit\Framework\TestCase;

class CborDecoderTest extends TestCase
{
    public function testDecodesUnsignedIntegersAcrossEveryArgumentWidth(): void
    {
        $this->assertSame(0, CborDecoder::decodeFirst("\x00")[0]);
        $this->assertSame(23, CborDecoder::decodeFirst("\x17")[0]);
        $this->assertSame(24, CborDecoder::decodeFirst("\x18\x18")[0]);
        $this->assertSame(1000, CborDecoder::decodeFirst("\x19\x03\xe8")[0]);
        $this->assertSame(1000000, CborDecoder::decodeFirst("\x1a\x00\x0f\x42\x40")[0]);
    }

    /**
     * COSE labels are negative integers, and the algorithm identifiers are
     * too (-7 for ES256, -257 for RS256) — getting the -1-n encoding wrong
     * would silently mis-read every key.
     */
    public function testDecodesNegativeIntegers(): void
    {
        $this->assertSame(-1, CborDecoder::decodeFirst("\x20")[0]);
        $this->assertSame(-2, CborDecoder::decodeFirst("\x21")[0]);
        $this->assertSame(-3, CborDecoder::decodeFirst("\x22")[0]);
        $this->assertSame(-7, CborDecoder::decodeFirst("\x26")[0]);
        $this->assertSame(-257, CborDecoder::decodeFirst("\x39\x01\x00")[0]);
    }

    public function testDecodesByteAndTextStrings(): void
    {
        $this->assertSame('abc', CborDecoder::decodeFirst("\x43abc")[0]);
        $this->assertSame('none', CborDecoder::decodeFirst("\x64none")[0]);

        $long = str_repeat("\x11", 40);
        $this->assertSame($long, CborDecoder::decodeFirst("\x58\x28" . $long)[0]);
    }

    public function testDecodesNestedMapsAndArrays(): void
    {
        // {"fmt": "none", "attStmt": {}, "list": [1, 2]}
        $cbor = "\xa3"
            . "\x63" . 'fmt' . "\x64" . 'none'
            . "\x67" . 'attStmt' . "\xa0"
            . "\x64" . 'list' . "\x82\x01\x02";

        [$value, $offset] = CborDecoder::decodeFirst($cbor);

        $this->assertSame('none', $value['fmt']);
        $this->assertSame([], $value['attStmt']);
        $this->assertSame([1, 2], $value['list']);
        $this->assertSame(strlen($cbor), $offset);
    }

    /**
     * A COSE key inside authenticator data is followed by the extensions
     * block, so the decoder must report where the key ended rather than
     * choking on what comes after it.
     */
    public function testReportsTheOffsetJustPastTheFirstItemAndIgnoresTrailingBytes(): void
    {
        $key = "\xa1\x01\x02";

        [$value, $offset] = CborDecoder::decodeFirst($key . 'TRAILING');

        $this->assertSame([1 => 2], $value);
        $this->assertSame(3, $offset);
    }

    /**
     * The old byte-scanning parser would happily "find" a field inside a
     * length prefix or a coordinate. A real decoder refuses truncated
     * input instead of returning a best guess.
     */
    public function testThrowsOnTruncatedInput(): void
    {
        $this->expectException(\RuntimeException::class);
        CborDecoder::decodeFirst("\x58\x20" . str_repeat("\x00", 4));
    }

    public function testThrowsOnAnIndefiniteLength(): void
    {
        $this->expectException(\RuntimeException::class);
        CborDecoder::decodeFirst("\x5f");
    }

    public function testThrowsOnAnEmptyBuffer(): void
    {
        $this->expectException(\RuntimeException::class);
        CborDecoder::decodeFirst('');
    }

    /**
     * A byte string whose declared length overruns the buffer must be
     * rejected, not silently truncated to whatever is available.
     */
    public function testThrowsWhenADeclaredLengthRunsPastTheBuffer(): void
    {
        $this->expectException(\RuntimeException::class);
        CborDecoder::decodeFirst("\x59\xff\xff" . 'short');
    }
}
