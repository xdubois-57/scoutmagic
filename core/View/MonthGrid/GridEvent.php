<?php

declare(strict_types=1);

namespace Core\View\MonthGrid;

/**
 * A single event to place on a MonthGridBuilder grid — deliberately generic
 * (no module-specific concepts like "calendar" or "location") so any module
 * with date-ranged things to show in a month view can reuse the same grid
 * algorithm and Twig partial (partials/month_grid.html.twig), not just the
 * calendar module. A caller maps its own domain objects into these.
 *
 * $tooltip is the full hover text (already composed by the caller — e.g.
 * "Réunion — 14:00 — Local scout"); $data is an opaque bag of extra
 * attributes the caller wants echoed back as data-* attributes on the
 * rendered bar (e.g. for a click-to-edit handler), rendered generically by
 * the partial without it needing to know what any of them mean.
 */
final class GridEvent
{
    /**
     * @param array<string, string> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly string $label,
        public readonly string $color,
        public readonly ?string $tooltip = null,
        public readonly array $data = []
    ) {
    }
}
