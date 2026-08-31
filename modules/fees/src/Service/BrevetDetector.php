<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Service\TextNormalizerService;
use Modules\Leadership\Api\FormationLevelInterface;

/**
 * Does this Desk formation wording mean "breveté"?
 *
 * The federation grants a reduction for a qualified animateur, and the
 * only thing the site holds about it is `member_years.formation_level` —
 * the federation's own wording, verbatim, which differs between exports.
 *
 * **Two readings, and the better one is optional** (roadmap IT-21,
 * ARCHITECTURE.md §7.5):
 *
 * 1. `Modules\Leadership\Api\FormationLevelInterface` when the leadership
 *    module is enabled — the unit's own mapping of its own leftover
 *    wordings, plus that module's heuristic, answering through
 *    `countsForFederationDiscount()`: the BACV, the Woodbadge, and a
 *    brevet whose kind nobody recorded.
 * 2. This class's own folded-substring reading when it is not. It is a
 *    fallback and stays one: `fees` works with `leadership` switched off,
 *    and the only name it ever autoloads from that module is the `Api\`
 *    interface itself.
 *
 * The fallback recognises `bacv` and `wood` as well as `brevet`, which is
 * the defect that started this: « BACV » does not contain "brevet", so a
 * unit whose export words it that way had every one of its brevetés
 * reported as undetermined.
 *
 * **A wording neither reading recognises is not "no brevet", it is "the
 * site cannot say"**, and the verification report shows the line as
 * undetermined rather than claiming a reduction is missing. Reporting an
 * unrecognised wording as a missing reduction would put a false alarm on
 * every unit whose export words it differently.
 */
final class BrevetDetector
{
    /**
     * @param FormationLevelInterface|null $formationLevels null when the
     *        leadership module is disabled — the fallback below is then
     *        the whole of the answer.
     */
    public function __construct(private ?FormationLevelInterface $formationLevels = null)
    {
    }

    public function isBrevet(?string $formationLevel): bool
    {
        if ($formationLevel === null || trim($formationLevel) === '') {
            return false;
        }

        if ($this->formationLevels !== null) {
            return $this->formationLevels->resolve($formationLevel)->countsForFederationDiscount;
        }

        return self::mentionsBrevet($formationLevel);
    }

    /**
     * The same reading applied to text that is **not** a formation level:
     * an invoice line's reference and descriptor, where "brevet" says what
     * the line is about rather than what somebody holds.
     *
     * Static, and never routed through the interface, deliberately. A
     * unit's mapping says what a *person's* Desk wording means; letting it
     * decide how a *document* is read would make a vocabulary decision
     * about people change the reading of an invoice — and a unit that
     * mapped « Zorglub » to a brevet would silently turn every line
     * mentioning it into a brevet reduction.
     */
    public static function mentionsBrevet(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $folded = TextNormalizerService::fold($text);

        foreach (['brevet', 'bacv', 'woodbadge'] as $needle) {
            if (str_contains($folded, $needle)) {
                return true;
            }
        }

        // Whole word, so « wood badge » is caught without firing on some
        // unrelated string that merely contains those four letters.
        return preg_match('/(^| )wood( |$)/', $folded) === 1;
    }
}
