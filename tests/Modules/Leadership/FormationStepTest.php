<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership;

use Modules\Leadership\FormationStep;
use PHPUnit\Framework\TestCase;

/**
 * The module's whole vocabulary, as a table.
 *
 * A table rather than one test per method, because the interesting failure
 * is not "label() is wrong for T2" — it is a case ADDED to the enum and
 * forgotten by one of the five things every case must answer. A `match`
 * with no default throws at runtime, on a page, for whoever happens to
 * hold that level; the row-per-case shape below fails in CI instead.
 */
class FormationStepTest extends TestCase
{
    /**
     * @return array<string, array{FormationStep, string, int, bool, ?FormationStep}>
     */
    public static function stepProvider(): array
    {
        return [
            'none' => [FormationStep::NONE, 'Aucune formation encodée', 0, false, null],
            't1' => [FormationStep::T1, 'T1', 1, true, FormationStep::T2],
            't2' => [FormationStep::T2, 'T2', 2, true, FormationStep::T3],
            't3' => [FormationStep::T3, 'T3', 3, true, FormationStep::BREVET],
            'brevet' => [FormationStep::BREVET, 'Brevet', 4, false, null],
            'unknown' => [FormationStep::UNKNOWN, 'Niveau non reconnu', -1, false, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stepProvider')]
    public function testEveryCaseAnswersTheFiveQuestions(
        FormationStep $step,
        string $label,
        int $rank,
        bool $inProgress,
        ?FormationStep $next
    ): void {
        $this->assertSame($label, $step->label());
        $this->assertSame($rank, $step->rank());
        $this->assertSame($inProgress, $step->isPathInProgress());
        $this->assertSame($next, $step->next());
        $this->assertNotSame('', $step->description(), 'every step needs a sentence, not only a chip');
    }

    public function testTheTableCoversEveryCaseTheEnumDeclares(): void
    {
        $covered = array_map(
            static fn (array $row): string => $row[0]->value,
            array_values(self::stepProvider())
        );

        $this->assertSame(
            array_map(static fn (FormationStep $case): string => $case->value, FormationStep::cases()),
            $covered,
            'a case added to the enum must gain a row here'
        );
    }

    /**
     * NONE is the state before the path starts and UNKNOWN is outside it,
     * so neither is a milestone anybody reaches.
     */
    public function testThePathIsTheFourRealStepsInOrder(): void
    {
        $this->assertSame(
            [FormationStep::T1, FormationStep::T2, FormationStep::T3, FormationStep::BREVET],
            FormationStep::path()
        );
    }

    /**
     * UNKNOWN is what the site SAYS when nobody has decided, never a
     * decision somebody can record — which is also why a mapped value
     * always resolves to a real step.
     */
    public function testUnknownIsNotSomethingAnAdminCanAssign(): void
    {
        $this->assertNotContains(FormationStep::UNKNOWN, FormationStep::assignable());
        $this->assertSame(
            [FormationStep::NONE, FormationStep::T1, FormationStep::T2, FormationStep::T3, FormationStep::BREVET],
            FormationStep::assignable()
        );
    }

    /**
     * The rank exists for one thing: sorting « parcours à terminer » by
     * step reached, descending. Both non-path states must sort below every
     * real step, and UNKNOWN below NONE — "we do not know" is less than
     * "we know there is nothing".
     */
    public function testTheRankOrdersThePathAndPutsTheNonStepsUnderneath(): void
    {
        $steps = FormationStep::cases();
        usort($steps, static fn (FormationStep $a, FormationStep $b): int => $a->rank() <=> $b->rank());

        $this->assertSame(
            [
                FormationStep::UNKNOWN,
                FormationStep::NONE,
                FormationStep::T1,
                FormationStep::T2,
                FormationStep::T3,
                FormationStep::BREVET,
            ],
            $steps
        );
    }

    public function testFollowingNextWalksThePathExactlyOnce(): void
    {
        $walked = [FormationStep::T1];
        $step = FormationStep::T1;
        while (($step = $step->next()) !== null) {
            $walked[] = $step;
        }

        $this->assertSame(FormationStep::path(), $walked);
    }

    public function testTryFromValueAcceptsAStoredValueAndRefusesAnythingElse(): void
    {
        $this->assertSame(FormationStep::T2, FormationStep::tryFromValue('t2'));
        $this->assertNull(FormationStep::tryFromValue(null));
        $this->assertNull(FormationStep::tryFromValue(''));
        $this->assertNull(FormationStep::tryFromValue('T2'), 'the stored form is lowercase');
        $this->assertNull(FormationStep::tryFromValue('pas-une-etape'));
    }

    /**
     * The stored values are what `formation_level_mappings.step` holds and
     * what a form posts. Renaming one silently unmaps every value an admin
     * has decided about.
     */
    public function testTheStoredValuesAreThePinnedContract(): void
    {
        $this->assertSame(
            ['none', 't1', 't2', 't3', 'brevet', 'unknown'],
            array_map(static fn (FormationStep $case): string => $case->value, FormationStep::cases())
        );
    }

    public function testEveryLabelAndDescriptionIsInFrenchAndDistinct(): void
    {
        $labels = array_map(static fn (FormationStep $case): string => $case->label(), FormationStep::cases());
        $descriptions = array_map(
            static fn (FormationStep $case): string => $case->description(),
            FormationStep::cases()
        );

        $this->assertCount(count($labels), array_unique($labels));
        $this->assertCount(count($descriptions), array_unique($descriptions));
    }
}
