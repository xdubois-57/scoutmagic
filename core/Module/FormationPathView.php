<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * A member's training path as the member page draws it: the milestones in
 * order, which of them are behind them, and what comes next.
 *
 * Presentation-ready on purpose. Core owns the template but not the domain,
 * so the providing module hands over labels and booleans rather than its
 * own step vocabulary — which keeps core free of any notion of T1/T2/T3 and
 * lets the module change its wording without touching a core template.
 *
 * `nextLabel` is null both at the end of the path and whenever the next
 * step is genuinely unknown, and the template says nothing in either case.
 * There is no third state that guesses.
 */
final class FormationPathView
{
    /**
     * @param list<array{label: string, reached: bool, current: bool}> $steps
     *        The milestones, in order, exactly as they should be drawn.
     * @param string $currentLabel  French sentence describing where the
     *        member stands — including "not recognised", which is a real
     *        answer and never dressed up as an estimate.
     * @param string|null $nextLabel The next milestone, or null when there
     *        is none to state.
     * @param bool $isRecognised False when Desk holds a value the site
     *        cannot resolve; the page then says so plainly rather than
     *        drawing a path it does not believe in.
     */
    public function __construct(
        public readonly array $steps,
        public readonly string $currentLabel,
        public readonly ?string $nextLabel,
        public readonly bool $isRecognised,
    ) {
    }
}
