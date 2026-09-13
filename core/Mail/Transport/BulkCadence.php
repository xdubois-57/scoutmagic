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
        private SendCounterRepository $counters
    ) {
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
