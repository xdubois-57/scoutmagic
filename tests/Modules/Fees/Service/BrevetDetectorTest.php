<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Modules\Fees\Service\BrevetDetector;
use PHPUnit\Framework\TestCase;

/**
 * The federation's own formation wording, verbatim, is all the site has.
 * A folded "brevet" recognises the usual ones — and a wording it does not
 * recognise is "the site cannot say", never "no brevet".
 */
class BrevetDetectorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('wordingProvider')]
    public function testAWordingIsRecognisedOrNot(?string $wording, bool $expected): void
    {
        $this->assertSame($expected, BrevetDetector::isBrevet($wording));
    }

    /** @return array<string, array{?string, bool}> */
    public static function wordingProvider(): array
    {
        return [
            'the plain wording' => ['Brevet', true],
            'the full one' => ["Brevet d'animateur", true],
            'lower case' => ['brevet danimateur', true],
            'accents around it' => ['Breveté fédéral', true],
            'buried in a sentence' => ['Formation : nœud brevet obtenu', true],
            'a step that is not one' => ['Formation en cours', false],
            'the empty wording' => ['', false],
            'blanks only' => ['   ', false],
            'nothing at all' => [null, false],
        ];
    }
}
