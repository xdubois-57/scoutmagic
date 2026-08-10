<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\AddressNormalizer;
use PHPUnit\Framework\TestCase;

class AddressNormalizerTest extends TestCase
{
    public function testDifferentEncodingsOfTheSameAddressConverge(): void
    {
        $a = AddressNormalizer::normalize('Rue de la Station', '5', null, '1000');
        $b = AddressNormalizer::normalize('R. Station', '5', null, '1000');
        $c = AddressNormalizer::normalize('  RUE   DE  LA   STATION  ', '5', '', '1000');
        $d = AddressNormalizer::normalize('Rue de la Station', '5', null, '1000');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertSame($a, $d);
        $this->assertNotSame('', $a);
    }

    public function testAccentsAndCaseAreIgnored(): void
    {
        $a = AddressNormalizer::normalize('Chaussée de Charleroi', '12', null, '6000');
        $b = AddressNormalizer::normalize('CHAUSSEE DE CHARLEROI', '12', null, '6000');

        $this->assertSame($a, $b);
    }

    public function testAbbreviationsConvergeWithTheFullStreetType(): void
    {
        $a = AddressNormalizer::normalize('Avenue Louise', '100', null, '1050');
        $b = AddressNormalizer::normalize('Av. Louise', '100', null, '1050');

        $this->assertSame($a, $b);
    }

    public function testDuquesnoyKeepsItsDuBecauseItIsAWholeWordNotAStopWordSubstring(): void
    {
        $duquesnoy = AddressNormalizer::normalize('Rue Duquesnoy', '10', null, '1000');

        $this->assertStringContainsString('duquesnoy', $duquesnoy);
    }

    public function testBoisDuDucKeepsDucIntactAndOnlyDropsTheStandaloneArticle(): void
    {
        $result = AddressNormalizer::normalize('Bois du Duc', '3', null, '1300');

        $this->assertStringContainsString('duc', $result);
        $this->assertStringContainsString('bois', $result);
    }

    public function testDifferentStreetsDoNotConverge(): void
    {
        $duquesnoy = AddressNormalizer::normalize('Rue Duquesnoy', '10', null, '1000');
        $duc = AddressNormalizer::normalize('Bois du Duc', '10', null, '1000');

        $this->assertNotSame($duquesnoy, $duc);
    }

    public function testDifferentPostalCodesNeverConverge(): void
    {
        $a = AddressNormalizer::normalize('Rue Duquesnoy', '10', null, '1000');
        $b = AddressNormalizer::normalize('Rue Duquesnoy', '10', null, '1050');

        $this->assertNotSame($a, $b);
    }

    public function testDifferentHouseNumbersAtTheSameStreetDoNotConverge(): void
    {
        $a = AddressNormalizer::normalize('Rue Duquesnoy', '10', null, '1000');
        $b = AddressNormalizer::normalize('Rue Duquesnoy', '12', null, '1000');

        $this->assertNotSame($a, $b);
    }

    public function testEmptyAddressNormalizesToEmptyString(): void
    {
        $this->assertSame('', AddressNormalizer::normalize(null, null, null, null));
        $this->assertSame('', AddressNormalizer::normalize('', '', '', ''));
    }
}
