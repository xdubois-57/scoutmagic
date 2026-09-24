<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Geo\GeoPoint;
use Core\Member\SectionService;
use Core\Service\DateInput;
use Core\Service\TextNormalizerService;
use Core\View\SearchPickerResult;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;

/**
 * Organising a carpool — the staff half of the module (D1: only a chief
 * creates one).
 *
 * The calendar is an OPTIONAL dependency (ARCHITECTURE.md §7.5): without
 * it there is no event to link, and every carpool carries the section
 * chosen at creation instead — which is exactly the case D3 describes for a
 * carpool with no event at all.
 */
class CarpoolService
{
    /** Said beside the delete button, and by the refusal, in the same words. */
    public const DELETE_REFUSED = 'Suppression impossible : des familles se sont déjà organisées. '
        . 'Modifiez le covoiturage, ou prévenez-les.';

    public const RETURN_REMOVAL_REFUSED = 'Des voitures sont déjà proposées pour le retour : il ne peut plus '
        . 'être retiré. Leurs conducteurs doivent d\'abord les annuler.';

    /** How many suggestions the event search returns. */
    private const SEARCH_LIMIT = 15;

    public function __construct(
        private CarpoolRepository $carpools,
        private OfferRepository $offers,
        private SectionService $sections,
        private ?CalendarEventLookupInterface $calendar = null
    ) {
    }

    public function hasCalendar(): bool
    {
        return $this->calendar !== null;
    }

    /**
     * The event picker's answer: upcoming events the chief may see,
     * warning on those that already have a carpool.
     *
     * @return list<SearchPickerResult>
     */
    public function searchEvents(string $query, CarpoolViewer $viewer): array
    {
        if ($this->calendar === null) {
            return [];
        }
        $events = $this->calendar->searchUpcomingEvents($query, $viewer->role, self::SEARCH_LIMIT);
        $taken = $this->carpools->carpoolIdsByEvent(array_map(static fn(EventSummary $e): int => $e->id, $events));

        return array_map(static function (EventSummary $event) use ($taken): SearchPickerResult {
            $subtitle = 'calendrier ' . $event->calendarName;
            if ($event->location !== null) {
                $subtitle .= ' · ' . $event->location;
            }

            return new SearchPickerResult(
                $event->id,
                $event->title . ' · ' . CarpoolFormat::day($event->startDate),
                $subtitle,
                $event->sectionName ?? $event->calendarName,
                isset($taken[$event->id])
                    ? 'Un covoiturage existe déjà pour cet évènement — mieux vaut le rejoindre.'
                    : null
            );
        }, $events);
    }

    /**
     * The places the given events announce, distinct, in their order — so
     * the form can take the first as the address and warn when there are
     * several.
     *
     * @param list<int> $eventIds
     * @return list<string>
     */
    public function eventLocations(array $eventIds, CarpoolViewer $viewer): array
    {
        $locations = [];
        foreach ($this->resolveEvents($eventIds, $viewer) as $event) {
            if ($event->location !== null) {
                $locations[TextNormalizerService::fold($event->location)] ??= $event->location;
            }
        }

        return array_values($locations);
    }

    /**
     * @param array<string, mixed> $input the organiser form
     * @throws CarpoolException
     */
    public function create(array $input, CarpoolViewer $viewer): int
    {
        if (!$viewer->mayCreate()) {
            throw new CarpoolException('Seul un animateur peut organiser un covoiturage.');
        }
        $data = $this->validate($input, $viewer, null);

        $id = $this->carpools->create(
            $data['address'],
            $data['outbound'],
            $data['return'],
            $data['section_id'],
            $viewer->accountId
        );
        $this->carpools->replaceEvents($id, $data['events']);
        // A point placed before saving is a human's point; none at all leaves
        // the address to the geocoding task.
        if ($data['point'] !== null) {
            $this->carpools->points()->setManual($id, $data['point'], new \DateTimeImmutable());
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @throws CarpoolException
     */
    public function update(Carpool $carpool, array $input, CarpoolViewer $viewer): void
    {
        if (!$viewer->mayOrganize($carpool)) {
            throw new CarpoolException('Ce covoiturage concerne une autre section : vous ne pouvez pas le modifier.');
        }
        $data = $this->validate($input, $viewer, $carpool);
        if ($data['return'] === null && $carpool->hasReturn() && $this->hasReturnOffers($carpool)) {
            // Without a return date the return tab disappears, and with it
            // every car proposed for it and every seat already granted.
            throw new CarpoolException(self::RETURN_REMOVAL_REFUSED);
        }

        $this->carpools->update($carpool->id, $data['address'], $data['outbound'], $data['return'], $data['section_id']);
        $this->carpools->replaceEvents($carpool->id, $data['events']);

        $points = $this->carpools->points();
        if (TextNormalizerService::fold($carpool->address) !== TextNormalizerService::fold($data['address'])) {
            // A different address is a different place on the map; a point
            // a chief placed by hand stays where it is (the core's lock).
            $points->forgetGeocoding($carpool->id);
        }
        // Moved, typed or removed by hand: locked for ever. A form that did
        // not carry the point at all changes nothing.
        if ($data['point_given'] && $data['point']?->line() !== $carpool->point?->line()) {
            $points->setManual($carpool->id, $data['point'], new \DateTimeImmutable());
        }
    }

    /**
     * Deleting is refused as soon as a car exists: families have organised
     * themselves around it.
     *
     * @throws CarpoolException
     */
    public function delete(Carpool $carpool, CarpoolViewer $viewer): void
    {
        if (!$viewer->mayOrganize($carpool)) {
            throw new CarpoolException('Ce covoiturage concerne une autre section : vous ne pouvez pas le supprimer.');
        }
        if ($this->hasOffers($carpool)) {
            throw new CarpoolException(self::DELETE_REFUSED);
        }
        $this->carpools->delete($carpool->id);
    }

    public function hasOffers(Carpool $carpool): bool
    {
        return ($this->offers->findByCarpools([$carpool->id])[$carpool->id] ?? []) !== [];
    }

    private function hasReturnOffers(Carpool $carpool): bool
    {
        foreach ($this->offers->findByCarpools([$carpool->id])[$carpool->id] ?? [] as $offer) {
            if ($offer->direction === Offer::RETURN) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{address: string, outbound: string, return: ?string, section_id: ?int,
     *               events: list<CarpoolEvent>, point: ?GeoPoint, point_given: bool}
     * @throws CarpoolException
     */
    private function validate(array $input, CarpoolViewer $viewer, ?Carpool $existing): array
    {
        $eventIds = $this->intList($input['event_ids'] ?? []);
        $events = $this->resolveEvents($eventIds, $viewer);
        if (count($events) !== count($eventIds)) {
            throw new CarpoolException('Un des évènements choisis n\'existe plus ou ne vous est pas visible.');
        }

        // One event, one carpool: offer to join the one that exists.
        $taken = $this->carpools->carpoolIdsByEvent($eventIds);
        foreach ($events as $event) {
            $owner = $taken[$event->id] ?? null;
            if ($owner !== null && $owner !== $existing?->id) {
                throw new DuplicateCarpoolException(
                    'Un covoiturage existe déjà pour « ' . $event->title . ' ». Rejoignez-le plutôt que d\'en '
                    . 'créer un second : les familles se répartiraient sur deux listes.',
                    $owner
                );
            }
        }

        $locations = $this->eventLocations($eventIds, $viewer);
        if (count($locations) > 1 && ($input['confirm_locations'] ?? '') !== '1') {
            throw new LocationMismatchException(
                'Les évènements retenus n\'indiquent pas tous le même lieu. Un covoiturage n\'a qu\'une '
                . 'destination : vérifiez l\'adresse, puis confirmez.',
                $locations
            );
        }

        $address = trim((string) ($input['address'] ?? ''));
        if ($address === '' && $locations !== []) {
            $address = $locations[0];
        }
        if ($address === '') {
            throw new CarpoolException('Indiquez l\'adresse de la sortie : c\'est la destination de tous les allers.');
        }
        if (mb_strlen($address) > 255) {
            throw new CarpoolException('L\'adresse est trop longue (255 caractères au plus).');
        }

        $outbound = DateInput::isoStringOrNull(trim((string) ($input['outbound_date'] ?? '')));
        if ($outbound === null) {
            throw new CarpoolException('Indiquez la date de l\'aller.');
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($outbound < $today && ($existing === null || $existing->outboundDate !== $outbound)) {
            throw new CarpoolException('La date de l\'aller est déjà passée : un covoiturage prépare une sortie à venir.');
        }
        $returnRaw = trim((string) ($input['return_date'] ?? ''));
        $return = $returnRaw === '' ? null : DateInput::isoStringOrNull($returnRaw);
        if ($returnRaw !== '' && $return === null) {
            throw new CarpoolException('La date du retour n\'est pas une date valide.');
        }
        if ($return !== null && $return < $outbound) {
            throw new CarpoolException('Le retour ne peut pas précéder l\'aller.');
        }

        $sectionId = null;
        if ($events === []) {
            $sectionId = (int) ($input['section_id'] ?? 0);
            if ($sectionId <= 0 || $this->sections->getSection($sectionId) === null) {
                throw new CarpoolException(
                    'Sans évènement, choisissez la section concernée : c\'est son staff qui verra les voitures '
                    . 'et les passagers.'
                );
            }
        }

        $pointGiven = array_key_exists('latitude', $input) || array_key_exists('longitude', $input);
        $point = GeoPoint::fromInput(
            isset($input['latitude']) ? (string) $input['latitude'] : null,
            isset($input['longitude']) ? (string) $input['longitude'] : null
        );

        return [
            'address' => $address,
            'outbound' => $outbound,
            'return' => $return,
            'section_id' => $sectionId,
            'events' => array_map(static fn(EventSummary $e): CarpoolEvent => new CarpoolEvent(
                $e->id,
                $e->title,
                $e->sectionId,
                $e->sectionName
            ), $events),
            'point' => $point,
            'point_given' => $pointGiven,
        ];
    }

    /**
     * The events behind $eventIds, re-read server-side and filtered by what
     * the viewer may see — a posted id is never trusted for its title or
     * its section.
     *
     * @param list<int> $eventIds
     * @return list<EventSummary>
     */
    private function resolveEvents(array $eventIds, CarpoolViewer $viewer): array
    {
        if ($this->calendar === null) {
            return [];
        }
        $events = [];
        foreach ($eventIds as $eventId) {
            $event = $this->calendar->findEventById($eventId, $viewer->role);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $values): array
    {
        if (!is_array($values)) {
            $values = $values === null || $values === '' ? [] : explode(',', (string) $values);
        }
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
