<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

use Core\Geo\GeoPoint;

/**
 * One carpool: the place and the dates of an outing, and the events it
 * serves. The place belongs here and never to an offer.
 */
final class Carpool
{
    /**
     * @param list<CarpoolEvent> $events
     */
    public function __construct(
        public readonly int $id,
        public readonly string $address,
        public readonly ?GeoPoint $point,
        public readonly bool $pointIsManual,
        public readonly string $outboundDate,
        public readonly ?string $returnDate,
        public readonly ?int $sectionId,
        public readonly ?int $createdBy,
        public readonly array $events = []
    ) {
    }

    /** The last day of the outing — what display and retention count from. */
    public function lastDate(): string
    {
        return $this->returnDate ?? $this->outboundDate;
    }

    public function hasReturn(): bool
    {
        return $this->returnDate !== null;
    }

    /**
     * The sections whose staff sees this carpool's passengers (D3): its
     * creator's own section AND those of its linked events.
     *
     * **It used to be one or the other** — the events' sections, or the
     * section a chief chose by hand on a carpool with no event. Issue #650
     * removed that field and made this a union, which is what widens D3:
     * the staff of the creator's section now sees the passengers of a
     * carpool whose events belong to other sections. That is the intent —
     * a chief organising a trip for their own section should not lose
     * sight of it because the outing is an Unit-wide event.
     *
     * Carpools created before #650 are unchanged by the same expression
     * **as long as nobody edits them**: those with events carried no
     * section of their own, and those without carried no event — the old
     * `validate()` forced `section_id` to null whenever an event was
     * retained, and the old `update()` wrote that null back, so the two
     * shapes were mutually exclusive in the table and still are at the
     * moment this ships.
     *
     * An edit that links an event to a legacy carpool is the one case where
     * this reads differently from before: the hand-picked section survives
     * alongside the event's, where the old rule silently dropped it. Its
     * staff keep a carpool they were already seeing, so nothing is
     * disclosed that was not disclosed before — but that section came from
     * the old unrestricted picker and may be one the creator never staffed,
     * which the new `create()` refuses. Issue #680 carries that legacy
     * question: neither repair fits here — clearing a stored section once
     * events exist would undo this very union for carpools created since,
     * and a migration keyed on « has a section and has events » would
     * target no row at all, the two shapes being exclusive as above.
     *
     * @return list<int>
     */
    public function sectionIds(): array
    {
        // The creator's section first: it is the carpool's own, the events'
        // are borrowed. Order has no effect on access, only on display.
        $ids = [];
        if ($this->sectionId !== null) {
            $ids[$this->sectionId] = $this->sectionId;
        }
        foreach ($this->events as $event) {
            if ($event->sectionId !== null) {
                $ids[$event->sectionId] = $event->sectionId;
            }
        }

        return array_values($ids);
    }

    /**
     * How the carpool is named: its event's title, the shared part of its
     * events' titles when there are several (« Fête d'unité — Baladins »
     * and « Fête d'unité — Louveteaux » are « Fête d'unité »), and
     * « Trajet libre » with none.
     */
    public function title(): string
    {
        if ($this->events === []) {
            return 'Trajet libre';
        }
        if (count($this->events) === 1) {
            return $this->events[0]->title;
        }

        $first = explode(' — ', $this->events[0]->title)[0];
        foreach ($this->events as $event) {
            if (explode(' — ', $event->title)[0] !== $first) {
                return $this->events[0]->title;
            }
        }

        return $first;
    }

    /**
     * @param list<CarpoolEvent> $events
     */
    public function withEvents(array $events): self
    {
        return new self(
            $this->id,
            $this->address,
            $this->point,
            $this->pointIsManual,
            $this->outboundDate,
            $this->returnDate,
            $this->sectionId,
            $this->createdBy,
            $events
        );
    }
}
