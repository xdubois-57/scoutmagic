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
 * (the "parcours à terminer" list, the ONE ratio, the member's own card)
 * reads a step, never a raw string.
 *
 * ## One box, never a list of qualifications
 *
 * `Core\Import\DeskCsvParser` reads a single column, « Niveau formation »,
 * and keeps only the first line's value for a member: the data available
 * is **one string per member per year**, not a set of things they hold.
 * Somebody holding both the BACV and the Woodbadge cannot be represented,
 * and the site does not pretend otherwise. So this enum classifies a
 * string into one box, and each box carries its own attributes — rather
 * than modelling an acquisition history the import could never fill.
 *
 * ## The shape of the path
 *
 * T1 and PI_DAYS are two doors into the same corridor: same rank, two
 * distinct boxes, both leading to T2. Then T2 → T3 → BACV, and BACV is
 * the end of the animator path.
 *
 * WOODBADGE is **outside** that path — an internal scouting qualification
 * with no ONE recognition and no next step — which is why it is neither
 * `isPathInProgress()` nor followed by anything.
 *
 * BREVET is the **legacy box**, kept because rows already point at it: a
 * brevet whose kind nobody recorded. Its label says so, and the two
 * attributes below are where that imprecision costs something.
 *
 * ## Two attributes rather than each caller re-deciding
 *
 * `countsForOneRatio()` and `countsForFederationDiscount()` exist because
 * the two questions genuinely have different answers, and a caller
 * re-deriving either from the case would drift from the other:
 *
 * - The **ONE ratio** is a regulatory figure and only the BACV is
 *   recognised by the ONE. A Woodbadge is not, and an unspecified brevet
 *   *might* be one — which is not the same as being one.
 * - The **federation discount** is opened by any brevet, BACV and
 *   Woodbadge alike. An unspecified brevet is therefore one of the two,
 *   whichever it is, so it counts — the union of the qualifying boxes
 *   contains it. Reading it as "no brevet" would silently withdraw the
 *   discount check from every unit that has not re-mapped its vocabulary,
 *   turning an imprecision into an assertion, which is the one thing this
 *   module refuses to do anywhere else.
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
    case PI_DAYS = 'pi_days';
    case T2 = 't2';
    case T3 = 't3';
    case BACV = 'bacv';
    case WOODBADGE = 'woodbadge';
    case BREVET = 'brevet';
    case UNKNOWN = 'unknown';

    /**
     * The path itself, in order, as drawn on the member's own card. NONE is
     * the state before it starts and UNKNOWN is outside it, so neither is a
     * milestone; PI_DAYS is an alternative entrance rather than a milestone
     * of its own, and WOODBADGE and BREVET are not on this path at all.
     *
     * @return list<self>
     */
    public static function path(): array
    {
        return [self::T1, self::T2, self::T3, self::BACV];
    }

    /** French label, for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Aucune formation encodée',
            self::T1 => 'T1',
            self::PI_DAYS => 'Pi-days',
            self::T2 => 'T2',
            self::T3 => 'T3',
            self::BACV => 'BACV',
            self::WOODBADGE => 'Woodbadge',
            self::BREVET => 'Brevet non précisé',
            self::UNKNOWN => 'Niveau non reconnu',
        };
    }

    /** Longer French wording, for a sentence rather than a chip. */
    public function description(): string
    {
        return match ($this) {
            self::NONE => "Aucun niveau de formation n'est encodé dans Desk",
            self::T1 => 'Première étape (T1) atteinte',
            self::PI_DAYS => 'Pi-days suivis — équivalent de la première étape',
            self::T2 => 'Deuxième étape (T2) atteinte',
            self::T3 => 'Troisième étape (T3) atteinte',
            self::BACV => "BACV obtenu — brevet reconnu par l'ONE",
            self::WOODBADGE => 'Woodbadge obtenu — formation interne, hors parcours animateur',
            self::BREVET => "Brevet obtenu, sans précision de son type — à préciser pour le ratio ONE",
            self::UNKNOWN => "Le niveau encodé dans Desk n'est pas reconnu par le site",
        };
    }

    /**
     * Rank along the path, used only for sorting "parcours à terminer" by
     * step reached, descending. NONE and UNKNOWN sort below every real
     * step because neither belongs to the path.
     *
     * **The rank is no longer one box per number**: PI_DAYS ranks with T1
     * (it is the same moment of the path by another door), and the three
     * brevet boxes share the top rank. Any sort on it must therefore break
     * ties on something stable — the name, as Modules\Fees\Service\
     * InvoiceVerificationService already does — or the order of two people
     * at the same rank becomes whatever the database returned that day.
     */
    public function rank(): int
    {
        return match ($this) {
            self::UNKNOWN => -1,
            self::NONE => 0,
            self::T1, self::PI_DAYS => 1,
            self::T2 => 2,
            self::T3 => 3,
            self::BACV, self::WOODBADGE, self::BREVET => 4,
        };
    }

    /** True for the steps between the two entrances and T3 — the "parcours à terminer" set. */
    public function isPathInProgress(): bool
    {
        return in_array($this, [self::T1, self::PI_DAYS, self::T2, self::T3], true);
    }

    /**
     * Counted as qualified in the ONE supervision ratio.
     *
     * The BACV alone: it is the qualification the ONE recognises. The
     * Woodbadge is internal to scouting and has no ONE recognition, and an
     * unspecified brevet cannot be asserted to be a BACV — the ratio has
     * regulatory weight, so "might be" is not "is".
     */
    public function countsForOneRatio(): bool
    {
        return $this === self::BACV;
    }

    /**
     * Counted as holding a brevet for the federation's fee discount.
     *
     * BACV and Woodbadge both open it — and so does the legacy box, since
     * it means "a brevet of unrecorded kind" and both kinds qualify. See
     * the class comment: excluding it would quietly stop reporting the
     * discount for every unit whose vocabulary predates these boxes.
     */
    public function countsForFederationDiscount(): bool
    {
        return in_array($this, [self::BACV, self::WOODBADGE, self::BREVET], true);
    }

    /**
     * The step after this one, or null when there is none.
     *
     * BACV ends the path; WOODBADGE is off it; BREVET is a brevet already,
     * imprecise but reached. NONE and UNKNOWN have no *known* next step,
     * and guessing one would be exactly the estimate this module refuses
     * to display.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::T1, self::PI_DAYS => self::T2,
            self::T2 => self::T3,
            self::T3 => self::BACV,
            default => null,
        };
    }

    /**
     * Every step an admin may map a raw Desk value to. UNKNOWN is absent on
     * purpose: it is what the site says when nobody has decided, never a
     * decision somebody can record.
     *
     * BREVET stays assignable although it is the legacy box. Rows already
     * point at it, and the Formations page renders each row's stored step
     * as the selected option of this very list — drop it here and one of
     * those rows would render with nothing selected, so the next click on
     * « Modifier » would silently reclassify it into whichever step
     * happened to come first.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [
            self::NONE,
            self::T1,
            self::PI_DAYS,
            self::T2,
            self::T3,
            self::BACV,
            self::WOODBADGE,
            self::BREVET,
        ];
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
