<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\FormationStep;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Service\SupervisionCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupervisionCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int}> headcount → animators, brevets
     */
    public static function ratioCases(): array
    {
        return [
            'nobody' => [0, 0, 0],
            'one child' => [1, 1, 1],
            'exactly one animator worth' => [12, 1, 1],
            'one over' => [13, 2, 1],
            'three animators worth' => [36, 3, 1],
            'four animators needs two brevets' => [37, 4, 2],
            'a big section' => [100, 9, 3],
        ];
    }

    #[DataProvider('ratioCases')]
    public function testTheOneRatioRoundsUpOnBothHalves(int $headcount, int $animators, int $brevets): void
    {
        $required = LeadershipRules::oneRequirementFor($headcount);

        $this->assertSame($animators, $required['animators']);
        $this->assertSame($brevets, $required['brevets']);
    }

    public function testSatisfiedWhenBothHalvesAreMet(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(24, [
            FormationStep::BACV,
            FormationStep::T2,
        ]);

        $this->assertSame(2, $situation->requiredAnimators);
        $this->assertSame(1, $situation->requiredBrevets);
        $this->assertSame(2, $situation->animatorCount);
        $this->assertSame(1, $situation->brevetCount);
        $this->assertTrue($situation->satisfied);
    }

    public function testNotSatisfiedWhenBrevetsAreShort(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(24, [
            FormationStep::T1,
            FormationStep::T2,
        ]);

        $this->assertFalse($situation->satisfied);
    }

    public function testNotSatisfiedWhenAnimatorsAreShort(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(40, [
            FormationStep::BACV,
            FormationStep::BACV,
        ]);

        $this->assertSame(4, $situation->requiredAnimators);
        $this->assertFalse($situation->satisfied);
    }

    /**
     * The ONE recognises the BACV. The Woodbadge is internal to scouting
     * and carries no ONE recognition, so it is not a breveté here however
     * much training it represents — this figure answers one body's
     * question, not the site's opinion of somebody's competence.
     */
    public function testAWoodbadgeIsNotCountedInTheOneRatio(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(12, [FormationStep::WOODBADGE]);

        $this->assertSame(0, $situation->brevetCount);
        $this->assertFalse($situation->satisfied);
    }

    /**
     * A brevet whose kind nobody recorded might be a BACV — which a
     * regulatory figure may not read as "is". It is not an unrecognised
     * level either: the site knows exactly what it says, and the page names
     * it in its own sentence rather than in the list of values to map.
     */
    public function testAnUnspecifiedBrevetIsNeitherCountedNorTreatedAsUnrecognised(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(12, [FormationStep::BREVET]);

        $this->assertSame(0, $situation->brevetCount);
        $this->assertSame(0, $situation->unknownLevelCount);
        $this->assertFalse($situation->satisfied);
        $this->assertFalse(
            $situation->mayBeIncomplete,
            'the « valeurs non reconnues » list would not contain it, so pointing at that list would send a chief nowhere'
        );
    }

    /**
     * The asymmetry the whole design rests on: an unresolvable level is
     * never counted as a brevet, so the count is a floor.
     */
    public function testAnUnknownLevelIsNeverCountedAsABrevet(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(12, [FormationStep::UNKNOWN]);

        $this->assertSame(0, $situation->brevetCount);
        $this->assertSame(1, $situation->unknownLevelCount);
        $this->assertFalse($situation->satisfied);
    }

    /**
     * Met is met for certain: whatever the unrecognised values turn out to
     * be, they could only raise the count further. Warning here would train
     * readers to ignore the warning.
     */
    public function testAMetThresholdNeverWarnsEvenWithUnknownLevels(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(12, [
            FormationStep::BACV,
            FormationStep::UNKNOWN,
        ]);

        $this->assertTrue($situation->satisfied);
        $this->assertSame(1, $situation->unknownLevelCount);
        $this->assertFalse($situation->mayBeIncomplete);
    }

    public function testAnUnmetThresholdWarnsWhenMappingCouldFlipIt(): void
    {
        // 12 children → 1 animateur, 1 brevet. Two animateurs, neither
        // resolvable: mapping either one could meet the threshold.
        $situation = (new SupervisionCalculator())->evaluate(12, [
            FormationStep::UNKNOWN,
            FormationStep::T1,
        ]);

        $this->assertFalse($situation->satisfied);
        $this->assertTrue($situation->mayBeIncomplete);
    }

    /**
     * A section short of animateurs is short of them whatever their levels
     * say — no mapping changes a headcount, so there is nothing uncertain
     * to report.
     */
    public function testNoWarningWhenTheAnimatorHalfIsWhatIsMissing(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(40, [FormationStep::UNKNOWN]);

        $this->assertFalse($situation->satisfied);
        $this->assertFalse($situation->mayBeIncomplete);
    }

    /**
     * Nor when even counting every unknown as a brevet would still fall
     * short: the verdict is certain, just not the one anybody wanted.
     */
    public function testNoWarningWhenEveryUnknownWouldStillNotBeEnough(): void
    {
        // 37 children → 4 animateurs, 2 brevets. Four animateurs, one
        // unknown, no brevet: 0 + 1 < 2.
        $situation = (new SupervisionCalculator())->evaluate(37, [
            FormationStep::UNKNOWN,
            FormationStep::T1,
            FormationStep::T1,
            FormationStep::NONE,
        ]);

        $this->assertFalse($situation->satisfied);
        $this->assertFalse($situation->mayBeIncomplete);
    }

    public function testASectionWithNoChildrenAsksForNothing(): void
    {
        $situation = (new SupervisionCalculator())->evaluate(0, []);

        $this->assertSame(0, $situation->requiredAnimators);
        $this->assertSame(0, $situation->requiredBrevets);
        $this->assertTrue($situation->satisfied);
        $this->assertFalse($situation->mayBeIncomplete);
    }
}
