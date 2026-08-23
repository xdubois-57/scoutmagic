<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership;

/**
 * The normalised training steps this module reasons about, and the only
 * vocabulary it ever stores.
 *
 * `member_years.formation_level` is a free VARCHAR carrying whatever Desk
 * exported — the federation's own wording, which differs between exports
 * and changes between years. This enum is the site's side of that: every
 * raw value is resolved to exactly one of these, and everything downstream
 * (the "parcours à terminer" list, the brevet count, the member's own
 * card) reads a step, never a raw string.
 *
 * UNKNOWN is a real step, not an error case: a raw value nobody has mapped
 * yet is shown as unknown, listed on the Formations page so it can be
 * mapped, and — crucially — never counted as anything. See
 * Service\SupervisionCalculator for why that direction of ignorance is the
 * safe one.
 */
enum FormationStep: string
{
    case NONE = 'none';
    case T1 = 't1';
    case T2 = 't2';
    case T3 = 't3';
    case BREVET = 'brevet';
    case UNKNOWN = 'unknown';

    /**
     * The four steps of the path itself, in order, as drawn on the member's
     * own card. NONE is the state before the path starts and UNKNOWN is
     * outside it, so neither is a milestone.
     *
     * @return list<self>
     */
    public static function path(): array
    {
        return [self::T1, self::T2, self::T3, self::BREVET];
    }

    /** French label, for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Aucune formation encodée',
            self::T1 => 'T1',
            self::T2 => 'T2',
            self::T3 => 'T3',
            self::BREVET => 'Brevet',
            self::UNKNOWN => 'Niveau non reconnu',
        };
    }

    /** Longer French wording, for a sentence rather than a chip. */
    public function description(): string
    {
        return match ($this) {
            self::NONE => "Aucun niveau de formation n'est encodé dans Desk",
            self::T1 => 'Première étape (T1) atteinte',
            self::T2 => 'Deuxième étape (T2) atteinte',
            self::T3 => 'Troisième étape (T3) atteinte',
            self::BREVET => "Brevet d'animateur obtenu",
            self::UNKNOWN => "Le niveau encodé dans Desk n'est pas reconnu par le site",
        };
    }

    /**
     * Rank along the path, used only for sorting "parcours à terminer" by
     * step reached, descending. NONE and UNKNOWN sort below every real
     * step because neither belongs to the path.
     */
    public function rank(): int
    {
        return match ($this) {
            self::UNKNOWN => -1,
            self::NONE => 0,
            self::T1 => 1,
            self::T2 => 2,
            self::T3 => 3,
            self::BREVET => 4,
        };
    }

    /** True for the three steps between T1 and T3 — the "parcours à terminer" set. */
    public function isPathInProgress(): bool
    {
        return in_array($this, [self::T1, self::T2, self::T3], true);
    }

    /**
     * The step after this one, or null when there is none (BREVET is the
     * end of the path; NONE and UNKNOWN have no *known* next step, and
     * guessing one would be exactly the estimate this module refuses to
     * display).
     */
    public function next(): ?self
    {
        return match ($this) {
            self::T1 => self::T2,
            self::T2 => self::T3,
            self::T3 => self::BREVET,
            default => null,
        };
    }

    /**
     * Every step an admin may map a raw Desk value to. UNKNOWN is absent on
     * purpose: it is what the site says when nobody has decided, never a
     * decision somebody can record.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::NONE, self::T1, self::T2, self::T3, self::BREVET];
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
