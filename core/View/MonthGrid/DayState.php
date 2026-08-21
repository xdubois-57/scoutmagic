<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View\MonthGrid;

/**
 * The state of a single day on a DayStateGridBuilder grid.
 *
 * This is the *other* month-grid model in this namespace, and the difference
 * is the whole reason both exist. Core\View\MonthGrid\GridEvent describes a
 * date-ranged **thing laid over** the grid, which MonthGridBuilder packs into
 * continuous bars — the right model when several items can share a day and
 * you want to see each of them. DayState describes the **day cell itself**:
 * exactly one state per day, no overlap, no packing. That is the right model
 * for availability, where the question is never "what is on the 14th" but
 * "can I take the 14th" — and where showing *why* a day is taken would be a
 * confidentiality leak.
 *
 * Deliberately generic — no notion of a booking, a rental, or any other
 * domain object. A caller maps its own availability calculation onto these.
 *
 * ## Accessibility is not optional here
 *
 * A calendar that encodes its meaning in cell colour alone is unusable with a
 * screen reader and ambiguous for colour-blind visitors, so $accessibleLabel
 * is a **required** constructor argument rather than an optional extra: there
 * is no way to construct a state that has a colour and no text equivalent.
 * The label is user-facing, so it is written in French by the caller.
 */
final class DayState
{
    /** Day is available and, subject to $selectable, can be picked. */
    public const STATE_FREE = 'free';

    /**
     * Day is not available, and the grid says nothing about why. Callers must
     * map every distinct private reason — booked, held, blocked, whatever —
     * onto this single state, so a public visitor cannot tell them apart.
     * That indistinguishability is a feature, not an approximation.
     */
    public const STATE_OCCUPIED = 'occupied';

    /**
     * Some but not all of a countable stock is available on this day. The
     * remaining quantity belongs in $data, so the grid itself stays generic.
     */
    public const STATE_PARTIAL = 'partial';

    /**
     * Occupied in the morning, free from midday: the last day of a range that
     * is released during the day, so a new range may begin on it. In a
     * half-open ("nights") availability model this is what the final day of a
     * booking looks like — the cell must be visibly distinct from both FREE
     * and OCCUPIED, which is why it is a first-class state here rather than a
     * flag bolted on later.
     */
    public const STATE_DEPARTING = 'departing';

    /** Day is inside the visitor's current selection. */
    public const STATE_SELECTED = 'selected';

    /**
     * Day cannot be picked for a reason that has nothing to do with
     * availability: it is in the past, or inside a minimum-notice window, or
     * beyond a booking horizon. Kept strictly apart from STATE_OCCUPIED
     * because conflating them actively misinforms — a visitor shown "taken"
     * for a day that is merely too late to request concludes the thing is
     * booked and gives up, when it is free.
     */
    public const STATE_UNSELECTABLE = 'unselectable';

    /**
     * @param string $state One of the STATE_* constants, or any caller-defined string — the builder and partial never branch on the value, they only pass it through as a CSS hook.
     * @param string $accessibleLabel Human-readable French equivalent of the colour, e.g. "Libre", "Occupé", "Départ le matin". Rendered as text for assistive technology.
     * @param string|null $color CSS colour for the cell, or null to let the stylesheet decide from $state alone.
     * @param bool $selectable Whether the visitor may click this day. Never a security boundary — the server re-validates every submitted range.
     * @param array<string, string> $data Opaque extra attributes echoed back as data-* attributes on the rendered cell (e.g. a remaining quantity, a price).
     */
    public function __construct(
        public readonly string $state,
        public readonly string $accessibleLabel,
        public readonly ?string $color = null,
        public readonly bool $selectable = false,
        public readonly array $data = []
    ) {
    }
}
