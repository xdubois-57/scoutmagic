<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Service\IbanNormalizer;
use PHPUnit\Framework\TestCase;

class IbanNormalizerTest extends TestCase
{
    public function testNormalizeStripsSpacesAndUppercases(): void
    {
        $this->assertSame('BE71096123456769', IbanNormalizer::normalize('be71 0961 2345 6769'));
    }

    public function testNormalizeStripsOtherPunctuation(): void
    {
        $this->assertSame('BE71096123456769', IbanNormalizer::normalize('BE71-0961.2345/6769'));
    }

    public function testIsValidFullIbanAcceptsRealBelgianIban(): void
    {
        $this->assertTrue(IbanNormalizer::isValidFullIban('BE71096123456769'));
    }

    public function testIsValidFullIbanAcceptsRealFrenchIban(): void
    {
        $this->assertTrue(IbanNormalizer::isValidFullIban('FR7630006000011234567890189'));
    }

    public function testIsValidFullIbanRejectsBadChecksum(): void
    {
        $this->assertFalse(IbanNormalizer::isValidFullIban('BE71096123456760'));
    }

    public function testIsValidFullIbanRejectsWrongLengthForKnownCountry(): void
    {
        // Correct checksum for a 15-char BBAN, but BE must be exactly 16.
        $this->assertFalse(IbanNormalizer::isValidFullIban('BE7109612345676'));
    }

    public function testIsValidFullIbanRejectsMalformedInput(): void
    {
        $this->assertFalse(IbanNormalizer::isValidFullIban('NOT AN IBAN'));
        $this->assertFalse(IbanNormalizer::isValidFullIban(''));
    }

    public function testLooksLikeFullIbanTrueForRealLength(): void
    {
        $this->assertTrue(IbanNormalizer::looksLikeFullIban('BE71096123456769'));
    }

    public function testLooksLikeFullIbanFalseForShortFragment(): void
    {
        $this->assertFalse(IbanNormalizer::looksLikeFullIban('BE710000'));
    }

    // --- format(): display only ---

    public function testFormatGroupsByFourForReading(): void
    {
        // How a bank statement prints it, and therefore how somebody
        // checks it against one.
        $this->assertSame('BE71 0961 2345 6769', IbanNormalizer::format('BE71096123456769'));
    }

    public function testFormatIsIdempotentAndTakesAnyInputShape(): void
    {
        $expected = 'BE71 0961 2345 6769';

        $this->assertSame($expected, IbanNormalizer::format($expected), 'already grouped');
        $this->assertSame($expected, IbanNormalizer::format('be71-0961.2345 6769'), 'other punctuation');
        $this->assertSame($expected, IbanNormalizer::format('  BE710961 23456769  '), 'stray spacing');
    }

    public function testFormatLeavesNoTrailingSpaceOnALengthNotDivisibleByFour(): void
    {
        // 15 characters: chunk_split() would leave a trailing separator,
        // which would then be stored or compared by an unwary caller.
        $this->assertSame('NO93 8601 1117 947', IbanNormalizer::format('NO9386011117947'));
    }

    public function testFormatOfNothingIsNothing(): void
    {
        $this->assertSame('', IbanNormalizer::format(''));
        $this->assertSame('', IbanNormalizer::format('   '));
    }

    public function testAFormattedIbanIsNotWhatGetsStoredOrIndexed(): void
    {
        // The point of the whole distinction: normalize() is what a
        // column and a blind index see. A formatted value reaching either
        // makes every IBAN search stop matching — silently, since nothing
        // errors and the lookup merely returns nothing.
        $formatted = IbanNormalizer::format('BE71096123456769');

        $this->assertNotSame($formatted, IbanNormalizer::normalize($formatted));
        $this->assertSame('BE71096123456769', IbanNormalizer::normalize($formatted));
        // And it must not be mistaken for a valid IBAN in its own right:
        // isValidFullIban() documents that it takes a normalize()d value.
        $this->assertFalse(IbanNormalizer::isValidFullIban($formatted));
        $this->assertTrue(IbanNormalizer::isValidFullIban(IbanNormalizer::normalize($formatted)));
    }
}
