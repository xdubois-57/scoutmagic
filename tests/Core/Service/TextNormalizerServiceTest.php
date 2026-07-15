<?php

declare(strict_types=1);

namespace Tests\Core\Service;

use Core\Service\TextNormalizerService;
use PHPUnit\Framework\TestCase;

class TextNormalizerServiceTest extends TestCase
{
    /**
     * @dataProvider nameProvider
     */
    public function testNormalizeName(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalizerService::normalizeName($input));
    }

    /** @return array<string, array{string, string}> */
    public static function nameProvider(): array
    {
        return [
            'all caps simple' => ['DUPONT', 'Dupont'],
            'all lowercase' => ['jean dupont', 'Jean Dupont'],
            'mixed case' => ['jEaN dUpOnT', 'Jean Dupont'],
            'particle van den' => ['VAN DEN BERG', 'Van den Berg'],
            'particle de' => ['DE SMET', 'De Smet'],
            'particle de la mid' => ['MARIE DE LA CROIX', 'Marie de la Croix'],
            'first word particle capitalized' => ['de smet', 'De Smet'],
            'hyphenated first name' => ['JEAN-PHILIPPE', 'Jean-Philippe'],
            'apostrophe' => ["D'HONDT", "D'Hondt"],
            'apostrophe mid lowercased only for particle' => ["JEAN D'ARC", "Jean D'Arc"],
            'collapse spaces' => ['  van   der   meer ', 'Van der Meer'],
            'empty' => ['', ''],
            'accented' => ['NOËL', 'Noël'],
        ];
    }

    /**
     * @dataProvider totemProvider
     */
    public function testNormalizeTotem(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalizerService::normalizeTotem($input));
    }

    /** @return array<string, array{string, string}> */
    public static function totemProvider(): array
    {
        return [
            'all caps two words' => ['RENARD ESPIÈGLE', 'Renard espiègle'],
            'lowercase' => ['renard', 'Renard'],
            'mixed' => ['ReNaRd', 'Renard'],
            'collapse and trim' => ['  loup   agile  ', 'Loup agile'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testNormalizePhone(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalizerService::normalizePhone($input));
    }

    /** @return array<string, array{string, string}> */
    public static function phoneProvider(): array
    {
        return [
            'mobile with 0 and spaces' => ['0476 12 34 56', '+32 476 12 34 56'],
            'mobile no separators' => ['0476123456', '+32 476 12 34 56'],
            'mobile already international' => ['+32 476 12 34 56', '+32 476 12 34 56'],
            'mobile with dots' => ['0476.12.34.56', '+32 476 12 34 56'],
            'mobile with slash' => ['0476/12.34.56', '+32 476 12 34 56'],
            'mobile starting 32 no plus' => ['32476123456', '+32 476 12 34 56'],
            'brussels landline' => ['02 345 67 89', '+32 2 345 67 89'],
            'namur landline 2-digit' => ['081 22 33 44', '+32 81 22 33 44'],
            'foreign number' => ['+33 1 23 45 67 89', '+33 123 45 67 89'],
            'empty' => ['', ''],
            'only separators' => ['--/--', ''],
        ];
    }

    /**
     * @dataProvider addressProvider
     */
    public function testNormalizeAddress(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalizerService::normalizeAddress($input));
    }

    /** @return array<string, array{string, string}> */
    public static function addressProvider(): array
    {
        return [
            'street with particle' => ['RUE DE LA STATION', 'Rue de la Station'],
            'avenue' => ['AVENUE LOUISE', 'Avenue Louise'],
            'chaussee accented' => ['CHAUSSÉE DE WATERLOO', 'Chaussée de Waterloo'],
            'keeps house number' => ['RUE DU MOULIN 12', 'Rue du Moulin 12'],
            'keeps postal code and city' => ['PLACE VERTE 1000 BRUXELLES', 'Place Verte 1000 Bruxelles'],
            'alphanumeric number kept' => ['RUE HAUTE 12A', 'Rue Haute 12A'],
            'empty' => ['', ''],
        ];
    }
}
