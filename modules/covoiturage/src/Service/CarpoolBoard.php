<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Config\SettingService;
use Core\Geo\MapsLink;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\UserAccountRepository;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;

/**
 * What each page of the module shows, built for ONE reader.
 *
 * Every passenger name and every phone number that reaches a template has
 * gone through a CarpoolViewer decision here first: what a reader may not
 * see is never put in the array, so no template, JSON payload or tooltip
 * can leak it (ARCHITECTURE.md §7.6, rule 3, applied at home).
 *
 * **Display and retention are one thing (D9).** The list shows what is to
 * come, and folds away what has passed — and only as far back as the
 * retention, because anything older has already been deleted by
 * Task\PurgeCarpoolsHandler.
 */
class CarpoolBoard
{
    public const RETENTION_SETTING = 'covoiturage_retention_days';
    public const DEFAULT_RETENTION_DAYS = 30;

    private const STATUS_LABELS = [
        SeatRequest::PENDING => 'en attente',
        SeatRequest::ACCEPTED => 'acceptée',
        SeatRequest::REFUSED => 'refusée',
        SeatRequest::REVOKED => 'place retirée',
    ];

    public function __construct(
        private CarpoolRepository $carpools,
        private OfferRepository $offers,
        private SeatRequestRepository $requests,
        private SettingService $settings,
        private SectionService $sections,
        private ?MemberService $members = null,
        private ?UserAccountRepository $accounts = null
    ) {
    }

    public function retentionDays(): int
    {
        $days = (int) $this->settings->get(
            self::RETENTION_SETTING,
            'covoiturage',
            (string) self::DEFAULT_RETENTION_DAYS
        );

        return max(1, $days);
    }

    /**
     * The members' list: carpools to come, then the folded past.
     *
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>, retention_days: int}
     */
    public function memberList(CarpoolViewer $viewer, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $todayYmd = $today->format('Y-m-d');
        $since = $today->modify('-' . $this->retentionDays() . ' days')->format('Y-m-d');

        $carpools = $this->carpools->findEndingOnOrAfter($since);
        [$offersByCarpool, $requestsByOffer] = $this->load($carpools);

        $upcoming = [];
        $past = [];
        foreach ($carpools as $carpool) {
            $offers = $offersByCarpool[$carpool->id] ?? [];
            if ($carpool->lastDate() >= $todayYmd) {
                $free = 0;
                foreach ($offers as $offer) {
                    $free += max(0, $offer->seats - $this->taken($requestsByOffer[$offer->id] ?? []));
                }
                $upcoming[] = [
                    'id' => $carpool->id,
                    'title' => $carpool->title(),
                    'address' => $carpool->address,
                    'dates' => $this->dates($carpool),
                    'cars' => count($offers),
                    'free' => $free,
                    'free_text' => CarpoolFormat::freeSeats($free),
                    // The reader's own part in it, from the one rule that
                    // the day's banner and the agenda line also read (#835).
                    'participation' => Participation::badges(
                        Participation::forAccount($viewer->accountId, $offers, $requestsByOffer),
                        $carpool->hasReturn()
                    ),
                ];
                continue;
            }

            $lines = [];
            foreach ($offers as $offer) {
                $riders = null;
                if ($viewer->seesRequestsOf($carpool, $offer)) {
                    $riders = [];
                    foreach ($requestsByOffer[$offer->id] ?? [] as $request) {
                        if ($request->isAccepted()) {
                            array_push($riders, ...$request->passengerNames);
                        }
                    }
                }
                $lines[] = [
                    'direction' => $offer->isOutbound() ? 'Aller' : 'Retour',
                    'time' => CarpoolFormat::time($offer->departureTime),
                    'driver' => $offer->driverName,
                    'riders' => $riders,
                ];
            }
            $past[] = [
                'id' => $carpool->id,
                'title' => $carpool->title(),
                'day' => CarpoolFormat::day($carpool->outboundDate, $today),
                'offers' => $lines,
            ];
        }

        return ['upcoming' => $upcoming, 'past' => array_reverse($past), 'retention_days' => $this->retentionDays()];
    }

    /**
     * One carpool, as its reader may see it. `$direction` is the trip the
     * page shows, which decides whether the banner of the day applies.
     *
     * @return array<string, mixed>
     */
    public function carpoolPage(
        Carpool $carpool,
        CarpoolViewer $viewer,
        ?\DateTimeImmutable $today = null,
        string $direction = Offer::OUTBOUND
    ): array {
        $today ??= new \DateTimeImmutable('today');
        [$offersByCarpool, $requestsByOffer] = $this->load([$carpool]);
        $hasFamily = $this->family($viewer) !== [];

        $directions = ['outbound' => [], 'return' => []];
        foreach ($offersByCarpool[$carpool->id] ?? [] as $offer) {
            $directions[$offer->direction][] = $this->offerView(
                $carpool,
                $offer,
                $requestsByOffer[$offer->id] ?? [],
                $viewer,
                $hasFamily
            );
        }

        return [
            'id' => $carpool->id,
            'title' => $carpool->title(),
            'address' => $carpool->address,
            'maps_url' => MapsLink::url($carpool->point, $carpool->address),
            'point_line' => $carpool->point?->line(),
            'dates' => $this->dates($carpool, $today),
            'outbound_day' => CarpoolFormat::day($carpool->outboundDate, $today),
            'return_day' => $carpool->returnDate !== null ? CarpoolFormat::day($carpool->returnDate, $today) : null,
            'events' => array_map(static fn($e): string => $e->title, $carpool->events),
            'is_past' => $carpool->lastDate() < $today->format('Y-m-d'),
            'directions' => $directions,
            'staff_hides_passengers' => $viewer->role->hasAccess(\Core\Security\Role::CHIEF)
                && !$viewer->seesPassengersOf($carpool),
            // Said out loud on the page, because nothing else on it reveals
            // that a section besides the events' sees the passengers (#650).
            'creator_section' => $this->sectionNames([$carpool->sectionId])[$carpool->sectionId] ?? null,
            'day_banner' => $this->dayBanner(
                $carpool,
                $direction,
                $offersByCarpool[$carpool->id] ?? [],
                $requestsByOffer,
                $viewer,
                $today
            ),
        ];
    }

    /**
     * The reader's summary of the trip, when it is TODAY and they take part
     * in it (#835): what they need on the way, on top of the page.
     *
     * Every contact in it went through CarpoolViewer — the driver sees a
     * requester's phone from the request on, a requester sees the driver's
     * only once accepted — so the banner shows nothing the page below it
     * does not.
     *
     * The carpool's place is the arrival of the outbound and the shared
     * departure of the return; the offer's endpoint is the other end, a free
     * text (hence `meeting` / `destination` rather than from / to).
     *
     * @param list<Offer> $offers
     * @param array<int, list<SeatRequest>> $requestsByOffer
     * @return ?array<string, mixed>
     */
    private function dayBanner(
        Carpool $carpool,
        string $direction,
        array $offers,
        array $requestsByOffer,
        CarpoolViewer $viewer,
        \DateTimeImmutable $today
    ): ?array {
        $day = $direction === Offer::RETURN ? $carpool->returnDate : $carpool->outboundDate;
        if ($day === null || $day !== $today->format('Y-m-d')) {
            return null;
        }

        $inDirection = array_values(array_filter(
            $offers,
            static fn(Offer $offer): bool => $offer->direction === $direction
        ));
        $mine = Participation::best(Participation::forAccount($viewer->accountId, $inDirection, $requestsByOffer))[$direction] ?? null;
        if ($mine === null) {
            return null;
        }

        $offer = $mine->offer;
        $outbound = $direction === Offer::OUTBOUND;
        $banner = [
            'role' => $mine->role,
            'label' => $mine->label(),
            'tone' => $mine->role === Participation::PENDING ? 'warning' : 'info',
            'direction' => $outbound ? 'Aller' : 'Retour',
            'time' => CarpoolFormat::time($offer->departureTime),
            'meeting' => $outbound ? $offer->endpoint : $carpool->address,
            'destination' => $outbound ? $carpool->address : $offer->endpoint,
            'place' => $carpool->address,
            'driver' => $offer->driverName,
            'driver_phone' => null,
            'who' => [],
            'passengers' => [],
        ];

        if ($mine->role === Participation::DRIVER) {
            foreach ($requestsByOffer[$offer->id] ?? [] as $request) {
                if (!$request->isActive() || !$viewer->sees($carpool, $offer, $request)) {
                    continue;
                }
                $banner['passengers'][] = [
                    'who' => $request->passengerNames,
                    'by' => $request->requesterName,
                    'confirmed' => $request->isAccepted(),
                    'phone' => $viewer->seesRequesterPhone($offer, $request) ? $request->phone : null,
                ];
            }
        } elseif ($mine->request !== null) {
            $banner['who'] = $mine->request->passengerNames;
            $banner['driver_phone'] = $viewer->seesDriverPhone($mine->request) ? $offer->phone : null;
        }

        return $banner;
    }

    /**
     * The organisers' list: every carpool to come, with its counts where
     * the reader may see them.
     *
     * @return list<array<string, mixed>>
     */
    public function organizerList(CarpoolViewer $viewer, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $carpools = $this->carpools->findEndingOnOrAfter($today->format('Y-m-d'));
        [$offersByCarpool, $requestsByOffer] = $this->load($carpools);
        $names = $this->sectionNamesOf($carpools);

        $rows = [];
        foreach ($carpools as $carpool) {
            $offers = $offersByCarpool[$carpool->id] ?? [];
            $seats = 0;
            $taken = 0;
            $pending = 0;
            foreach ($offers as $offer) {
                $seats += $offer->seats;
                $requests = $requestsByOffer[$offer->id] ?? [];
                $taken += $this->taken($requests);
                $pending += count(array_filter($requests, static fn(SeatRequest $r): bool => $r->isPending()));
            }

            $visible = $viewer->seesPassengersOf($carpool);
            $rows[] = [
                'id' => $carpool->id,
                'title' => count($carpool->events) > 1
                    ? $carpool->title() . ' · ' . count($carpool->events) . ' évènements liés'
                    : ($carpool->events === [] ? 'Sans évènement' : $carpool->title()),
                // Built from sectionIds(), which is also what decides who
                // sees the passengers: one source, so the badges cannot
                // drift from the access they are describing. Before #650
                // they were read off the events alone, and a carpool whose
                // section had been chosen by hand showed none at all.
                'sections' => array_values($names[$carpool->id]),
                'address' => $carpool->address,
                'dates' => $this->dates($carpool, $today),
                'visible' => $visible,
                'may_organize' => $viewer->mayOrganize($carpool),
                'cars' => $visible ? count($offers) : null,
                'seats' => $visible ? $seats : null,
                'taken' => $visible ? $taken : null,
                'pending' => $visible ? $pending : null,
                'deletable' => $offers === [],
            ];
        }

        return $rows;
    }

    /**
     * The members linked to the reader's account, which is who a request
     * can name.
     *
     * @return list<MemberProfile>
     */
    public function family(CarpoolViewer $viewer): array
    {
        return $this->members !== null
            ? array_values($this->members->getLinkedMembers($viewer->email, $viewer->scoutYearId))
            : [];
    }

    /**
     * What the forms start with (D5, D6): the account holder as driver, and
     * the family's phone from its record — both editable, and the phone
     * shown with who will see it before anything is saved.
     *
     * @return array{driver_name: string, phone: string, requester_name: string,
     *               family: list<array{id: int, label: string}>}
     */
    public function prefill(CarpoolViewer $viewer): array
    {
        $family = $this->family($viewer);
        $account = $this->accounts?->findById($viewer->accountId);
        $holder = $account !== null ? trim(($account->firstName ?? '') . ' ' . ($account->lastName ?? '')) : '';

        $phone = '';
        foreach ($family as $member) {
            $candidate = $member->mobile ?? $member->phone;
            if ($candidate !== null && trim($candidate) !== '') {
                $phone = trim($candidate);
                break;
            }
        }

        $familyName = $account?->lastName !== null && trim($account->lastName) !== ''
            ? trim($account->lastName)
            : ($family[0]->lastName ?? '');

        return [
            'driver_name' => $holder,
            'phone' => $phone,
            'requester_name' => $familyName !== ''
                ? 'Famille ' . $familyName
                : ($holder !== '' ? $holder : 'Une famille'),
            'family' => array_map(
                static fn(MemberProfile $m): array => ['id' => $m->memberYearId, 'label' => $m->getDisplayNameFull()],
                $family
            ),
        ];
    }

    /**
     * Section names for a set of ids, resolved in ONE query and WITHOUT the
     * active/visible filtering `getAllWithBranches()` applies: a carpool's
     * section is frozen at creation (#650), so it may name a section since
     * deactivated — and « Géré aussi par : … » must still say which one
     * rather than fall silent.
     *
     * @param list<?int> $ids
     * @return array<int, string> keyed by section id, absent ids omitted
     */
    private function sectionNames(array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if ($id !== null) {
                $wanted[$id] = $id;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $names = [];
        foreach ($this->sections->findByIds(array_values($wanted)) as $id => $section) {
            $names[$id] = $section['name'] ?? $section['desk_code'];
        }

        return $names;
    }

    /**
     * Each carpool's managing sections, as names, in sectionIds() order.
     *
     * @param list<Carpool> $carpools
     * @return array<int, array<int, string>> keyed by carpool id, then by
     *         section id
     */
    private function sectionNamesOf(array $carpools): array
    {
        $ids = [];
        foreach ($carpools as $carpool) {
            foreach ($carpool->sectionIds() as $sectionId) {
                $ids[] = $sectionId;
            }
        }
        $names = $this->sectionNames($ids);

        $byCarpool = [];
        foreach ($carpools as $carpool) {
            $byCarpool[$carpool->id] = [];
            foreach ($carpool->sectionIds() as $sectionId) {
                if (isset($names[$sectionId])) {
                    $byCarpool[$carpool->id][$sectionId] = $names[$sectionId];
                }
            }
        }

        return $byCarpool;
    }

    /**
     * @param list<SeatRequest> $requests
     * @return array<string, mixed>
     */
    private function offerView(
        Carpool $carpool,
        Offer $offer,
        array $requests,
        CarpoolViewer $viewer,
        bool $hasFamily
    ): array {
        $taken = $this->taken($requests);
        $left = $offer->seats - $taken;
        $mine = null;
        $visibleRequests = [];
        foreach ($requests as $request) {
            if ($request->requesterAccountId === $viewer->accountId && $request->isActive()) {
                $mine = [
                    'id' => $request->id,
                    'who' => $request->passengerNames,
                    'status' => $request->status,
                    'driver_phone' => $viewer->seesDriverPhone($request) ? $offer->phone : null,
                ];
            }
            if (!$viewer->sees($carpool, $offer, $request)) {
                continue;
            }
            $visibleRequests[] = [
                'id' => $request->id,
                'who' => $request->passengerNames,
                'count' => $request->passengerCount,
                'by' => $request->requesterName,
                'status' => $request->status,
                'status_label' => self::STATUS_LABELS[$request->status] ?? $request->status,
                'phone' => $viewer->seesRequesterPhone($offer, $request) ? $request->phone : null,
                'may_decide' => $viewer->drives($offer) && $request->isPending(),
                'may_revoke' => $viewer->drives($offer) && $request->isAccepted(),
            ];
        }

        return [
            'id' => $offer->id,
            'direction' => $offer->direction,
            'driver' => $offer->driverName,
            'mine' => $viewer->drives($offer),
            'time' => CarpoolFormat::time($offer->departureTime),
            'endpoint' => $offer->endpoint,
            'note' => $offer->note,
            'seats' => $offer->seats,
            'taken' => $taken,
            'left' => $left,
            'free_text' => CarpoolFormat::freeSeats($left),
            'my_request' => $mine,
            'may_request' => !$viewer->drives($offer) && $mine === null && $left > 0 && $hasFamily,
            'sees_requests' => $viewer->seesRequestsOf($carpool, $offer),
            'requests' => $visibleRequests,
        ];
    }

    /**
     * @param list<Carpool> $carpools
     * @return array{0: array<int, list<Offer>>, 1: array<int, list<SeatRequest>>}
     */
    private function load(array $carpools): array
    {
        $offersByCarpool = $this->offers->findByCarpools(array_map(static fn(Carpool $c): int => $c->id, $carpools));
        $offerIds = [];
        foreach ($offersByCarpool as $offers) {
            foreach ($offers as $offer) {
                $offerIds[] = $offer->id;
            }
        }

        return [$offersByCarpool, $this->requests->findByOffers($offerIds)];
    }

    /**
     * Seats granted, counted in people.
     *
     * @param list<SeatRequest> $requests
     */
    private function taken(array $requests): int
    {
        $taken = 0;
        foreach ($requests as $request) {
            if ($request->isAccepted()) {
                $taken += $request->passengerCount;
            }
        }

        return $taken;
    }

    private function dates(Carpool $carpool, ?\DateTimeImmutable $today = null): string
    {
        $outbound = CarpoolFormat::day($carpool->outboundDate, $today);

        return $carpool->returnDate !== null && $carpool->returnDate !== $carpool->outboundDate
            ? $outbound . ' et ' . CarpoolFormat::day($carpool->returnDate, $today)
            : $outbound;
    }
}
