<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Phone;

use Core\Phone\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /**
     * Every case of the mock-up (docs/chantiers/maquettes/maquette-telephone.jsx),
     * plus the zones and countries the chantier names.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function numbers(): array
    {
        return [
            'Belgian mobile, national' => ['0472546771', '+32472546771', '+32 472 54 67 71', true],
            'Belgian mobile, international' => ['+32 472 54 67 71', '+32472546771', '+32 472 54 67 71', true],
            'Belgian mobile, 0032' => ['0032 472 54 67 71', '+32472546771', '+32 472 54 67 71', true],
            'Belgian mobile, 32 without plus' => ['32472546771', '+32472546771', '+32 472 54 67 71', true],
            'Brussels landline, slashes and dots' => ['02/123.45.67', '+3221234567', '+32 2 123 45 67', false],
            'Antwerp landline' => ['03 123 45 67', '+3231234567', '+32 3 123 45 67', false],
            'Liège landline' => ['04 123 45 67', '+3241234567', '+32 4 123 45 67', false],
            'Ghent landline' => ['09 123 45 67', '+3291234567', '+32 9 123 45 67', false],
            'Namur landline' => ['081 22 33 44', '+3281223344', '+32 81 22 33 44', false],
            'French mobile, international' => ['+33 6 12 34 56 78', '+33612345678', '+33 6 12 34 56 78', true],
            'French mobile, 00 prefix' => ['0033 6 12 34 56 78', '+33612345678', '+33 6 12 34 56 78', true],
            'Luxembourg mobile' => ['+352 691 123 456', '+352691123456', '+352 691 123 456', true],
            'Luxembourg mobile, 00 prefix' => ['00352691123456', '+352691123456', '+352 691 123 456', true],
            'Dutch mobile' => ['+31 6 12345678', '+31612345678', '+31 612 34 56 78', true],
            'German landline' => ['+49 30 1234567', '+49301234567', '+49 301 23 45 67', true],
            'code the ITU list does not assign' => ['+210 123 456', '+210123456', '+210123456', true],
        ];
    }

    #[DataProvider('numbers')]
    public function testANumberIsReadOnceForItsLinkItsTextAndItsNature(
        string $raw,
        string $international,
        string $display,
        bool $canReceiveMessages,
    ): void {
        $number = PhoneNumber::parse($raw);

        $this->assertNotNull($number);
        $this->assertSame($international, $number->international);
        $this->assertSame($display, $number->display);
        $this->assertSame($canReceiveMessages, $number->canReceiveMessages);
    }

    /**
     * D7: only 045x–049x on nine digits is a Belgian mobile. A Liège
     * landline starts with 4 too, on eight digits, and must not be offered
     * a message.
     */
    public function testOnlyABelgianMobileRangeReceivesMessages(): void
    {
        $this->assertTrue(PhoneNumber::parse('0456 12 34 56')?->canReceiveMessages);
        $this->assertTrue(PhoneNumber::parse('0499 12 34 56')?->canReceiveMessages);
        $this->assertFalse(PhoneNumber::parse('0412 34 56 78')?->canReceiveMessages);
        $this->assertFalse(PhoneNumber::parse('04 123 45 67')?->canReceiveMessages);
        $this->assertFalse(PhoneNumber::parse('0800 12 345')?->canReceiveMessages);
    }

    public function testATextWithoutADigitIsNoNumber(): void
    {
        $this->assertNull(PhoneNumber::parse(''));
        $this->assertNull(PhoneNumber::parse('   '));
        $this->assertNull(PhoneNumber::parse('--/--'));
        $this->assertNull(PhoneNumber::parse('00'));
    }
}
