<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\FormationStep;
use Modules\Leadership\Service\FormationLevelResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormationLevelResolverTest extends TestCase
{
    /**
     * @return array<string, array{?string, FormationStep}>
     */
    public static function heuristicCases(): array
    {
        return [
            'plain T1' => ['T1', FormationStep::T1],
            'plain T2' => ['T2', FormationStep::T2],
            'plain T3' => ['T3', FormationStep::T3],
            'lowercase' => ['t2', FormationStep::T2],
            'inside a longer label' => ['Animateur T2', FormationStep::T2],
            'spelled out, accented' => ['Deuxième étape', FormationStep::T2],
            'spelled out, ordinal' => ['3e étape', FormationStep::T3],
            'brevet' => ['Breveté', FormationStep::BREVET],
            'brevet, uppercase' => ['ANIMATEUR BREVETE', FormationStep::BREVET],
            // The two named brevets, and the second entrance to the path
            // (roadmap IT-19). Each has its own box now, so the ONE ratio
            // can count the BACV without counting the Woodbadge.
            'bacv' => ['BACV', FormationStep::BACV],
            'bacv inside a longer label' => ['Animateur BACV 2025', FormationStep::BACV],
            'woodbadge, one word' => ['Woodbadge', FormationStep::WOODBADGE],
            'woodbadge, two words' => ['Wood Badge', FormationStep::WOODBADGE],
            'pi-days' => ['Pi-days', FormationStep::PI_DAYS],
            'pi days, spelled out' => ['Journées Pi', FormationStep::PI_DAYS],
            'journee pi, singular' => ['Journée Pi', FormationStep::PI_DAYS],

            'explicitly none' => ['Aucune formation', FormationStep::NONE],
            'nothing recorded' => [null, FormationStep::NONE],
            'empty string' => ['', FormationStep::NONE],
            'whitespace only' => ['   ', FormationStep::NONE],
            'unrecognised wording' => ['Module transversal 4', FormationStep::UNKNOWN],

            // A T-step is two characters long, so matched as a substring it
            // fires on anything that happens to contain them: a year, a
            // reference number, a room code. A confidently-wrong step never
            // announces itself the way an unrecognised one does, so these
            // have to stay whole-word matches.
            'a year swallowing t2' => ['POST2015', FormationStep::UNKNOWN],
            'a reference swallowing t1' => ['FORMAT1234', FormationStep::UNKNOWN],
            'a code swallowing t3' => ['LOT3-B', FormationStep::UNKNOWN],
            // …and the real wordings still resolve, including with the
            // punctuation folding collapses to a space.
            'a t-step with punctuation around it' => ['Formation : T2 (2026)', FormationStep::T2],
            'a t-step at the end of a label' => ['Animateur — T3', FormationStep::T3],
        ];
    }

    #[DataProvider('heuristicCases')]
    public function testResolvesTheFederationVocabulary(?string $raw, FormationStep $expected): void
    {
        $this->assertSame($expected, (new FormationLevelResolver())->resolve($raw));
    }

    /**
     * A named brevet is tested before the generic one: « brevet BACV » is
     * a BACV, and reading it as an unspecified brevet would lose exactly
     * the distinction the ONE ratio is built on.
     */
    public function testANamedBrevetWinsOverTheGenericWord(): void
    {
        $resolver = new FormationLevelResolver();

        $this->assertSame(FormationStep::BACV, $resolver->resolve('Brevet BACV'));
        $this->assertSame(FormationStep::WOODBADGE, $resolver->resolve('Brevet Woodbadge'));
        $this->assertSame(FormationStep::BREVET, $resolver->resolve('Brevet d\'animateur'));
    }

    /**
     * Pi-days is an entrance equivalent to T1, so a label naming both is
     * the Pi-days one — the same reasoning as the brevet above, one rung
     * lower.
     */
    public function testPiDaysWinsOverTheEquivalentTStep(): void
    {
        $this->assertSame(FormationStep::PI_DAYS, (new FormationLevelResolver())->resolve('Pi-days (T1)'));
    }

    /**
     * « en cours » still wins over everything, the new boxes included: a
     * BACV being prepared is not a BACV, and SupervisionCalculator's floor
     * argument depends on never reading it as one.
     */
    public function testABacvUnderWayIsNotABacv(): void
    {
        $resolver = new FormationLevelResolver();

        $this->assertSame(FormationStep::UNKNOWN, $resolver->resolve('BACV en cours'));
        $this->assertSame(FormationStep::UNKNOWN, $resolver->resolve('Pi-days prévu'));
    }

    /**
     * Brevet is tested before any T-step, so a label naming both resolves
     * to the further of the two rather than to whichever appears first.
     */
    public function testBrevetWinsOverAnEarlierStepInTheSameLabel(): void
    {
        $this->assertSame(
            FormationStep::BREVET,
            (new FormationLevelResolver())->resolve('T3 + brevet')
        );
    }

    /**
     * The guarantee Service\SupervisionCalculator rests on: an
     * unrecognisable value never counts as a brevet, so the brevet count is
     * a floor. "Brevet en cours" is not a brevet, and resolving it as one
     * would put somebody who has not got it into the count.
     *
     * @return array<string, array{string}>
     */
    public static function inProgressWordings(): array
    {
        return [
            'brevet under way' => ['Brevet en cours'],
            'step under way' => ['T2 en cours'],
            'registered for it' => ['Inscrit T3'],
            'planned' => ['T1 prévu'],
        ];
    }

    #[DataProvider('inProgressWordings')]
    public function testAStepUnderWayIsNotAStepReached(string $raw): void
    {
        $this->assertSame(
            FormationStep::UNKNOWN,
            (new FormationLevelResolver())->resolve($raw),
            'An ambiguous wording must resolve upwards into "ask a human", never downwards into a guess.'
        );
    }

    public function testAnAdminMappingOverridesTheHeuristic(): void
    {
        $resolver = new FormationLevelResolver([
            FormationLevelResolver::keyFor('T2') => FormationStep::BREVET->value,
        ]);

        $this->assertSame(FormationStep::BREVET, $resolver->resolve('T2'));
    }

    public function testAnAdminMappingResolvesAnUnrecognisedValue(): void
    {
        $resolver = new FormationLevelResolver([
            FormationLevelResolver::keyFor('Module transversal 4') => FormationStep::T3->value,
        ]);

        $this->assertSame(FormationStep::T3, $resolver->resolve('Module transversal 4'));
    }

    /**
     * The mapping key folds case and accents, so one decision covers every
     * spelling of the same wording rather than needing one row per variant.
     */
    public function testMappingMatchesRegardlessOfCaseAndAccents(): void
    {
        $resolver = new FormationLevelResolver([
            FormationLevelResolver::keyFor('Animateur Breveté') => FormationStep::BREVET->value,
        ]);

        $this->assertSame(FormationStep::BREVET, $resolver->resolve('ANIMATEUR BREVETE'));
        $this->assertSame(FormationStep::BREVET, $resolver->resolve('  animateur breveté  '));
    }

    public function testAMappingCanNeverStoreUnknown(): void
    {
        // 'unknown' is a real FormationStep but not an assignable one — the
        // controller refuses it, and this pins the vocabulary the refusal
        // is built on.
        $this->assertNotContains(FormationStep::UNKNOWN, FormationStep::assignable());
    }

    public function testWithMappingLeavesTheOriginalResolverAlone(): void
    {
        $bare = new FormationLevelResolver();
        $mapped = $bare->withMapping([
            FormationLevelResolver::keyFor('Zorglub') => FormationStep::T1->value,
        ]);

        $this->assertSame(FormationStep::T1, $mapped->resolve('Zorglub'));
        $this->assertSame(FormationStep::UNKNOWN, $bare->resolve('Zorglub'));
    }
}
