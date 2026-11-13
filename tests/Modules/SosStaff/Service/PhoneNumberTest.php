<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Modules\SosStaff\Service\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1125: one GSM, however Desk holds it, is one number — the one sent
 * to the operator and the one its answer is compared with.
 */
final class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function spellingsOfOneMobile(): array
    {
        return [
            'national, spaced' => ['0470 12 34 56'],
            'national, slash and dots' => ['0470/12.34.56'],
            'national, dashes' => ['0470-12-34-56'],
            'national, packed' => ['0470123456'],
            'E.164, spaced' => ['+32 470 12 34 56'],
            'E.164, packed' => ['+32470123456'],
            'international 00' => ['0032470123456'],
            'trunk prefix in brackets' => ['+32 (0)470 12 34 56'],
            'surrounding blanks' => ['  0470 12 34 56 '],
        ];
    }

    #[DataProvider('spellingsOfOneMobile')]
    public function testEverySpellingOfAMobileIsTheSameE164Number(string $spelling): void
    {
        $this->assertSame('+32470123456', PhoneNumber::normalize($spelling));
    }

    public function testABelgianLandLineAndAForeignNumberAreKept(): void
    {
        $this->assertSame('+3221234567', PhoneNumber::normalize('02 123 45 67'));
        // Liège: 04 is a land-line prefix too, 8 significant digits (review of #1194).
        $this->assertSame('+3242223344', PhoneNumber::normalize('04 222 33 44'));
        $this->assertSame('+3243223344', PhoneNumber::normalize('+32 4 322 33 44'));
        $this->assertSame('+33612345678', PhoneNumber::normalize('+33 6 12 34 56 78'));
        $this->assertSame('+33612345678', PhoneNumber::normalize('0033612345678'));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notANumber(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blanks' => ['   '],
            'no prefix at all' => ['470123456'],
            'too short' => ['0470'],
            'a Belgian mobile one digit short' => ['0470 12 34 5'],
            'a 049 mobile one digit short' => ['0495 12 34 5'],
            'a Liège land line one digit long' => ['04 222 33 445'],
            'a Belgian mobile one digit long' => ['0470 12 34 567'],
            'trunk zero kept after +32' => ['+32 0470 12 34 56'],
            'letters' => ['0470 AB 34 56'],
            'more than fifteen digits' => ['+1234567890123456'],
            'a country code starting with 0' => ['+0470123456'],
            'two plus signs' => ['++32470123456'],
        ];
    }

    #[DataProvider('notANumber')]
    public function testWhatCannotBeANumberIsRefusedRatherThanGuessed(?string $raw): void
    {
        $this->assertNull(PhoneNumber::normalize($raw));
    }

    public function testTwoSpellingsOfOneNumberAreTheSame(): void
    {
        $this->assertTrue(PhoneNumber::same('0470 12 34 56', '0032470123456'));
        $this->assertFalse(PhoneNumber::same('0470 12 34 56', '0470 12 34 57'));
    }

    public function testAnUnreadableNumberIsNeverTheSameAsAnything(): void
    {
        $this->assertFalse(PhoneNumber::same('0470', '0470'));
        $this->assertFalse(PhoneNumber::same(null, null));
        $this->assertFalse(PhoneNumber::same('0470 12 34 56', null));
    }
}
