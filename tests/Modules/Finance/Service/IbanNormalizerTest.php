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
}
