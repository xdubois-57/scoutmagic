<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * What one operational check saw, this once.
 *
 * **The check decides whether it is over or under, not this object and not
 * the service.** Each check owns its own two thresholds and its own units
 * — a percentage, a number of days, a count of failures — and comparing
 * them is the one thing it knows how to do. What comes back here is the
 * verdict plus the words to say it, so that
 * {@see OperationalAlertService} can run the armed/triggered state machine
 * without knowing what any of the checks measure.
 *
 * `overTrigger` and `underRearm` are deliberately two independent
 * booleans rather than one three-valued state. Between the two thresholds
 * both are false, and that gap is the whole design
 * (`docs/exigences-non-fonctionnelles.md` §4): a triggered alert stays
 * triggered there, and an armed one stays armed.
 */
final class AlertReading
{
    /**
     * @param bool $overTrigger the value has reached the triggering threshold
     * @param bool $underRearm  the value has come back under the strictly
     *                          lower re-arming threshold. Never true at the
     *                          same time as $overTrigger — the two
     *                          thresholds do not overlap.
     * @param string $value     the reading as a screen would print it
     *                          (« 92 % », « 14 jours »), stored in
     *                          `operational_alerts.last_value` and shown.
     *                          Displayed and journaled, never compared.
     * @param string $title     one French sentence naming what is wrong
     * @param string $why       the consequence, not a restatement of the title
     * @param string|null $actionUrl where to go and fix it
     * @param string|null $actionLabel named as an action (« Voir les sauvegardes »)
     */
    public function __construct(
        public readonly bool $overTrigger,
        public readonly bool $underRearm,
        public readonly string $value,
        public readonly string $title,
        public readonly string $why,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null
    ) {
    }

    /**
     * A check that has nothing to report — the ordinary case, and not a
     * failure. Neither over the trigger nor under the re-arm, so a
     * triggered alert is left triggered: "I could not tell" must never
     * silently clear an alert somebody still needs to see.
     */
    public static function inconclusive(string $value = ''): self
    {
        return new self(false, false, $value, '', '');
    }
}
