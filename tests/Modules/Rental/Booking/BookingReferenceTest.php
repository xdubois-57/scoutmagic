<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingReference;
use PHPUnit\Framework\TestCase;

/**
 * A booking reference drawn at random (issue #720, step 9).
 *
 * What is pinned here is what a person relies on: the shape they see on an
 * invoice, an alphabet they can read out on the phone without spelling
 * « zéro, pas la lettre O », and that every character of it can actually
 * come out of a draw.
 */
class BookingReferenceTest extends TestCase
{
    public function testTheReferenceIsSixCharactersWithoutAYear(): void
    {
        $reference = BookingReference::secure()->draw();

        $this->assertMatchesRegularExpression('/^LOC-[' . BookingReference::ALPHABET . ']{6}$/', $reference);
    }

    public function testTheAlphabetHoldsNoCharacterThatReadsLikeAnother(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, BookingReference::ALPHABET);
        }
        $this->assertSame(31, strlen(BookingReference::ALPHABET));
        $this->assertSame(31, count(array_unique(str_split(BookingReference::ALPHABET))));
        $this->assertSame(strtoupper(BookingReference::ALPHABET), BookingReference::ALPHABET);
    }

    public function testEveryCharacterOfTheAlphabetCanBeDrawn(): void
    {
        // The draw indexes the alphabet from 0 to its last position; an
        // off-by-one would silently lose the last character (or read past
        // the end). Each draw here starts with the next position and is
        // completed by « 2A222 », so every one of them is kept.
        $draws = [];
        for ($position = 0; $position < 31; $position++) {
            array_push($draws, $position, 0, 8, 0, 0, 0);
        }
        $reference = new BookingReference(static function (int $min, int $max) use (&$draws): int {
            self::assertSame(0, $min);
            self::assertSame(30, $max);

            return (int) array_shift($draws);
        });

        $seen = '';
        for ($i = 0; $i < 31; $i++) {
            $seen .= substr($reference->draw(), 4, 1);
        }

        $this->assertSame(BookingReference::ALPHABET, $seen);
    }

    public function testADrawOfOnlyOneKindOfCharacterIsDrawnAgain(): void
    {
        // « LOC-MARCHE » and « LOC-234567 » read as ordinary text once there
        // is no year; the draw never hands either out.
        $draws = [
            ...array_fill(0, 6, 8),   // AAAAAA: letters only
            ...array_fill(0, 6, 0),   // 222222: digits only
            0, 8, 0, 8, 0, 8,         // 2A2A2A
        ];
        $reference = new BookingReference(static function () use (&$draws): int {
            return (int) array_shift($draws);
        });

        $this->assertSame('LOC-2A2A2A', $reference->draw());
    }

    public function testASourceStuckOnOneValueGivesUpInsteadOfLoopingForEver(): void
    {
        $reference = new BookingReference(static fn(): int => 0);

        $this->expectException(\RuntimeException::class);
        $reference->draw();
    }

    public function testThePatternRecognisesADrawnReferenceInEitherCase(): void
    {
        $pattern = '/^' . BookingReference::PATTERN . '$/i';

        $this->assertMatchesRegularExpression($pattern, 'LOC-K7Q2MX');
        $this->assertMatchesRegularExpression($pattern, 'loc-k7q2mx', 'A reference retyped in lower case.');
        $this->assertMatchesRegularExpression($pattern, 'LOC-2A2A2A');
    }

    public function testThePatternRefusesWhatNoDrawCanProduce(): void
    {
        $pattern = '/^' . BookingReference::PATTERN . '$/i';

        $notOnes = [
            'LOC-K7Q2MO', 'LOC-K7Q2MI', 'LOC-K7Q2ML', 'LOC-K7Q2M', 'LOC-K7Q2MXA',
            'LOC-MARCHE', 'LOC-234567',
            // The earlier forms, with a year: no longer references (#720).
            'LOC-2027-K7Q2MX', 'LOC-2027-0042',
        ];
        foreach ($notOnes as $notOne) {
            $this->assertDoesNotMatchRegularExpression($pattern, $notOne, $notOne);
        }
    }

    public function testTwentyDrawsDoNotRepeat(): void
    {
        $references = BookingReference::secure();

        $drawn = [];
        for ($i = 0; $i < 20; $i++) {
            $drawn[] = $references->draw();
        }

        $this->assertCount(20, array_unique($drawn));
    }
}
