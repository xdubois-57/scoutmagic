<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Phone;

use Core\Phone\CountryCallingCodes;
use PHPUnit\Framework\TestCase;

final class CountryCallingCodesTest extends TestCase
{
    public function testTheCodeIsReadToItsFullLength(): void
    {
        $this->assertSame('1', CountryCallingCodes::of('12025550123'));
        $this->assertSame('32', CountryCallingCodes::of('32472546771'));
        $this->assertSame('33', CountryCallingCodes::of('33612345678'));
        $this->assertSame('352', CountryCallingCodes::of('352691123456'));
        $this->assertSame('423', CountryCallingCodes::of('4231234567'));
        $this->assertSame('383', CountryCallingCodes::of('38344123456'));
    }

    public function testASpareCodeIsNoCode(): void
    {
        $this->assertNull(CountryCallingCodes::of('210123456'));
        $this->assertNull(CountryCallingCodes::of('0123'));
        $this->assertNull(CountryCallingCodes::of(''));
    }

    /**
     * The longest-prefix reading is only unambiguous because E.164 codes
     * are prefix-free; a code added later that broke that would make the
     * answer depend on the loop's direction.
     */
    public function testNoCodeIsThePrefixOfAnother(): void
    {
        $codes = (new \ReflectionClassConstant(CountryCallingCodes::class, 'CODES'))->getValue();
        $this->assertIsArray($codes);

        foreach ($codes as $code) {
            foreach ($codes as $other) {
                $this->assertFalse(
                    $code !== $other && str_starts_with($other, $code),
                    "{$code} is a prefix of {$other}"
                );
            }
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
    }
}
