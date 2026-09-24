<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Member\MemberProfile;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;

/**
 * Seats offered and asked — the members' half of the module. Every
 * identified member offers or asks (D1); every rule about who may do what
 * to whom is checked here, the controller only passes the viewer along.
 *
 * **The place is never an input.** An offer carries its meeting point and
 * nothing else: at the outbound, the destination is the carpool's place;
 * at the return, the departure is. Whatever a form posts about the other
 * end is not read.
 *
 * **Seats are counted in people, and only acceptance holds them (D4).** A
 * pending request reserves nothing — a phantom booking is not possible —
 * and a request is accepted whole: the driver refuses one too large, and
 * the family asks again for fewer people.
 */
class OfferService
{
    /** A car, not a coach. */
    public const MAX_SEATS = 8;

    public function __construct(
        private OfferRepository $offers,
        private SeatRequestRepository $requests,
        private \PDO $pdo
    ) {
    }

    /**
     * One offer, or two when the driver also offers the return — two
     * separate cars in the model, each with its seats and its requests.
     *
     * @param array<string, mixed> $input
     * @return list<int> the offer ids created
     * @throws CarpoolException
     */
    public function propose(Carpool $carpool, array $input, CarpoolViewer $viewer): array
    {
        $this->assertStillAhead($carpool);

        $direction = ($input['direction'] ?? Offer::OUTBOUND) === Offer::RETURN ? Offer::RETURN : Offer::OUTBOUND;
        if ($direction === Offer::RETURN && !$carpool->hasReturn()) {
            throw new CarpoolException('Ce covoiturage n\'a pas de retour.');
        }
        $common = $this->validateCommon($input, 1);
        $time = $this->time($input['departure_time'] ?? '');
        // Everything is checked before the first row is written: an
        // outbound car saved on its own would be created a second time when
        // the driver fixes the return time and resubmits.
        $returnTime = null;
        if (($input['also_return'] ?? '') === '1' && $direction === Offer::OUTBOUND && $carpool->hasReturn()) {
            $returnTime = $this->time(
                $input['return_departure_time'] ?? '',
                'Indiquez l\'heure de départ du retour, par exemple 16:00.'
            );
        }

        $ids = [$this->offers->create(
            $carpool->id,
            $direction,
            $time,
            $common['endpoint'],
            $common['seats'],
            $viewer->accountId,
            $common['driver_name'],
            $common['phone'],
            $common['note']
        )];

        if ($returnTime !== null) {
            $ids[] = $this->offers->create(
                $carpool->id,
                Offer::RETURN,
                $returnTime,
                $common['endpoint'],
                $common['seats'],
                $viewer->accountId,
                $common['driver_name'],
                $common['phone'],
                $common['note']
            );
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $input
     * @return bool whether the time or the meeting point changed — what the
     *              passengers already accepted have to be told
     * @throws CarpoolException
     */
    public function update(Offer $offer, array $input, CarpoolViewer $viewer): bool
    {
        $this->assertDriver($offer, $viewer);
        // Checked before the general range: « 3 places sont déjà accordées »
        // is the sentence that tells the driver what to do.
        $taken = $this->requests->acceptedSeats($offer->id);
        if ((int) ($input['seats'] ?? 0) < $taken) {
            throw new CarpoolException(self::seatsBelowTaken($taken));
        }
        $common = $this->validateCommon($input, 1);
        $time = $this->time($input['departure_time'] ?? '');

        $this->offers->update(
            $offer->id,
            $time,
            $common['endpoint'],
            $common['seats'],
            $common['driver_name'],
            $common['phone'],
            $common['note']
        );

        return $time !== $offer->departureTime || $common['endpoint'] !== $offer->endpoint;
    }

    /**
     * The sentence the edit form and the refusal share, so the screen says
     * why before the driver even tries.
     */
    public static function seatsBelowTaken(int $taken): string
    {
        return $taken > 1
            ? "Impossible : {$taken} places sont déjà accordées. Retirez d'abord une place accordée."
            : 'Impossible : 1 place est déjà accordée. Retirez d\'abord la place accordée.';
    }

    /**
     * « Retirer ma voiture »: the offer goes, and every request on it.
     *
     * @return list<SeatRequest> the requests that were pending or accepted,
     *                           for whoever has to be told
     * @throws CarpoolException
     */
    public function cancel(Offer $offer, CarpoolViewer $viewer): array
    {
        $this->assertDriver($offer, $viewer);
        $affected = array_values(array_filter(
            $this->requests->findByOffers([$offer->id])[$offer->id] ?? [],
            static fn(SeatRequest $r): bool => $r->isActive()
        ));
        $this->offers->delete($offer->id);

        return $affected;
    }

    /**
     * Seats asked for named people of the requester's family.
     *
     * @param array<string, mixed> $input
     * @param list<MemberProfile> $family the members linked to the account —
     *        the only people a request can name
     * @throws CarpoolException
     */
    public function request(
        Carpool $carpool,
        Offer $offer,
        array $input,
        CarpoolViewer $viewer,
        array $family,
        string $requesterName
    ): int {
        $this->assertStillAhead($carpool);
        if ($viewer->drives($offer)) {
            throw new CarpoolException('C\'est votre propre voiture : vous n\'avez pas besoin d\'y demander une place.');
        }
        foreach ($this->requests->findByOffers([$offer->id])[$offer->id] ?? [] as $existing) {
            if ($existing->requesterAccountId === $viewer->accountId && $existing->isActive()) {
                throw new CarpoolException('Vous avez déjà une demande dans cette voiture.');
            }
        }

        $chosen = array_map('intval', is_array($input['passengers'] ?? null) ? $input['passengers'] : []);
        $names = [];
        foreach ($family as $member) {
            if (in_array($member->memberYearId, $chosen, true)) {
                $names[] = $member->getDisplayNameFull();
            }
        }
        if ($names === []) {
            throw new CarpoolException('Choisissez pour qui vous demandez une place.');
        }

        $free = $offer->seats - $this->requests->acceptedSeats($offer->id);
        if (count($names) > $free) {
            throw new CarpoolException(
                'Il ne reste que ' . CarpoolFormat::freeSeats($free) . ' dans cette voiture : demandez pour moins '
                . 'de personnes, ou dans une autre voiture.'
            );
        }

        return $this->requests->create(
            $offer->id,
            $viewer->accountId,
            $requesterName,
            $names,
            $this->phone($input['phone'] ?? '')
        );
    }

    /**
     * Accepting holds the seats — all of them, or none (D4).
     *
     * @throws CarpoolException
     */
    public function accept(SeatRequest $request, Offer $offer, CarpoolViewer $viewer): void
    {
        $this->assertDriver($offer, $viewer);
        if (!$request->isPending()) {
            throw new CarpoolException('Cette demande a déjà reçu une réponse.');
        }

        $this->pdo->beginTransaction();
        try {
            $free = $offer->seats - $this->requests->acceptedSeats($offer->id);
            if ($request->passengerCount > $free) {
                throw new CarpoolException(
                    'Il ne reste que ' . CarpoolFormat::freeSeats($free) . ' : une demande s\'accepte entière. '
                    . 'Refusez-la, la famille pourra redemander pour moins de personnes.'
                );
            }
            $this->requests->setStatus($request->id, SeatRequest::ACCEPTED);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @throws CarpoolException */
    public function refuse(SeatRequest $request, Offer $offer, CarpoolViewer $viewer): void
    {
        $this->assertDriver($offer, $viewer);
        if (!$request->isPending()) {
            throw new CarpoolException('Cette demande a déjà reçu une réponse.');
        }
        $this->requests->setStatus($request->id, SeatRequest::REFUSED);
    }

    /**
     * « Retirer cette place »: a seat granted and taken back. Not a refusal
     * — the family had its seat and was organising around it — so it keeps
     * its own status, and its own message.
     *
     * @throws CarpoolException
     */
    public function revoke(SeatRequest $request, Offer $offer, CarpoolViewer $viewer): void
    {
        $this->assertDriver($offer, $viewer);
        if (!$request->isAccepted()) {
            throw new CarpoolException('Seule une place accordée peut être retirée.');
        }
        $this->requests->setStatus($request->id, SeatRequest::REVOKED);
    }

    /**
     * The requester takes their request back, granted or not: the row goes.
     *
     * @throws CarpoolException
     */
    public function withdraw(SeatRequest $request, CarpoolViewer $viewer): void
    {
        if ($request->requesterAccountId !== $viewer->accountId) {
            throw new CarpoolException('Cette demande n\'est pas la vôtre.');
        }
        if (!$request->isActive()) {
            throw new CarpoolException('Cette demande n\'est plus en cours.');
        }
        $this->requests->delete($request->id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{endpoint: string, seats: int, driver_name: string, phone: string, note: ?string}
     * @throws CarpoolException
     */
    private function validateCommon(array $input, int $minimumSeats): array
    {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new CarpoolException('Indiquez le point de rendez-vous : un parking, une gare, un carrefour.');
        }
        if (mb_strlen($endpoint) > 150) {
            throw new CarpoolException('Le point de rendez-vous est trop long (150 caractères au plus).');
        }

        $seats = (int) ($input['seats'] ?? 0);
        if ($seats < $minimumSeats || $seats > self::MAX_SEATS) {
            throw new CarpoolException('Le nombre de places doit être compris entre ' . $minimumSeats . ' et '
                . self::MAX_SEATS . '.');
        }

        $driverName = trim((string) ($input['driver_name'] ?? ''));
        if ($driverName === '') {
            throw new CarpoolException('Indiquez qui conduit.');
        }
        if (mb_strlen($driverName) > 120) {
            throw new CarpoolException('Le nom du conducteur est trop long (120 caractères au plus).');
        }

        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            throw new CarpoolException('La note est trop longue (500 caractères au plus).');
        }

        return [
            'endpoint' => $endpoint,
            'seats' => $seats,
            'driver_name' => $driverName,
            'phone' => $this->phone($input['phone'] ?? ''),
            'note' => $note !== '' ? $note : null,
        ];
    }

    private function phone(mixed $value): string
    {
        $phone = trim((string) $value);
        if ($phone === '' || preg_match('/^[+0-9][0-9 .\/()-]{5,24}$/', $phone) !== 1) {
            throw new CarpoolException('Indiquez un numéro de téléphone, par exemple 0471 23 45 67.');
        }

        return $phone;
    }

    private function time(
        mixed $value,
        string $refusal = 'Indiquez l\'heure de départ, par exemple 08:30.'
    ): string {
        $time = trim((string) $value);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw new CarpoolException($refusal);
        }

        return $time;
    }

    private function assertDriver(Offer $offer, CarpoolViewer $viewer): void
    {
        if (!$viewer->drives($offer)) {
            throw new CarpoolException('Seul le conducteur de cette voiture peut le faire.');
        }
    }

    private function assertStillAhead(Carpool $carpool): void
    {
        if ($carpool->lastDate() < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new CarpoolException('Ce covoiturage est passé.');
        }
    }
}
