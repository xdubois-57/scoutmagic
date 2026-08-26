<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Balance-at-date computation — starts from the closest known checkpoint
 * at or before the requested date (schema.sql's comment on
 * finance_balance_checkpoints explains why: summing every transaction
 * since account creation every time would get slower and slower as an
 * account ages) and adds every transaction strictly after that
 * checkpoint's date, up to and including the requested date. A
 * checkpoint's own balance is assumed to already reflect everything up
 * to and including its own checkpoint_date (typically the bank's own
 * reported closing balance for that day).
 */
class BalanceService
{
    public function __construct(
        private BalanceCheckpointRepository $checkpointRepository,
        private TransactionRepository $transactionRepository
    ) {
    }

    /**
     * Null when the account has no balance checkpoint at or before $date
     * — there is no known reference point to compute from.
     */
    public function getBalanceAt(Account $account, \DateTimeInterface $date): ?float
    {
        $dateStr = $date->format('Y-m-d');
        $checkpoint = $this->checkpointRepository->findClosestBefore($account->id, $dateStr);
        if ($checkpoint === null) {
            return null;
        }

        $balance = $checkpoint->balance;
        foreach ($this->transactionRepository->findByAccountAfterDate($account->id, $checkpoint->checkpointDate) as $transaction) {
            if ($transaction->transactionDate > $dateStr) {
                continue;
            }
            $balance += $transaction->amount;
        }

        return $balance;
    }

    /**
     * The balance at each of several dates, in one pass.
     *
     * getBalanceAt() answers one date and costs two queries plus a full
     * read of the account's history since its checkpoint. The dashboard's
     * evolution chart asks twelve times, which was twenty-four queries
     * and twelve reads of the same rows — the single slowest thing on
     * that page.
     *
     * **Same arithmetic, same answer, per date.** Each date still starts
     * from the checkpoint closest at or before IT, not from one shared
     * anchor: a checkpoint is an authoritative balance that re-anchors
     * the running total, so a mid-year one must keep correcting the
     * months after it exactly as it did before. All that changes is that
     * the checkpoints and the movements are each read once and the sums
     * come from a running total rather than from a fresh scan per date.
     *
     * @param \DateTimeInterface[] $dates
     * @return array<string, ?float> keyed by Y-m-d, null for a date with
     *         no checkpoint at or before it — the same "no known
     *         reference point" answer getBalanceAt() gives
     */
    public function getBalancesAt(Account $account, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $checkpoints = $this->checkpointRepository->findByAccountId($account->id);
        $wanted = [];
        foreach ($dates as $date) {
            $wanted[$date->format('Y-m-d')] = null;
        }

        if ($checkpoints === []) {
            return $wanted;
        }

        // Cumulative movement total up to and including each date that
        // matters — the month ends asked for, and every checkpoint date,
        // since a balance is (checkpoint + everything strictly after it).
        $milestones = array_keys($wanted);
        foreach ($checkpoints as $checkpoint) {
            $milestones[] = $checkpoint->checkpointDate;
        }
        $milestones = array_values(array_unique($milestones));
        sort($milestones);

        $earliest = $checkpoints[0]->checkpointDate;
        $runningTotal = 0.0;
        $cumulativeUpTo = [];
        $movements = $this->transactionRepository->findByAccountAfterDate($account->id, $earliest);
        $index = 0;
        $count = count($movements);
        foreach ($milestones as $milestone) {
            while ($index < $count && $movements[$index]->transactionDate <= $milestone) {
                $runningTotal += $movements[$index]->amount;
                $index++;
            }
            $cumulativeUpTo[$milestone] = $runningTotal;
        }

        foreach ($wanted as $date => $_) {
            $anchor = null;
            foreach ($checkpoints as $checkpoint) {
                if ($checkpoint->checkpointDate <= $date) {
                    $anchor = $checkpoint;
                    continue;
                }
                break;
            }
            if ($anchor === null) {
                continue;
            }

            // Movements strictly after the checkpoint, up to and
            // including the date — getBalanceAt()'s own window.
            $wanted[$date] = $anchor->balance
                + $cumulativeUpTo[$date]
                - $cumulativeUpTo[$anchor->checkpointDate];
        }

        return $wanted;
    }

    /**
     * Lowest balance reached at any point from $since to today — walks
     * forward from a starting balance, transaction by transaction,
     * tracking the running minimum (balance only ever changes at a
     * transaction's date, so checking after each one is enough to find
     * every local minimum). When there is no checkpoint at or before
     * $since, this falls back to the account's very earliest checkpoint
     * instead of giving up — covering as much history as is actually on
     * record rather than reporting the whole thing unknown just because
     * it doesn't reach all the way back to $since. Null only when the
     * account has no checkpoint at all — same "no known reference point"
     * convention as getBalanceAt().
     */
    public function getLowestBalanceSince(Account $account, \DateTimeInterface $since): ?float
    {
        $balance = $this->getBalanceAt($account, $since);
        $anchorDate = $since->format('Y-m-d');

        if ($balance === null) {
            $earliest = $this->checkpointRepository->findEarliestForAccount($account->id);
            if ($earliest === null) {
                return null;
            }
            $balance = $earliest->balance;
            $anchorDate = $earliest->checkpointDate;
        }

        $lowest = $balance;
        foreach ($this->transactionRepository->findByAccountAfterDate($account->id, $anchorDate) as $transaction) {
            $balance += $transaction->amount;
            $lowest = min($lowest, $balance);
        }

        return $lowest;
    }
}
