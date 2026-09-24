<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Notification\NotificationService;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\SeatRequest;

/**
 * The module's eight notifications, each to its one party (IT-05).
 *
 * | covoiturage.request_received  | the driver                          |
 * | covoiturage.request_pending   | the driver (reminder, from a task)  |
 * | covoiturage.request_withdrawn | the driver                          |
 * | covoiturage.request_accepted  | the requester                       |
 * | covoiturage.request_refused   | the requester                       |
 * | covoiturage.seat_revoked      | the requester                       |
 * | covoiturage.offer_changed     | the accepted passengers             |
 * | covoiturage.offer_cancelled   | the accepted and pending passengers |
 *
 * **The staff receives nothing on account of its role (D11)**: it
 * consults, it does not arbitrate. Nothing here ever looks a recipient up
 * by role — every recipient is a party to the request or the car — so a
 * chief who drives gets the driver's notifications, and once.
 *
 * `in_app` is locked on every type (module.json): it is the one channel
 * certain to arrive, since dispatch() always writes the row. A member who
 * could switch it off would never learn that their request was accepted.
 *
 * `seat_revoked` is NOT `request_refused`: a family that never had a seat
 * and a family that loses one do not live the same thing, and the message
 * cannot be the same.
 *
 * No phone number ever goes into a notification: a push lands on a lock
 * screen and an e-mail in a mailbox, and the number is on the page for
 * whoever is entitled to it.
 */
class CarpoolNotifier
{
    public function __construct(private ?NotificationService $notifications = null)
    {
    }

    public function requestReceived(Carpool $carpool, Offer $offer, SeatRequest $request): void
    {
        $this->send('covoiturage.request_received', [$offer->driverAccountId], [
            'title' => 'Nouvelle demande de place',
            'body' => $request->requesterName . ' demande ' . self::seats($request->passengerCount) . ' — '
                . self::trip($carpool, $offer) . ', ' . CarpoolFormat::time($offer->departureTime) . '.',
            'url' => self::url($carpool, $offer),
        ]);
    }

    public function requestPending(Carpool $carpool, Offer $offer, SeatRequest $request, int $daysWaiting, int $daysBefore): void
    {
        $this->send('covoiturage.request_pending', [$offer->driverAccountId], [
            'title' => 'Une demande attend votre réponse',
            'body' => $request->requesterName . ', depuis ' . self::days($daysWaiting) . '. '
                . ($daysBefore <= 0 ? 'Départ aujourd\'hui.' : 'Départ dans ' . self::days($daysBefore) . '.'),
            'url' => self::url($carpool, $offer),
        ]);
    }

    public function requestWithdrawn(Carpool $carpool, Offer $offer, SeatRequest $request): void
    {
        $this->send('covoiturage.request_withdrawn', [$offer->driverAccountId], [
            'title' => 'Une demande a été retirée',
            'body' => $request->requesterName . ' a retiré sa demande — '
                . ($request->isAccepted()
                    ? self::seats($request->passengerCount) . ($request->passengerCount > 1 ? ' se libèrent' : ' se libère')
                        . ' sur ' . ($offer->isOutbound() ? 'l\'' : 'le ') . self::trip($carpool, $offer)
                    : self::trip($carpool, $offer))
                . '.',
            'url' => self::url($carpool, $offer),
        ]);
    }

    public function requestAccepted(Carpool $carpool, Offer $offer, SeatRequest $request): void
    {
        $this->send('covoiturage.request_accepted', [$request->requesterAccountId], [
            'title' => 'Votre place est confirmée',
            'body' => $offer->driverName . ' a accepté ' . self::seats($request->passengerCount) . ' — '
                . self::trip($carpool, $offer) . ', ' . $offer->endpoint . ', ' . CarpoolFormat::time($offer->departureTime) . '.',
            'url' => self::url($carpool, $offer),
        ]);
    }

    public function requestRefused(Carpool $carpool, Offer $offer, SeatRequest $request): void
    {
        $this->send('covoiturage.request_refused', [$request->requesterAccountId], [
            'title' => 'Demande refusée',
            'body' => $offer->driverName . ' ne peut pas vous prendre — ' . self::trip($carpool, $offer)
                . '. D\'autres voitures existent.',
            'url' => self::url($carpool, $offer),
        ]);
    }

    public function seatRevoked(Carpool $carpool, Offer $offer, SeatRequest $request): void
    {
        $this->send('covoiturage.seat_revoked', [$request->requesterAccountId], [
            'title' => 'Votre place a été retirée',
            'body' => $offer->driverName . ' ne peut plus vous prendre — ' . self::trip($carpool, $offer) . '.',
            'url' => self::url($carpool, $offer),
        ]);
    }

    /**
     * @param list<SeatRequest> $requests every request on the offer; only
     *        the accepted ones are told
     */
    public function offerChanged(Carpool $carpool, Offer $before, Offer $after, array $requests): void
    {
        $recipients = [];
        foreach ($requests as $request) {
            if ($request->isAccepted()) {
                $recipients[] = $request->requesterAccountId;
            }
        }

        // Each change is said with what it replaces, and both when both
        // moved: a meeting point printed bare reads as unchanged, and a
        // passenger would go to the old one.
        $timeChanged = $before->departureTime !== $after->departureTime;
        $placeChanged = $before->endpoint !== $after->endpoint;
        $time = $timeChanged
            ? 'départ à ' . CarpoolFormat::time($after->departureTime) . ' au lieu de '
                . CarpoolFormat::time($before->departureTime)
            : 'départ à ' . CarpoolFormat::time($after->departureTime);
        $place = $placeChanged
            ? 'rendez-vous à ' . $after->endpoint . ' au lieu de ' . $before->endpoint
            : 'rendez-vous à ' . $after->endpoint;
        $body = ucfirst(self::trip($carpool, $after)) . ' : '
            . ($placeChanged && !$timeChanged ? $place . ', ' . $time : $time . ', ' . $place) . '.';

        $this->send('covoiturage.offer_changed', $recipients, [
            'title' => 'La voiture a changé',
            'body' => $body,
            'url' => self::url($carpool, $after),
        ]);
    }

    /**
     * @param list<SeatRequest> $affected the pending and accepted requests
     *        the cancelled car carried
     */
    public function offerCancelled(Carpool $carpool, Offer $offer, array $affected): void
    {
        $accepted = [];
        $pending = [];
        foreach ($affected as $request) {
            if ($request->isAccepted()) {
                $accepted[] = $request->requesterAccountId;
            } elseif ($request->isPending()) {
                $pending[] = $request->requesterAccountId;
            }
        }

        $cancelled = $offer->driverName . ' a annulé ' . ($offer->isOutbound() ? 'son aller' : 'son retour')
            . ' du ' . CarpoolFormat::day($offer->isOutbound() ? $carpool->outboundDate : ($carpool->returnDate ?? $carpool->outboundDate)) . '.';
        $url = '/covoiturage/' . $carpool->id . ($offer->isOutbound() ? '' : '?sens=return');

        $this->send('covoiturage.offer_cancelled', $accepted, [
            'title' => 'Voiture annulée',
            'body' => $cancelled . ' Votre place n\'est plus réservée.',
            'url' => $url,
        ]);
        $this->send('covoiturage.offer_cancelled', array_values(array_diff($pending, $accepted)), [
            'title' => 'Voiture annulée',
            'body' => $cancelled . ' Votre demande est annulée avec elle.',
            'url' => $url,
        ]);
    }

    /**
     * @param list<int> $accountIds
     * @param array{title: string, body: string, url: string} $payload
     */
    private function send(string $type, array $accountIds, array $payload): void
    {
        if ($this->notifications === null) {
            return;
        }
        $recipients = [];
        foreach (array_unique($accountIds) as $accountId) {
            $recipients[] = ['userAccountId' => $accountId, 'memberId' => null];
        }
        if ($recipients === []) {
            return;
        }

        $this->notifications->dispatch($type, $recipients, $payload);
    }

    /** « l'aller du samedi 7 novembre », « le retour du dimanche 8 novembre ». */
    private static function trip(Carpool $carpool, Offer $offer): string
    {
        return $offer->isOutbound()
            ? 'aller du ' . CarpoolFormat::day($carpool->outboundDate)
            : 'retour du ' . CarpoolFormat::day($carpool->returnDate ?? $carpool->outboundDate);
    }

    private static function url(Carpool $carpool, Offer $offer): string
    {
        return '/covoiturage/' . $carpool->id . ($offer->isOutbound() ? '' : '?sens=return');
    }

    private static function seats(int $count): string
    {
        return $count > 1 ? $count . ' places' : '1 place';
    }

    private static function days(int $count): string
    {
        return $count > 1 ? $count . ' jours' : '1 jour';
    }
}
