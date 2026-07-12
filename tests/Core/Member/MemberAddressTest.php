<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberAddress;
use PHPUnit\Framework\TestCase;

class MemberAddressTest extends TestCase
{
    public function testFormatWithAllFields(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: 'Rue de la Paix',
            number: '123',
            box: '4',
            complement: 'Appartement 5',
            postalCode: '1000',
            city: 'Bruxelles',
            country: 'Belgique'
        );

        $expected = 'Rue de la Paix 123 4, Appartement 5, 1000 Bruxelles, Belgique';
        $this->assertSame($expected, $address->format());
    }

    public function testFormatWithPartialFieldsNoBoxNoComplement(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: 'Avenue des Champs',
            number: '45',
            box: null,
            complement: null,
            postalCode: '75008',
            city: 'Paris',
            country: null
        );

        $expected = 'Avenue des Champs 45, 75008 Paris';
        $this->assertSame($expected, $address->format());
    }

    public function testFormatWithOnlyCityAndCountry(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: null,
            number: null,
            box: null,
            complement: null,
            postalCode: null,
            city: 'Lyon',
            country: 'France'
        );

        $expected = 'Lyon, France';
        $this->assertSame($expected, $address->format());
    }

    public function testFormatWithOnlyStreetAndNumber(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: 'Grand-Place',
            number: '1',
            box: null,
            complement: null,
            postalCode: null,
            city: null,
            country: null
        );

        $expected = 'Grand-Place 1';
        $this->assertSame($expected, $address->format());
    }

    public function testFormatWithEmptyStreet(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: '',
            number: '10',
            box: null,
            complement: null,
            postalCode: '4000',
            city: 'Liège',
            country: 'Belgique'
        );

        $expected = '10, 4000 Liège, Belgique';
        $this->assertSame($expected, $address->format());
    }

    public function testFormatWithComplementOnly(): void
    {
        $address = new MemberAddress(
            type: 'Domicile',
            street: null,
            number: null,
            box: null,
            complement: 'Boîte aux lettres 12',
            postalCode: null,
            city: 'Namur',
            country: null
        );

        $expected = 'Boîte aux lettres 12, Namur';
        $this->assertSame($expected, $address->format());
    }
}
