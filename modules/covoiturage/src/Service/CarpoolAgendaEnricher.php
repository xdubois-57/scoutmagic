<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\EventDescriptionEnricherInterface;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;

/**
 * The agenda line: under each event a carpool is linked to, in the
 * reader's personal ICS feed, what THIS reader has in it —
 *
 *     Covoiturage — vous conduisez à l'aller, 8 h 30, parking des locaux. 2 places libres.
 *     Covoiturage — retour 16 h 00, gare de Wavre : confirmé.
 *     Voir : https://…/covoiturage/1
 *
 * Plugged into calendar's Api\EventDescriptionEnricherInterface, which the
 * personal feed alone reads (IT-03): a calendar's own feed and the unit feed
 * have no identified reader, and never see a line.
 *
 * - **On every linked event.** A parent of two sections sees the unit party
 *   twice; the event is already replicated there, and so is its line.
 * - **The status is in the line**: a seat obtained is not a request waiting.
 *   A refused or revoked request has no line, and since the feed is built
 *   at every fetch, it disappears at the client's next refresh.
 * - **Never a phone number (D12).** An ICS file lands in clear at Google or
 *   iCloud. Place, time, status and the link — which asks for a session —
 *   and nothing else.
 */
class CarpoolAgendaEnricher implements EventDescriptionEnricherInterface
{
    public function __construct(
        private CarpoolRepository $carpools,
        private OfferRepository $offers,
        private SeatRequestRepository $requests,
        private UserAccountRepository $accounts,
        private string $baseUrl = ''
    ) {
    }

    public function enricherId(): string
    {
        return 'covoiturage';
    }

    public function describeEvents(array $eventIds, VirtualEventViewer $viewer): array
    {
        if (!$viewer->isIdentified() || $eventIds === []) {
            return [];
        }
        $account = $this->accounts->findByEmail((string) $viewer->email);
        if ($account === null) {
            return [];
        }

        $carpoolByEvent = $this->carpools->carpoolIdsByEvent($eventIds);
        if ($carpoolByEvent === []) {
            return [];
        }

        // One pass for all carpools of the feed (§7.6, rule 1).
        $linesByCarpool = [];
        $carpoolIds = array_values(array_unique($carpoolByEvent));
        $offersByCarpool = $this->offers->findByCarpools($carpoolIds);
        $offerIds = [];
        foreach ($offersByCarpool as $offers) {
            foreach ($offers as $offer) {
                $offerIds[] = $offer->id;
            }
        }
        $requestsByOffer = $this->requests->findByOffers($offerIds);

        foreach ($carpoolIds as $carpoolId) {
            $lines = [];
            foreach ($offersByCarpool[$carpoolId] ?? [] as $offer) {
                $requests = $requestsByOffer[$offer->id] ?? [];
                if ($offer->driverAccountId === $account->id) {
                    $taken = 0;
                    foreach ($requests as $request) {
                        $taken += $request->isAccepted() ? $request->passengerCount : 0;
                    }
                    $lines[] = 'Covoiturage — vous conduisez ' . ($offer->isOutbound() ? 'à l\'aller' : 'au retour')
                        . ', ' . CarpoolFormat::time($offer->departureTime) . ', ' . $offer->endpoint . '. '
                        . ucfirst(CarpoolFormat::freeSeats($offer->seats - $taken)) . '.';
                }
                foreach ($requests as $request) {
                    if ($request->requesterAccountId !== $account->id || !$request->isActive()) {
                        continue;
                    }
                    $lines[] = 'Covoiturage — ' . self::direction($offer) . ' '
                        . CarpoolFormat::time($offer->departureTime) . ', ' . $offer->endpoint . ' : '
                        . ($request->isAccepted() ? 'confirmé' : 'en attente') . '.';
                }
            }
            if ($lines !== []) {
                $lines[] = 'Voir : ' . rtrim($this->baseUrl, '/') . '/covoiturage/' . $carpoolId;
                $linesByCarpool[$carpoolId] = $lines;
            }
        }

        $result = [];
        foreach ($carpoolByEvent as $eventId => $carpoolId) {
            if (isset($linesByCarpool[$carpoolId])) {
                $result[$eventId] = $linesByCarpool[$carpoolId];
            }
        }

        return $result;
    }

    private static function direction(Offer $offer): string
    {
        return $offer->isOutbound() ? 'aller' : 'retour';
    }
}
