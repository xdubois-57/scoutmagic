<?php

declare(strict_types=1);

namespace Tests\Modules\Rental;

use Modules\Rental\Support;
use PHPUnit\Framework\TestCase;

/**
 * The three one-liners this module had nine private copies of. Each test
 * below is the half a copy could plausibly have dropped.
 */
class SupportTest extends TestCase
{
    public function testEurosFormatsForAFrenchReader(): void
    {
        $this->assertSame('2 450,00 €', Support::euros(245000));
        $this->assertSame('0,00 €', Support::euros(0));
        $this->assertSame('45,50 €', Support::euros(4550));
    }

    /**
     * The reason one formatting matters: `Core\Audit` compares nothing and
     * formats nothing, so a price recorded as "2 450,00 €" by one service
     * and "2450.00 EUR" by another reads as two different prices in the
     * same booking's history. This pins the exact spelling.
     */
    public function testTheSpellingIsPinnedDownToTheSeparators(): void
    {
        $this->assertSame('1 234 567,89 €', Support::euros(123456789));
        $this->assertStringEndsWith(' €', Support::euros(100));
        $this->assertStringNotContainsString('.', Support::euros(100));
    }

    public function testEurosHandlesNegativeAmounts(): void
    {
        // A settlement can owe money back.
        $this->assertSame('-45,50 €', Support::euros(-4550));
    }

    public function testOptionalStringTellsBlankFromAbsent(): void
    {
        $this->assertSame('Jeanne', Support::optionalString('  Jeanne '));
        $this->assertNull(Support::optionalString(''));
        $this->assertNull(Support::optionalString('   '), 'a box of spaces is an empty box');
        $this->assertNull(Support::optionalString(null));
        $this->assertNull(Support::optionalString(42), 'a non-string is not a filled-in field');
        $this->assertNull(Support::optionalString(['a']));
    }

    public function testIsDateAcceptsOnlyARealYmdDay(): void
    {
        $this->assertTrue(Support::isDate('2027-07-01'));
        $this->assertTrue(Support::isDate('2028-02-29'), '2028 is a leap year');
    }

    /**
     * The round-trip check, which is the whole reason this is not a bare
     * `createFromFormat()`: PHP accepts the 31st of February and hands
     * back the 3rd of March.
     */
    public function testIsDateRefusesADayThatDoesNotExist(): void
    {
        $this->assertFalse(Support::isDate('2027-02-31'));
        $this->assertFalse(Support::isDate('2027-13-01'));
        $this->assertFalse(Support::isDate('2027-02-29'), '2027 is not a leap year');
        $this->assertFalse(Support::isDate('01/07/2027'));
        $this->assertFalse(Support::isDate(''));
        $this->assertFalse(Support::isDate('2027-7-1'));
    }
}
