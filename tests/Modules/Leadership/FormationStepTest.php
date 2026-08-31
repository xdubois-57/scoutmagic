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
 * forgotten by one of the things every case must answer. A `match`
 * with no default throws at runtime, on a page, for whoever happens to
 * hold that level; the row-per-case shape below fails in CI instead.
 */
class FormationStepTest extends TestCase
{
    /**
     * @return array<string, array{FormationStep, string, int, bool, ?FormationStep, bool, bool}>
     */
    public static function stepProvider(): array
    {
        return [
            'none' => [FormationStep::NONE, 'Aucune formation encodée', 0, false, null, false, false],
            't1' => [FormationStep::T1, 'T1', 1, true, FormationStep::T2, false, false],
            'pi_days' => [FormationStep::PI_DAYS, 'Pi-days', 1, true, FormationStep::T2, false, false],
            't2' => [FormationStep::T2, 'T2', 2, true, FormationStep::T3, false, false],
            't3' => [FormationStep::T3, 'T3', 3, true, FormationStep::BACV, false, false],
            'bacv' => [FormationStep::BACV, 'BACV', 4, false, null, true, true],
            'woodbadge' => [FormationStep::WOODBADGE, 'Woodbadge', 4, false, null, false, true],
            'brevet' => [FormationStep::BREVET, 'Brevet non précisé', 4, false, null, false, true],
            'unknown' => [FormationStep::UNKNOWN, 'Niveau non reconnu', -1, false, null, false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stepProvider')]
    public function testEveryCaseAnswersTheSevenQuestions(
        FormationStep $step,
        string $label,
        int $rank,
        bool $inProgress,
        ?FormationStep $next,
        bool $countsForOneRatio,
        bool $countsForFederationDiscount
    ): void {
        $this->assertSame($label, $step->label());
        $this->assertSame($rank, $step->rank());
        $this->assertSame($inProgress, $step->isPathInProgress());
        $this->assertSame($next, $step->next());
        $this->assertSame($countsForOneRatio, $step->countsForOneRatio());
        $this->assertSame($countsForFederationDiscount, $step->countsForFederationDiscount());
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
     * The ONE recognises the BACV and nothing else. The Woodbadge is
     * internal to scouting, and an unspecified brevet MIGHT be a BACV —
     * which a regulatory figure may not read as "is".
     */
    public function testTheOneRatioCountsTheBacvAndNothingElse(): void
    {
        $counted = array_values(array_filter(
            FormationStep::cases(),
            static fn (FormationStep $case): bool => $case->countsForOneRatio()
        ));

        $this->assertSame([FormationStep::BACV], $counted);
    }

    /**
     * The federation discount is opened by any brevet. The legacy box is
     * in, deliberately: it means "a brevet nobody wrote the kind of", and
     * both kinds qualify — so excluding it would withdraw the discount
     * check from every unit whose vocabulary predates these boxes.
     */
    public function testTheFederationDiscountCountsBothBrevetsAndTheImpreciseOne(): void
    {
        $counted = array_values(array_filter(
            FormationStep::cases(),
            static fn (FormationStep $case): bool => $case->countsForFederationDiscount()
        ));

        $this->assertSame(
            [FormationStep::BACV, FormationStep::WOODBADGE, FormationStep::BREVET],
            $counted
        );
    }

    /**
     * NONE is the state before the path starts and UNKNOWN is outside it,
     * so neither is a milestone anybody reaches. Pi-days is a second
     * entrance rather than a milestone of its own, and the Woodbadge is
     * off this path entirely.
     */
    public function testThePathIsTheFourRealStepsInOrder(): void
    {
        $this->assertSame(
            [FormationStep::T1, FormationStep::T2, FormationStep::T3, FormationStep::BACV],
            FormationStep::path()
        );
    }

    /**
     * UNKNOWN is what the site SAYS when nobody has decided, never a
     * decision somebody can record — which is also why a mapped value
     * always resolves to a real step. The legacy box stays assignable:
     * rows point at it and the Formations page renders each row's stored
     * step as the selected option of this very list.
     */
    public function testUnknownIsNotSomethingAnAdminCanAssign(): void
    {
        $this->assertNotContains(FormationStep::UNKNOWN, FormationStep::assignable());
        $this->assertSame(
            [
                FormationStep::NONE,
                FormationStep::T1,
                FormationStep::PI_DAYS,
                FormationStep::T2,
                FormationStep::T3,
                FormationStep::BACV,
                FormationStep::WOODBADGE,
                FormationStep::BREVET,
            ],
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
                FormationStep::PI_DAYS,
                FormationStep::T2,
                FormationStep::T3,
                FormationStep::BACV,
                FormationStep::WOODBADGE,
                FormationStep::BREVET,
            ],
            $steps
        );
    }

    /**
     * Pi-days ranks with T1 — same moment of the path, another door — so
     * the rank is no longer one box per number, and a sort on it alone
     * decides nothing between two people at the same rank. PHP's sort is
     * stable, so equal ranks keep their incoming order; a caller wanting a
     * defined order has to break the tie itself, on the name.
     */
    public function testPiDaysAndT1ShareARankAndSortStably(): void
    {
        $this->assertSame(FormationStep::T1->rank(), FormationStep::PI_DAYS->rank());

        $people = [
            ['name' => 'Zoé', 'step' => FormationStep::PI_DAYS],
            ['name' => 'Alix', 'step' => FormationStep::T1],
            ['name' => 'Manon', 'step' => FormationStep::T2],
        ];
        usort($people, static fn (array $a, array $b): int => $b['step']->rank() <=> $a['step']->rank());

        $this->assertSame(
            ['Manon', 'Zoé', 'Alix'],
            array_column($people, 'name'),
            'the two tied entries keep the order they came in with'
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

    /**
     * The second entrance joins the corridor at the same place T1 does,
     * and the three terminal boxes end it.
     */
    public function testTheEntrancesJoinAndTheTerminalBoxesEnd(): void
    {
        $this->assertSame(FormationStep::T2, FormationStep::PI_DAYS->next());
        $this->assertNull(FormationStep::BACV->next());
        $this->assertNull(FormationStep::WOODBADGE->next());
        $this->assertNull(FormationStep::BREVET->next());
    }

    /**
     * The Woodbadge is outside the animator path: it is not a step
     * somebody is on their way through, so the "parcours à terminer" list
     * must not ask a chief to follow up on it.
     */
    public function testTheWoodbadgeIsNotAPathInProgress(): void
    {
        $this->assertFalse(FormationStep::WOODBADGE->isPathInProgress());
        $this->assertTrue(FormationStep::PI_DAYS->isPathInProgress());
    }

    public function testTryFromValueAcceptsAStoredValueAndRefusesAnythingElse(): void
    {
        $this->assertSame(FormationStep::T2, FormationStep::tryFromValue('t2'));
        // Every value already stored keeps resolving — the legacy box
        // included, which is the whole reason it was not removed.
        $this->assertSame(FormationStep::BREVET, FormationStep::tryFromValue('brevet'));
        $this->assertNull(FormationStep::tryFromValue(null));
        $this->assertNull(FormationStep::tryFromValue(''));
        $this->assertNull(FormationStep::tryFromValue('T2'), 'the stored form is lowercase');
        $this->assertNull(FormationStep::tryFromValue('pas-une-etape'));
    }

    /**
     * The stored values are what `leadership_formation_levels.step` holds
     * and what a form posts. Renaming one silently unmaps every value an
     * admin has decided about.
     */
    public function testTheStoredValuesAreThePinnedContract(): void
    {
        $this->assertSame(
            ['none', 't1', 'pi_days', 't2', 't3', 'bacv', 'woodbadge', 'brevet', 'unknown'],
            array_map(static fn (FormationStep $case): string => $case->value, FormationStep::cases())
        );
    }

    /** The column is VARCHAR(10) — a longer value would be truncated on write. */
    public function testEveryStoredValueFitsTheColumn(): void
    {
        foreach (FormationStep::cases() as $case) {
            $this->assertLessThanOrEqual(10, strlen($case->value), "{$case->value} does not fit step VARCHAR(10)");
        }
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
