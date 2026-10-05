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
    public function testTheReferenceIsTheYearThenSixCharacters(): void
    {
        $reference = BookingReference::secure()->draw(new \DateTimeImmutable('2027-03-14 10:00:00'));

        $this->assertMatchesRegularExpression('/^LOC-2027-[' . BookingReference::ALPHABET . ']{6}$/', $reference);
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
        // the end). Walk the whole range through a known sequence.
        $next = 0;
        $reference = new BookingReference(static function (int $min, int $max) use (&$next): int {
            self::assertSame(0, $min);
            self::assertSame(30, $max);

            return $next++ % 31;
        });

        $seen = '';
        for ($i = 0; $i < 6; $i++) {
            $seen .= substr($reference->draw(new \DateTimeImmutable('2027-01-01')), -6);
        }

        $this->assertSame(BookingReference::ALPHABET, substr($seen, 0, 31));
    }

    public function testTheYearIsThatOfTheRequest(): void
    {
        $reference = BookingReference::secure()->draw(new \DateTimeImmutable('2031-12-31 23:59:00'));

        $this->assertStringStartsWith('LOC-2031-', $reference);
    }

    public function testThePatternRecognisesTheRandomAndTheSequentialForm(): void
    {
        $pattern = '/^' . BookingReference::PATTERN . '$/i';

        $this->assertMatchesRegularExpression($pattern, 'LOC-2027-K7Q2MX');
        $this->assertMatchesRegularExpression($pattern, 'loc-2027-k7q2mx', 'A reference retyped in lower case.');
        // A booking made while references were counted keeps its number.
        $this->assertMatchesRegularExpression($pattern, 'LOC-2027-0042');
        $this->assertMatchesRegularExpression($pattern, 'LOC-2027-123456');
    }

    public function testThePatternRefusesWhatNoDrawCanProduce(): void
    {
        $pattern = '/^' . BookingReference::PATTERN . '$/i';

        foreach (['LOC-2027-K7Q2MO', 'LOC-2027-K7Q2MI', 'LOC-2027-K7Q2ML', 'LOC-2027-K7Q2M', 'LOC-2027-K7Q2MXA', 'LOC-27-K7Q2MX'] as $notOne) {
            $this->assertDoesNotMatchRegularExpression($pattern, $notOne, $notOne);
        }
    }

    public function testTwentyDrawsDoNotRepeat(): void
    {
        $references = BookingReference::secure();
        $now = new \DateTimeImmutable('2027-01-01');

        $drawn = [];
        for ($i = 0; $i < 20; $i++) {
            $drawn[] = $references->draw($now);
        }

        $this->assertCount(20, array_unique($drawn));
    }
}
