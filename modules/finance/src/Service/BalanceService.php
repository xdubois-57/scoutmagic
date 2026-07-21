<?php

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
