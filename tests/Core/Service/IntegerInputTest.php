<?php

declare(strict_types=1);

namespace Tests\Core\Service;

use Core\Service\IntegerInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntegerInputTest extends TestCase
{
    public function testAnOrdinaryNumberIsRead(): void
    {
        $this->assertSame(12, IntegerInput::unsigned('12'));
        $this->assertSame(12, IntegerInput::unsigned(12));
        $this->assertSame(12, IntegerInput::unsigned('  12  '));
        $this->assertSame(7, IntegerInput::unsigned('007'), 'leading zeros are notation, not a different number');
        $this->assertSame(0, IntegerInput::unsigned('0'));
    }

    /**
     * The finding this class exists for: an INT UNSIGNED column holds up
     * to 4 294 967 295, a form will happily post more, and the cast
     * preserves it all the way to MySQL — which refuses it as a
     * PDOException nobody catches.
     */
    public function testAValueTheColumnCouldNotHoldIsRefused(): void
    {
        $this->assertSame(4294967295, IntegerInput::unsigned('4294967295'), 'the ceiling itself still fits');
        $this->assertNull(IntegerInput::unsigned('4294967296'));
        $this->assertNull(IntegerInput::unsigned('99999999999999999999999999'));
    }

    /**
     * Past PHP_INT_MAX the cast saturates instead of failing, so a naive
     * `(int) $v <= $max` check passes for a number that is nothing like
     * what was typed. This pins the trap itself, so the guard cannot be
     * simplified away by someone who does not know it is there.
     */
    public function testTheCastReallyDoesSaturateOnThatInput(): void
    {
        $this->assertSame(PHP_INT_MAX, (int) '99999999999999999999999999');
        $this->assertNull(IntegerInput::bounded('99999999999999999999999999', 0, PHP_INT_MAX));
    }

    #[DataProvider('valuesThatAreNotWholeNumbers')]
    public function testNothingIsSalvagedFromAValueThatIsNotANumber(mixed $value): void
    {
        $this->assertNull(IntegerInput::unsigned($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function valuesThatAreNotWholeNumbers(): array
    {
        return [
            'a number with a unit' => ['12 places'],
            'a decimal' => ['12.5'],
            'a whole decimal' => ['12.0'],
            'scientific notation' => ['1e3'],
            'hexadecimal' => ['0x1A'],
            'a word' => ['douze'],
            'empty' => [''],
            'blank' => ['   '],
            'absent' => [null],
            'an array' => [[12]],
            'a boolean' => [true],
        ];
    }

    public function testTheCastReallyWouldHaveSalvagedThose(): void
    {
        $this->assertSame(12, (int) '12 places');
        $this->assertSame(12, (int) '12.5');
    }

    public function testABoundIsInclusiveAtBothEnds(): void
    {
        $this->assertSame(1, IntegerInput::bounded('1', 1, 10));
        $this->assertSame(10, IntegerInput::bounded('10', 1, 10));
        $this->assertNull(IntegerInput::bounded('0', 1, 10));
        $this->assertNull(IntegerInput::bounded('11', 1, 10));
    }

    public function testANegativeValueIsRefusedForAnUnsignedColumn(): void
    {
        $this->assertNull(IntegerInput::unsigned('-1'));
        $this->assertSame(-1, IntegerInput::bounded('-1', -10, 10));
        $this->assertSame(0, IntegerInput::bounded('-0', -10, 10));
    }

    /**
     * Out of range is refused, never quietly reduced to the ceiling —
     * storing a number the visitor did not choose is the failure this
     * class is meant to prevent, not a friendlier version of it.
     */
    public function testAnOutOfRangeValueIsRefusedRatherThanClamped(): void
    {
        $this->assertNull(IntegerInput::bounded('5000', 0, 100));
        $this->assertNotSame(100, IntegerInput::bounded('5000', 0, 100));
    }

    public function testUnsignedOrFallsBackWithoutComplaining(): void
    {
        $this->assertSame(5, IntegerInput::unsignedOr('douze', 5));
        $this->assertSame(12, IntegerInput::unsignedOr('12', 5));
        $this->assertSame(5, IntegerInput::unsignedOr(null, 5));
    }
}
