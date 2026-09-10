<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Storage\ByteFormatter;
use PHPUnit\Framework\TestCase;

class ByteFormatterTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function formatCases(): array
    {
        return [
            'zero' => [0, '0 o'],
            'bytes' => [512, '512 o'],
            'kilobytes' => [2048, '2 Ko'],
            // No decimal below Go: « 340,0 Mo » only looks more precise
            // than the measurement is.
            'megabytes have no decimal' => [340 * 1024 * 1024, '340 Mo'],
            'gigabytes keep one decimal' => [(int) (1.5 * 1024 * 1024 * 1024), '1,5 Go'],
            // …until 100, past which the digit is noise again.
            'large gigabytes drop it' => [512 * 1024 * 1024 * 1024, '512 Go'],
            'negative reads as empty, never as a negative size' => [-1, '0 o'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formatCases')]
    public function testFormat(int $bytes, string $expected): void
    {
        $this->assertSame($expected, ByteFormatter::format($bytes));
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function parseCases(): array
    {
        return [
            'empty is not stated' => ['', null],
            'blank is not stated' => ['   ', null],
            'a bare number is bytes — the canonical unit' => ['1024', 1024],
            'gigabytes' => ['10 Go', 10 * 1024 * 1024 * 1024],
            'without the space' => ['10Go', 10 * 1024 * 1024 * 1024],
            'lowercase' => ['500 mo', 500 * 1024 * 1024],
            'a French decimal comma' => ['1,5 Go', (int) (1.5 * 1024 * 1024 * 1024)],
            'a decimal point too' => ['1.5 Go', (int) (1.5 * 1024 * 1024 * 1024)],
            // What a copy-paste out of this site's own formatted output
            // carries once the browser has had its way with the space.
            'a non-breaking space' => ["512\u{00A0}Mo", 512 * 1024 * 1024],
            'a unit nobody uses here' => ['10 GB', null],
            'words' => ['beaucoup', null],
            'a negative quota' => ['-5 Go', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('parseCases')]
    public function testParse(string $written, ?int $expected): void
    {
        $this->assertSame($expected, ByteFormatter::parse($written));
    }

    public function testFormatAndParseRoundTripOnAWholeUnit(): void
    {
        $this->assertSame(4 * 1024 * 1024 * 1024, ByteFormatter::parse(ByteFormatter::format(4 * 1024 * 1024 * 1024)));
    }
}
