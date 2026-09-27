<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Geo\GeoPoint;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Service\DateInput;
use Core\Service\TextNormalizerService;
use Core\View\SearchPickerResult;
use Core\View\SectionPickerHelper;
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
 * it there is no event to link, and a carpool is simply managed by its
 * creator's own section — which is what {@see creatorSectionId()} answers,
 * and why no screen has to ask.
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
        private MemberService $members,
        private ?CalendarEventLookupInterface $calendar = null
    ) {
    }

    /**
     * The section a carpool created by this viewer is managed by, beside the
     * sections of its events — the answer that replaced the « Section
     * concernée » field of the organiser form (issue #650).
     *
     * It is the viewer's OWN section: among the members linked to their
     * address, the highest-role one, then that member's main function's
     * section — the same reading every other screen of the site starts from
     * (Core\View\SectionPickerHelper).
     *
     * **Resolved by Desk code, deliberately, and not against the list a
     * picker would offer** (raised in review of #650). That list comes from
     * `getAllWithBranches()`, which drops inactive AND hidden sections,
     * while `SectionStaffAuthorizationService::getStaffedSections()` keeps
     * them — so a chief staffs a hidden section the list never mentions.
     * Resolving against it would store no section for that chief's
     * carpools, and their section's staff would lose the passengers this
     * change exists to show them. `findByDeskCode()` filters on neither
     * flag.
     *
     * **And the answer is then kept only if the creator actually STAFFS
     * that section** (raised in review too, and it is the same lesson from
     * the other side). The main-function rule is blind to role:
     * `MemberProfile::getMainFunction()` returns whichever function Desk
     * flagged « Fonction principale », or simply the first one, with no
     * regard for `functionRole`. The access check this section feeds,
     * `CarpoolViewer::isStaffOf()`, reads `staffedSectionIds`, which
     * `StaffedSectionRepository` builds WITH a role filter — « without the
     * role filter, every animé would come back as an animateur of their own
     * section », says its own comment.
     *
     * Left unchecked, the two disagree: a chief of section B whose
     * main-flagged function sits in section A would freeze the carpool onto
     * A, handing A's staff the passengers of children they do not follow
     * while B's staff — and the creator's own colleagues — see nothing.
     * Intersecting with `$viewer->staffedSectionIds` closes it with the
     * SAME array `isStaffOf()` will consult, so the grant and its check
     * cannot come from different readings. The main-function rule stays
     * what it was: the selector among the sections a creator staffs.
     *
     * **Null is a real answer and is stored as such.** An account linked to
     * no member, or to one with no main function, creates a carpool with no
     * section: it is managed by its creator, by the Staff d'U, and by the
     * sections of its events. No section is chosen in its place — the
     * variant used here refuses the « first available section » fallback for
     * exactly that reason, because a section picked at random would be
     * handed the passengers of children it does not follow.
     *
     * Read at creation ONLY, never on update: see validate(). A chief who
     * changes section next year leaves the carpool where it was, and a
     * carpool edited by somebody else keeps its creator's section.
     */
    public function creatorSectionId(CarpoolViewer $viewer): ?int
    {
        $code = SectionPickerHelper::resolveMainSectionCode(
            $this->members->getLinkedMembers($viewer->email, $viewer->scoutYearId)
        );
        if ($code === null) {
            return null;
        }

        $section = $this->sections->findByDeskCode($code);
        if ($section === null) {
            return null;
        }

        return in_array($section['id'], $viewer->staffedSectionIds, true) ? $section['id'] : null;
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
            // Taken HERE and nowhere else: the one write of this column
            // (issue #650), which is what « figée à la création » means.
            $this->creatorSectionId($viewer),
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

        $this->carpools->update(
            $carpool->id,
            $data['address'],
            $data['outbound'],
            $data['return']
        );
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
     * The section is deliberately absent: it is never read from the form
     * (issue #650 removed the field, and honouring a hand-built
     * `section_id` would let the sender choose who sees the passengers),
     * and it is never written by an edit. create() takes it from the
     * creator instead.
     *
     * @return array{address: string, outbound: string, return: ?string,
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
            throw new CarpoolException(
                'La date de l\'aller est déjà passée : un covoiturage prépare une sortie à venir.'
            );
        }
        $returnRaw = trim((string) ($input['return_date'] ?? ''));
        $return = $returnRaw === '' ? null : DateInput::isoStringOrNull($returnRaw);
        if ($returnRaw !== '' && $return === null) {
            throw new CarpoolException('La date du retour n\'est pas une date valide.');
        }
        if ($return !== null && $return < $outbound) {
            throw new CarpoolException('Le retour ne peut pas précéder l\'aller.');
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
            'events' => array_map(
                static fn(EventSummary $e): CarpoolEvent
                    => new CarpoolEvent($e->id, $e->title, $e->sectionId, $e->sectionName),
                $events
            ),
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
