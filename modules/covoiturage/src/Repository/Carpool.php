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
     * The sections whose staff sees this carpool's passengers (D3): those
     * of the linked events, or the one chosen at creation when there is
     * none.
     *
     * @return list<int>
     */
    public function sectionIds(): array
    {
        if ($this->events === []) {
            return $this->sectionId !== null ? [$this->sectionId] : [];
        }

        $ids = [];
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
