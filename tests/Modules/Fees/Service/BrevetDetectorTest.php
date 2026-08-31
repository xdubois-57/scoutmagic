<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Modules\Fees\Service\BrevetDetector;
use Modules\Leadership\Api\FormationLevel;
use Modules\Leadership\Api\FormationLevelInterface;
use PHPUnit\Framework\TestCase;

/**
 * Two readings of the federation's own formation wording: the unit's own,
 * through the leadership module's `Api\FormationLevelInterface`, and this
 * module's fallback when that module is switched off (roadmap IT-21).
 *
 * A wording neither one recognises is "the site cannot say", never "no
 * brevet" — the whole reason the invoice check reports such a line as
 * undetermined instead of as a missing reduction.
 */
class BrevetDetectorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('wordingProvider')]
    public function testTheFallbackReadingRecognisesTheUsualWordings(?string $wording, bool $expected): void
    {
        $this->assertSame($expected, (new BrevetDetector())->isBrevet($wording));
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

            // The defect that started IT-21: « BACV » does not contain
            // "brevet", so every breveté of a unit whose export words it
            // that way was reported as undetermined.
            'the BACV' => ['BACV', true],
            'the BACV in a sentence' => ['Animateur BACV 2025', true],
            'the woodbadge, one word' => ['Woodbadge', true],
            'the woodbadge, two words' => ['Wood Badge', true],

            'a step that is not one' => ['Formation en cours', false],
            'a T-step' => ['T2', false],
            'the empty wording' => ['', false],
            'blanks only' => ['   ', false],
            'nothing at all' => [null, false],
        ];
    }

    /**
     * With the interface, the answer is the unit's own decision about its
     * own vocabulary — including a wording the fallback could never have
     * recognised, which is the point of consuming it.
     */
    public function testWithTheInterfaceTheUnitsOwnMappingDecides(): void
    {
        $detector = new BrevetDetector($this->levels(['Wording maison' => true]));

        $this->assertTrue($detector->isBrevet('Wording maison'));
        $this->assertFalse($detector->isBrevet('Formation en cours'));
    }

    /**
     * The interface answers `countsForFederationDiscount`, which is a
     * different question from the ONE ratio: the Woodbadge opens the
     * reduction and is not counted by the ONE.
     */
    public function testTheInterfaceAnswerIsTheDiscountQuestionNotTheRatioOne(): void
    {
        $levels = new class implements FormationLevelInterface {
            public function resolve(?string $rawFormationLevel): FormationLevel
            {
                return new FormationLevel(
                    code: 'woodbadge',
                    label: 'Woodbadge',
                    recognised: true,
                    countsForOneRatio: false,
                    countsForFederationDiscount: true
                );
            }
        };

        $this->assertTrue((new BrevetDetector($levels))->isBrevet('Woodbadge'));
    }

    /**
     * An empty value never reaches the interface: Desk leaves the column
     * empty for somebody who has not started, and that is not a question
     * about vocabulary.
     */
    public function testAnEmptyWordingIsAnsweredWithoutAskingAnybody(): void
    {
        $levels = new class implements FormationLevelInterface {
            public int $calls = 0;

            public function resolve(?string $rawFormationLevel): FormationLevel
            {
                $this->calls++;

                return new FormationLevel('brevet', 'Brevet non précisé', true, false, true);
            }
        };

        $detector = new BrevetDetector($levels);

        $this->assertFalse($detector->isBrevet(null));
        $this->assertFalse($detector->isBrevet('   '));
        $this->assertSame(0, $levels->calls);
    }

    /**
     * An invoice line's own words are read by this module and never
     * through a unit's mapping of what its PEOPLE's wordings mean —
     * otherwise a unit mapping « Zorglub » onto a brevet would turn every
     * line mentioning it into a brevet reduction.
     */
    public function testALineDescriptorIsReadFromTheDocumentAlone(): void
    {
        $this->assertTrue(BrevetDetector::mentionsBrevet('RED-BREV Réduction brevet'));
        $this->assertTrue(BrevetDetector::mentionsBrevet('RED-BACV Réduction BACV'));
        $this->assertFalse(BrevetDetector::mentionsBrevet('COT-STD Cotisation standard'));
        $this->assertFalse(BrevetDetector::mentionsBrevet(null));
    }

    /**
     * @param array<string, bool> $discountByWording
     */
    private function levels(array $discountByWording): FormationLevelInterface
    {
        return new class ($discountByWording) implements FormationLevelInterface {
            /** @param array<string, bool> $discountByWording */
            public function __construct(private array $discountByWording)
            {
            }

            public function resolve(?string $rawFormationLevel): FormationLevel
            {
                $discount = $this->discountByWording[(string) $rawFormationLevel] ?? false;

                return new FormationLevel(
                    code: $discount ? 'bacv' : 'unknown',
                    label: $discount ? 'BACV' : 'Niveau non reconnu',
                    recognised: $discount,
                    countsForOneRatio: $discount,
                    countsForFederationDiscount: $discount
                );
            }
        };
    }
}
