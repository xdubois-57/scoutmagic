<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

use Modules\Fees\Invoice\BelgianNumber;
use PHPUnit\Framework\TestCase;

/**
 * The most frequent way a reading of a document like this silently
 * produces a total of zero.
 */
class BelgianNumberTest extends TestCase
{
    /** @return array<string, array{string, ?int}> */
    public static function amountProvider(): array
    {
        return [
            'plain' => ['39,00', 3900],
            'no decimals' => ['25', 2500],
            'one decimal' => ['25,5', 2550],
            'negative' => ['-100,00', -10000],
            'non-breaking thousands separator' => ["1\u{00A0}215,00", 121500],
            'narrow non-breaking space' => ["1\u{202F}215,00", 121500],
            'dot as a thousands separator' => ['1.215,00', 121500],
            'a dot as a decimal separator is not this format' => ['39.00', null],
            'a bare word' => ['Montant', null],
            'empty' => ['', null],
        ];
    }

    /** @dataProvider amountProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountProvider')]
    public function testItReadsTheAmountsThisDocumentPrints(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, BelgianNumber::toCents($raw));
    }

    /**
     * The trap: an ordinary space separates the COLUMNS of a line, and
     * collapsing it would turn a quantity followed by an amount into one
     * number.
     */
    public function testAnOrdinarySpaceIsNeverAThousandsSeparator(): void
    {
        $this->assertSame('39,00 3 117,00', BelgianNumber::collapseGroupSeparators('39,00 3 117,00'));
        $this->assertNull(BelgianNumber::toCents('3 117,00'));
    }

    public function testOnlyTheSeparatorInsideANumberIsCollapsed(): void
    {
        $this->assertSame(
            'COT_NORM Cotisation normale SV025B1 39,00 32 1248,00',
            BelgianNumber::collapseGroupSeparators("COT_NORM Cotisation normale SV025B1 39,00 32 1\u{00A0}248,00")
        );
    }

    /** Integers all the way: an invoice that must fall on the centime cannot round. */
    public function testTheCentIsExact(): void
    {
        $this->assertSame(1, BelgianNumber::toCents('0,01'));
        $this->assertSame(-1, BelgianNumber::toCents('-0,01'));
        $this->assertSame(106900, BelgianNumber::toCents("1\u{00A0}069,00"));
    }

    public function testItWritesAnAmountBackTheWayAReaderExpects(): void
    {
        $this->assertSame("1\u{00A0}069,00\u{00A0}€", BelgianNumber::format(106900));
        $this->assertSame("-100,00\u{00A0}€", BelgianNumber::format(-10000));
    }
}
