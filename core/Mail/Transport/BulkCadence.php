<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * How fast the mailing lane may go right now (D6, D7,
 * ARCHITECTURE.md §8.106).
 *
 * A cadence belongs to a provider, not to a lane: it describes what that
 * relay accepts. So the answer is not a setting any more — it is read off
 * whichever provider the bulk lane would actually use for its next
 * message, and it changes the moment the lane falls back to the next one
 * (D7: a lane that switches adopts **its** cadence and **its** quota,
 * never the previous one's).
 *
 * `modules/mass_mail` is its one consumer today: `Task\SendBatchHandler`
 * asks how many copies to send now and how long to wait before the next
 * lot. Any later sender of the same shape asks the same question here
 * rather than growing a second pacing mechanism.
 *
 * With no chain readable at all — a database that has not migrated yet —
 * the local send's prudent default is the answer, which is the same
 * degradation as everywhere else in this namespace: slower, never
 * stopped.
 */
final class BulkCadence
{
    public function __construct(
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        /**
         * The share of each quota the mailing lane leaves to the other two
         * (D8) — what {@see hasRoomLeft()} takes off, as the chain does.
         * Null reads every quota whole, which is what a narrow test wants.
         */
        private ?MailReserve $reserve = null
    ) {
    }

    /**
     * Whether the mailing lane could hand its next message to a relay right
     * now, quota-wise (issue #1454) — the question a mailing asks before a
     * batch, so that a lane with nothing left SUSPENDS the mailing instead
     * of pouring the rest of it into the deferred queue.
     *
     * **The chain's own reading, not a second opinion.** Same entries, same
     * counters, and on this lane the same reserve off the top
     * (`MailTransportChain::hasSpentItsQuota()`): a lane this calls full is
     * one the chain would answer with `LaneExhaustedException`. A provider
     * with no known quota always has room, and so does a lane with no chain
     * laid down — the chain sends that through the configured relay.
     *
     * **True whenever the answer cannot be read**, which is the posture of
     * every reading in this namespace: a cadence that cannot tell must not
     * be the reason a mailing stops. The worst that costs is one message
     * deferred, after which the mailing stops on that signal instead.
     */
    public function hasRoomLeft(): bool
    {
        try {
            $entries = $this->chains->forLane(MailLane::Bulk);
            $providers = $this->directory->all();
            $usedToday = $this->counters->totalsForDay();
        } catch (\Throwable) {
            return true;
        }

        if ($entries === []) {
            return true;
        }

        foreach ($entries as $entry) {
            $provider = $entry->enabled ? ($providers[$entry->providerId] ?? null) : null;
            if ($provider === null || !$provider->isUsable()) {
                continue;
            }

            if ($provider->dailyQuota === null) {
                return true;
            }

            try {
                $ceiling = $provider->dailyQuota - ($this->reserve?->forProvider($provider)->messages ?? 0);
            } catch (\Throwable) {
                // The chain's posture: a reserve that cannot be computed
                // is not a reason to stop sending.
                $ceiling = $provider->dailyQuota;
            }

            if (($usedToday[$provider->id] ?? 0) < max(0, $ceiling)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider the bulk lane would use for its next message, or the
     * local send when nothing else is available.
     */
    public function activeProvider(): MailProvider
    {
        try {
            $entries = $this->chains->forLane(MailLane::Bulk);
            $providers = $this->directory->all();
            $usedToday = $this->counters->totalsForDay();
        } catch (\Throwable) {
            return $this->directory->local();
        }

        $firstEnabled = null;
        foreach ($entries as $entry) {
            if (!$entry->enabled) {
                continue;
            }

            $provider = $providers[$entry->providerId] ?? null;
            if ($provider === null || !$provider->isUsable()) {
                continue;
            }

            $firstEnabled ??= $provider;

            if ($provider->dailyQuota !== null && ($usedToday[$provider->id] ?? 0) >= $provider->dailyQuota) {
                continue;
            }

            return $provider;
        }

        // Every enabled entry has spent its quota: the lane is exhausted
        // for today and nothing will leave until tomorrow. Answering with
        // the first of them keeps the pacing sane rather than inventing
        // one — what happens to the messages themselves is D9's business,
        // not the cadence's.
        return $firstEnabled ?? $this->directory->local();
    }

    /**
     * @return array{batch_size: int, interval_minutes: int}
     */
    public function current(): array
    {
        $provider = $this->activeProvider();

        return [
            'batch_size' => $provider->batchSize,
            'interval_minutes' => $provider->batchIntervalMinutes,
        ];
    }
}
