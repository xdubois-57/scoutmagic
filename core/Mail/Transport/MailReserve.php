<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * How much of a provider's daily quota the mailing lane may not spend
 * (D8, ARCHITECTURE.md §8.106).
 *
 * **Calculated, never settable.** A percentage of the quota would be
 * arbitrary: what has to survive a publipostage is a day's worth of
 * sign-in links and transactional mail, and that depends on the size of
 * the unit, not on the generosity of the relay. So the reserve is the
 * unit's own measured peak — the busiest non-bulk day of the last thirty
 * — plus a margin for a day busier than any seen yet.
 *
 * Three bounds make that usable rather than merely correct:
 *
 * - **A floor**, because a fresh installation has no history at all and
 *   « zero measured, therefore reserve nothing » is exactly wrong on the
 *   day it first matters.
 * - **A ceiling at half the quota**, because a reserve that ate the whole
 *   quota would stop the mailing entirely, and a publipostage that never
 *   leaves is not a protected inbox.
 * - **It applies only where there is something to protect** — a provider
 *   that serves the mailing lane AND at least one other. A relay used for
 *   bulk alone has no sign-in link to shield, so reserving part of it
 *   would cost the unit throughput for nothing.
 *
 * {@see Reserve} carries the number together with the peak it came from,
 * because the screen has to say where it comes from; a number a volunteer
 * cannot account for is one they will assume is wrong.
 */
final class MailReserve
{
    /** The window the peak is measured over. */
    public const WINDOW_DAYS = 30;

    /**
     * Added to the measured peak: tomorrow may be busier than any day
     * behind it, and the reserve exists precisely for the day that is.
     */
    public const MARGIN = 20;

    /** What a site with no history reserves — see the class docblock. */
    public const FLOOR = 30;

    public function __construct(
        private SendCounterRepository $counters,
        private LaneChainRepository $chains
    ) {
    }

    /**
     * The reserve standing on one provider right now.
     *
     * @param ?string $today Injected by the tests; production reads the clock.
     */
    public function forProvider(MailProvider $provider, ?string $today = null): Reserve
    {
        if ($provider->dailyQuota === null) {
            // Nothing to divide. The local send is the usual case, and it
            // has no known ceiling by decision (D6).
            return Reserve::none('Ce fournisseur n’a pas de quota journalier connu, donc rien à réserver.');
        }

        if (!$this->servesBulkAndAnother($provider->id)) {
            return Reserve::none(
                'Ce fournisseur ne sert pas à la fois le publipostage et une autre voie, '
                . 'donc aucun trafic n’a besoin d’être protégé de lui.'
            );
        }

        $daily = $this->counters->dailyNonBulkTotals(self::WINDOW_DAYS, $today);
        $peak = $daily === [] ? 0 : max($daily);

        $wanted = $peak > 0 ? $peak + self::MARGIN : self::FLOOR;
        $ceiling = intdiv($provider->dailyQuota, 2);

        return new Reserve(
            min($wanted, $ceiling),
            $peak,
            $daily === [],
            $wanted > $ceiling
        );
    }

    /**
     * How many messages $lane may hand to $provider today, all lanes
     * counted together — the ONE reading of « full » (issue #1454), shared
     * by the chain that sends (`MailTransportChain`), the question a
     * mailing asks before a batch (`BulkCadence::hasRoomLeft()`) and the
     * cadence it paces itself on (`BulkCadence::activeProvider()`). Three
     * copies of this arithmetic had already drifted once: the cadence
     * compared against the raw quota while the chain took the reserve off,
     * and so paced a mailing on a relay the chain would no longer use.
     *
     * Null when the provider has no known quota. On the mailing lane the
     * reserve comes off the top (D8), never below zero; the other lanes,
     * which the reserve protects, see the whole quota. **A reserve that
     * cannot be read leaves the quota whole**: it must never become the
     * reason a lane stops sending — the posture of every reading here.
     */
    public static function laneCeiling(?self $reserve, MailProvider $provider, MailLane $lane): ?int
    {
        if ($provider->dailyQuota === null) {
            return null;
        }

        if ($lane !== MailLane::Bulk || $reserve === null) {
            return $provider->dailyQuota;
        }

        try {
            return max(0, $provider->dailyQuota - $reserve->forProvider($provider)->messages);
        } catch (\Throwable) {
            return $provider->dailyQuota;
        }
    }

    /**
     * Whether this provider carries the mailing lane and at least one
     * other — the only case where a reserve means anything.
     *
     * Disabled entries do not count: an entry a superadmin switched off
     * carries nothing, so it protects nothing either.
     */
    private function servesBulkAndAnother(int $providerId): bool
    {
        $carriesBulk = false;
        $carriesOther = false;

        foreach (MailLane::ordered() as $lane) {
            foreach ($this->chains->forLane($lane) as $entry) {
                if ($entry->providerId !== $providerId || !$entry->enabled) {
                    continue;
                }

                if ($lane === MailLane::Bulk) {
                    $carriesBulk = true;
                } else {
                    $carriesOther = true;
                }
            }
        }

        return $carriesBulk && $carriesOther;
    }
}
