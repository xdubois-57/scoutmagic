<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Movement;

/**
 * How a member's situation this scout year compares to their situation the
 * previous scout year — a generic, core-level classification (see
 * MemberMovementClassifierService), reusable by any feature that wants to
 * explain "why is this member here this year".
 *
 * This enum only carries semantic meaning (French label, description,
 * neutral presentation "tone") — never a CSS class or color, so any
 * consumer can render it however fits its own page.
 */
enum MemberMovementStatus: string
{
    /** Present this year, no active-with-section history at all before this year. */
    case NEW = 'new';

    /** Active in a section last year, active in a different section this year, same branch. */
    case SECTION_CHANGE = 'section_change';

    /** Active in a section last year, active in a different branch this year. */
    case BRANCH_CHANGE = 'branch_change';

    /** Known historically, but not active-with-a-section last year; active again this year. */
    case RETURNING = 'returning';

    /** Active in the exact same section last year and this year. */
    case CONTINUING = 'continuing';

    /** Not enough reliable history to classify — never guessed. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Nouveau',
            self::SECTION_CHANGE => 'Changement de section',
            self::BRANCH_CHANGE => 'Changement de branche',
            self::RETURNING => 'Retour',
            self::CONTINUING => 'Continuité',
            self::UNKNOWN => 'Situation inconnue',
        };
    }

    /**
     * Longer French explanation, suitable for a legend or a tooltip.
     */
    public function description(): string
    {
        return match ($this) {
            self::NEW => "Ce membre n'était pas membre de l'unité l'année scoute précédente.",
            self::SECTION_CHANGE => "Ce membre a changé de section au sein de la même branche depuis l'année scoute précédente.",
            self::BRANCH_CHANGE => "Ce membre a changé de branche depuis l'année scoute précédente.",
            self::RETURNING => "Ce membre était déjà connu de l'unité mais ne se trouvait pas, l'année scoute précédente, dans une situation active en section — il ou elle revient cette année.",
            self::CONTINUING => "Ce membre poursuit dans la continuité de l'année scoute précédente.",
            self::UNKNOWN => "L'historique disponible ne permet pas de déterminer la situation de ce membre avec certitude.",
        };
    }

    /**
     * Neutral, non-visual "tone" — a presentation layer maps this to actual
     * colors/CSS classes; the core enum never hardcodes a class.
     */
    public function tone(): string
    {
        return match ($this) {
            self::NEW => 'new',
            self::SECTION_CHANGE => 'section_change',
            self::BRANCH_CHANGE => 'branch_change',
            self::RETURNING => 'returning',
            self::CONTINUING, self::UNKNOWN => 'neutral',
        };
    }

    /**
     * Whether this status is notable enough to be visually highlighted —
     * continuity and "unknown" are deliberately not (§10 of the spec:
     * "la continuité normale n'a pas de couleur particulière").
     */
    public function isNotable(): bool
    {
        return $this !== self::CONTINUING && $this !== self::UNKNOWN;
    }
}
