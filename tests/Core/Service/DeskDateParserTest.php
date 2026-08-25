<?php

declare(strict_types=1);

namespace Tests\Core\Service;

use Core\Service\DeskDateParser;
use PHPUnit\Framework\TestCase;

/**
 * Desk exports the same field in more than one shape; the failure this
 * class prevents reads as "this member has no birth date", never as an
 * error.
 */
class DeskDateParserTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function dateProvider(): array
    {
        return [
            'the comma export ships ISO' => ['2019-05-22', '2019-05-22'],
            'the semicolon export ships Belgian' => ['15/03/2012', '2012-03-15'],
            'a dashed Belgian date' => ['15-03-2012', '2012-03-15'],
            'a datetime column keeps only its date' => ['1998-03-15 00:00:00', '1998-03-15'],
            'an ISO datetime with a T' => ['1998-03-15T09:30:00', '1998-03-15'],
            'surrounding whitespace' => ['  15/03/2012  ', '2012-03-15'],
            'empty is unknown' => ['', null],
            'blank is unknown' => ['   ', null],
            'null is unknown' => [null, null],
            'a word is unknown' => ['inconnu', null],
            // PHP would happily turn 31 February into 3 March; "unknown" is
            // the honest answer for a date that does not exist.
            'an impossible day is unknown' => ['31/02/2012', null],
            'a two-digit year is not one of the shapes Desk uses' => ['15/03/12', null],
        ];
    }

    /** @dataProvider dateProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('dateProvider')]
    public function testItReadsEveryShapeDeskExportsAndNothingElse(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, DeskDateParser::toIso($raw));
    }

    public function testItReturnsADateAtMidnightSoTwoDatesCompareAsDates(): void
    {
        $parsed = DeskDateParser::parse('15/03/2012');

        $this->assertNotNull($parsed);
        $this->assertSame('2012-03-15 00:00:00', $parsed->format('Y-m-d H:i:s'));
    }
}
