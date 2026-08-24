<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Modules\Leadership\FormationStep;

/**
 * Turns a raw `member_years.formation_level` value into a FormationStep.
 *
 * Two sources, in this order, and the order is the point:
 *
 * 1. **The admin mapping** (`leadership_formation_levels`), keyed on the
 *    folded raw value. Whatever a chief d'unité decided a value means, it
 *    means — including a decision that contradicts the heuristic below,
 *    which is how a unit corrects the site rather than filing a bug.
 * 2. **The built-in heuristic**, so the Formations page is useful on the
 *    day the module is enabled instead of showing every member as unknown
 *    until somebody has mapped a dozen strings by hand.
 *
 * Anything neither source recognises is UNKNOWN, listed on the Formations
 * page, and counted as nothing at all.
 *
 * **An empty value is NONE, not UNKNOWN**, and that is a judgement worth
 * writing down. Desk leaves the column empty for somebody who has not
 * started the path — that is what the blank means there, so reading it as
 * "no formation" is reading it correctly, not estimating. UNKNOWN is
 * reserved for a value that is *present* and unrecognised, which is a
 * genuinely different thing: somebody wrote something and the site cannot
 * say what it means. The UI keeps them apart in the same words ("aucune
 * formation encodée" vs "niveau non reconnu") rather than collapsing both
 * into a reassuring dash.
 */
final class FormationLevelResolver
{
    /**
     * Folded-substring patterns, most specific first. Order matters: a
     * label reading "T3 + brevet" must resolve to brevet, and "brevet" is
     * therefore tested before any T-step.
     *
     * Deliberately conservative. A pattern that fires on the wrong value is
     * worse than one that does not fire at all, because an unrecognised
     * value announces itself on the Formations page and a
     * confidently-wrong one never does.
     *
     * @var list<array{0: string, 1: FormationStep}>
     */
    private const PATTERNS = [
        // Brevet first — it is the end of the path and its wording often
        // carries the preceding step's name alongside it.
        ['brevet', FormationStep::BREVET],
        ['animateur brevete', FormationStep::BREVET],

        ['t3', FormationStep::T3],
        ['troisieme etape', FormationStep::T3],
        ['3eme etape', FormationStep::T3],
        ['3e etape', FormationStep::T3],

        ['t2', FormationStep::T2],
        ['deuxieme etape', FormationStep::T2],
        ['2eme etape', FormationStep::T2],
        ['2e etape', FormationStep::T2],

        ['t1', FormationStep::T1],
        ['premiere etape', FormationStep::T1],
        ['1ere etape', FormationStep::T1],
        ['1re etape', FormationStep::T1],

        ['aucune formation', FormationStep::NONE],
        ['pas de formation', FormationStep::NONE],
        ['aucun', FormationStep::NONE],
        ['neant', FormationStep::NONE],
    ];

    /**
     * Wordings that say a step is under way rather than reached. A value
     * carrying one of these is UNKNOWN unless an admin has mapped it,
     * never resolved by the patterns above.
     *
     * This exists to protect one specific guarantee. Service\
     * SupervisionCalculator's whole argument is that an unrecognised level
     * can only ever make the real brevet count *higher* than the computed
     * one — so the computed one is a floor, and a met threshold is met for
     * certain. A pattern firing "brevet" on "brevet en cours" would put a
     * person who has no brevet into the count and destroy that: the number
     * would no longer be a floor in either direction, and the page would
     * be quietly announcing a subsidy threshold as met when it is not.
     * Ambiguity has to resolve upwards into "ask a human", never downwards
     * into a guess.
     *
     * @var list<string>
     */
    private const IN_PROGRESS_MARKERS = ['en cours', 'inscrit', 'a suivre', 'prevu'];

    /**
     * @param array<string, string> $mapping folded raw value → step value,
     *        as stored by an admin on the Formations page.
     */
    public function __construct(private array $mapping = [])
    {
    }

    /**
     * @param array<string, string> $mapping folded raw value → step value
     */
    public function withMapping(array $mapping): self
    {
        return new self($mapping);
    }

    public function resolve(?string $rawValue): FormationStep
    {
        $folded = TextMatcher::fold($rawValue);

        if ($folded === '') {
            return FormationStep::NONE;
        }

        $mapped = FormationStep::tryFromValue($this->mapping[$folded] ?? null);
        if ($mapped !== null) {
            return $mapped;
        }

        foreach (self::IN_PROGRESS_MARKERS as $marker) {
            if (str_contains($folded, $marker)) {
                return FormationStep::UNKNOWN;
            }
        }

        foreach (self::PATTERNS as [$needle, $step]) {
            if (str_contains($folded, $needle)) {
                return $step;
            }
        }

        return FormationStep::UNKNOWN;
    }

    /**
     * True when this exact raw value is covered by an admin mapping — used
     * by the Formations page to show which of the values it lists have
     * already been decided.
     */
    public function isExplicitlyMapped(?string $rawValue): bool
    {
        $folded = TextMatcher::fold($rawValue);

        return $folded !== '' && isset($this->mapping[$folded]);
    }

    /** The key an admin mapping is stored and looked up under. */
    public static function keyFor(?string $rawValue): string
    {
        return TextMatcher::fold($rawValue);
    }
}
