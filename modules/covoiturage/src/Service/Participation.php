<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\SeatRequest;

/**
 * « Ma participation » (#835): what one account has in one carpool, per
 * direction — the single rule the list's badges, the banner of the day and
 * the agenda line all read, so that the three cannot interpret the same
 * state differently.
 *
 * Three roles, and nothing else is a participation: the **driver** of an
 * offer, a **confirmed** seat (an accepted request), a **pending** request.
 * A refused, revoked or withdrawn request holds nothing and says nothing.
 *
 * **A role belongs to a direction.** The same person may drive out and ride
 * back; the two are never folded into one status.
 *
 * Who may SEE a phone is not decided here: that stays in CarpoolViewer.
 */
final class Participation
{
    public const DRIVER = 'driver';
    public const CONFIRMED = 'confirmed';
    public const PENDING = 'pending';

    /** Lower is stronger, for choosing one role when a direction has several. */
    private const RANK = [self::DRIVER => 0, self::CONFIRMED => 1, self::PENDING => 2];

    private const LABELS = [
        self::DRIVER => 'Conducteur',
        self::CONFIRMED => 'Place confirmée',
        self::PENDING => 'À confirmer',
    ];

    /** The site's status vocabulary (partials/status_badge.html.twig). */
    private const BADGE_STATUS = [
        self::DRIVER => 'info',
        self::CONFIRMED => 'confirmed',
        self::PENDING => 'pending',
    ];

    public function __construct(
        public readonly string $role,
        public readonly Offer $offer,
        /** The request that makes it a seat; null for a driver. */
        public readonly ?SeatRequest $request = null
    ) {
    }

    public function direction(): string
    {
        return $this->offer->direction;
    }

    public function label(): string
    {
        return self::LABELS[$this->role];
    }

    /**
     * Every participation of an account in the offers of one carpool, in
     * the order of the offers.
     *
     * @param list<Offer> $offers
     * @param array<int, list<SeatRequest>> $requestsByOffer keyed by offer id
     * @return list<self>
     */
    public static function forAccount(int $accountId, array $offers, array $requestsByOffer): array
    {
        $found = [];
        foreach ($offers as $offer) {
            if ($offer->driverAccountId === $accountId) {
                $found[] = new self(self::DRIVER, $offer);
            }
            foreach ($requestsByOffer[$offer->id] ?? [] as $request) {
                if ($request->requesterAccountId !== $accountId || !$request->isActive()) {
                    continue;
                }
                $found[] = new self($request->isAccepted() ? self::CONFIRMED : self::PENDING, $offer, $request);
            }
        }

        return $found;
    }

    /**
     * The strongest participation of each direction.
     *
     * @param list<self> $participations
     * @return array<string, self> keyed by direction; absent when none
     */
    public static function best(array $participations): array
    {
        $best = [];
        foreach ($participations as $participation) {
            $direction = $participation->direction();
            if (!isset($best[$direction]) || self::RANK[$participation->role] < self::RANK[$best[$direction]->role]) {
                $best[$direction] = $participation;
            }
        }

        return $best;
    }

    /**
     * The badges of the carpool list. The direction is named only when it
     * tells something: the carpool has a return AND the roles are not the
     * same both ways.
     *
     * @param list<self> $participations
     * @return list<array{status: string, label: string}>
     */
    public static function badges(array $participations, bool $hasReturn): array
    {
        $best = self::best($participations);
        if ($best === []) {
            return [];
        }

        $badge = static fn(self $p, string $prefix = ''): array => [
            'status' => self::BADGE_STATUS[$p->role],
            'label' => $prefix . $p->label(),
        ];

        if (!$hasReturn) {
            return [$badge(reset($best))];
        }
        if (count($best) === 2 && $best[Offer::OUTBOUND]->role === $best[Offer::RETURN]->role) {
            return [$badge($best[Offer::OUTBOUND])];
        }

        $badges = [];
        foreach ([Offer::OUTBOUND => 'Aller · ', Offer::RETURN => 'Retour · '] as $direction => $prefix) {
            if (isset($best[$direction])) {
                $badges[] = $badge($best[$direction], $prefix);
            }
        }

        return $badges;
    }

    /**
     * Whether every participation is on the return trip (the agenda link then opens it).
     *
     * @param list<self> $participations
     */
    public static function onlyOnTheReturn(array $participations): bool
    {
        if ($participations === []) {
            return false;
        }
        foreach ($participations as $participation) {
            if ($participation->direction() !== Offer::RETURN) {
                return false;
            }
        }

        return true;
    }
}
