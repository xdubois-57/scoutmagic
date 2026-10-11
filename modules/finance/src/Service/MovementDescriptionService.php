<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * The human description and counterparty of a page of movements, resolved
 * in one pass (issue #838) — wherever the site shows a movement: the
 * movements list, its search, its XLSX export, a receipt's « Mouvements
 * liés ». One source of truth: MovementPresenter composes, this service
 * gathers what it composes from, for every movement at once.
 *
 * **Nothing here is stored.** The description is a projection of today's
 * facts — what each movement pays, its first receipt, its counterparty,
 * its comment, its category — so a late reconciliation or a corrected
 * split shows at once.
 *
 * One query per kind of fact, never one per movement: receipts,
 * categories, allocations, receivables, debtor names, and — only for the
 * accounts that need it — the counterparty history. A receivable's source
 * is named by its module's describer, which the Api\ contract asks one
 * instance at a time: once per distinct campaign or booking, then.
 */
final class MovementDescriptionService
{
    /**
     * How far back an account's own history is searched for the name that
     * goes with a counterparty IBAN, when the bank gave the IBAN alone —
     * Payconiq does this on every payment.
     */
    public const COUNTERPARTY_HISTORY_MONTHS = 18;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $today;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $today
     */
    public function __construct(
        private FirstReceiptResolver $firstReceiptResolver,
        private CategoryRepository $categoryRepository,
        private ReceivableAllocationRepository $allocationRepository,
        private ExpectedReceivableRepository $receivableRepository,
        private ReceivablesOverviewService $receivableLabels,
        private TransactionRepository $transactionRepository,
        ?\Closure $today = null
    ) {
        $this->today = $today ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('today');
    }

    /**
     * @param Transaction[] $movements
     * @param array<int, string> $accountNamesById the fallback counterparty
     * @return array<int, array{counterparty: string, description: string}> movement id => what to show
     */
    public function present(array $movements, array $accountNamesById): array
    {
        if ($movements === []) {
            return [];
        }

        $ids = array_map(static fn(Transaction $movement): int => $movement->id, $movements);
        $firstReceipts = $this->firstReceiptResolver->resolve($ids);
        $categoryNames = [];
        foreach ($this->categoryRepository->findAllOrdered() as $category) {
            $categoryNames[$category->id] = $category->name;
        }
        $paid = $this->whatEachPays($ids);
        $inferredNames = $this->inferCounterpartyNames($movements);

        $presented = [];
        foreach ($movements as $movement) {
            $firstReceipt = $firstReceipts[$movement->id] ?? null;
            $inferred = $inferredNames[$movement->id] ?? null;
            $category = $movement->categoryId !== null ? ($categoryNames[$movement->categoryId] ?? null) : null;
            $presented[$movement->id] = [
                'counterparty' => MovementPresenter::counterparty(
                    $movement,
                    $firstReceipt,
                    $accountNamesById[$movement->accountId] ?? '',
                    $inferred
                ),
                'description' => MovementPresenter::description($movement, $firstReceipt, [
                    'receivable_label' => $paid[$movement->id]['label'] ?? null,
                    'other_receivables' => $paid[$movement->id]['others'] ?? 0,
                    // Blank counts as missing here too, as in counterparty().
                    'counterparty' => trim((string) $movement->counterpartyName) !== ''
                        ? $movement->counterpartyName
                        : $inferred,
                    'category' => $category,
                ]),
            ];
        }

        return $presented;
    }

    /**
     * For each movement that pays something: the receivable taking the
     * largest share (the lowest id on a tie, so it never flips), named by
     * its source module, and how many others it pays.
     *
     * @param int[] $movementIds
     * @return array<int, array{label: string, others: int}>
     */
    private function whatEachPays(array $movementIds): array
    {
        $byMovement = $this->allocationRepository->findByTransactionIds($movementIds);
        if ($byMovement === []) {
            return [];
        }

        $shares = [];
        foreach ($byMovement as $movementId => $allocations) {
            foreach ($allocations as $allocation) {
                // 0 is a removal kept so the next import does not redo it; a
                // refund is negative and still names what it refunds.
                if ($allocation->amountCents === 0) {
                    continue;
                }
                $shares[$movementId][$allocation->receivableId]
                    = ($shares[$movementId][$allocation->receivableId] ?? 0) + abs($allocation->amountCents);
            }
        }

        $receivableIds = [];
        foreach ($shares as $byReceivable) {
            foreach (array_keys($byReceivable) as $receivableId) {
                $receivableIds[$receivableId] = true;
            }
        }
        $labels = $this->receivableLabels->labelsFor(
            $this->receivableRepository->findByIds(array_keys($receivableIds))
        );

        $paid = [];
        foreach ($shares as $movementId => $byReceivable) {
            ksort($byReceivable);
            $largest = (int) array_key_first($byReceivable);
            foreach ($byReceivable as $receivableId => $cents) {
                if ($cents > $byReceivable[$largest]) {
                    $largest = $receivableId;
                }
            }
            if (!isset($labels[$largest])) {
                continue;
            }
            $paid[$movementId] = ['label' => $labels[$largest], 'others' => count($byReceivable) - 1];
        }

        return $paid;
    }

    /**
     * The name this account's own last COUNTERPARTY_HISTORY_MONTHS attach
     * to a counterparty IBAN the bank gave without a name — the most
     * frequent one once spelling variants are folded together, and none
     * at all when two names tie: a guess between two people is worse than
     * no name.
     *
     * The counterparty columns are encrypted, so the history is read and
     * decrypted per ACCOUNT, once, and only for an account with a movement
     * on this page that needs it.
     *
     * @param Transaction[] $movements
     * @return array<int, string> movement id => inferred name
     */
    private function inferCounterpartyNames(array $movements): array
    {
        $needing = [];
        foreach ($movements as $movement) {
            if (
                ($movement->counterpartyName === null || trim($movement->counterpartyName) === '')
                && self::iban($movement->counterpartyAccount) !== ''
            ) {
                $needing[$movement->accountId][] = $movement;
            }
        }
        if ($needing === []) {
            return [];
        }

        $since = ($this->today)()
            ->modify('-' . self::COUNTERPARTY_HISTORY_MONTHS . ' months')
            ->format('Y-m-d');

        $inferred = [];
        foreach ($needing as $accountId => $accountMovements) {
            $namesByIban = self::namesByIban($this->transactionRepository->findByAccountAfterDate($accountId, $since));
            foreach ($accountMovements as $movement) {
                $name = $namesByIban[self::iban($movement->counterpartyAccount)] ?? null;
                if ($name !== null) {
                    $inferred[$movement->id] = $name;
                }
            }
        }

        return $inferred;
    }

    /**
     * @param Transaction[] $history
     * @return array<string, string> IBAN => the one dominant name
     */
    private static function namesByIban(array $history): array
    {
        /** @var array<string, array<string, array{count: int, display: array<string, int>}>> $tally */
        $tally = [];
        foreach ($history as $movement) {
            $iban = self::iban($movement->counterpartyAccount);
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $movement->counterpartyName));
            if ($iban === '' || $name === '') {
                continue;
            }
            $key = mb_strtoupper($name);
            $tally[$iban][$key]['count'] = ($tally[$iban][$key]['count'] ?? 0) + 1;
            $tally[$iban][$key]['display'][$name] = ($tally[$iban][$key]['display'][$name] ?? 0) + 1;
        }

        $dominant = [];
        foreach ($tally as $iban => $names) {
            uasort($names, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
            $ranked = array_values($names);
            if (isset($ranked[1]) && $ranked[1]['count'] === $ranked[0]['count']) {
                continue;
            }
            $display = $ranked[0]['display'];
            arsort($display);
            $dominant[$iban] = (string) array_key_first($display);
        }

        return $dominant;
    }

    private static function iban(?string $account): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', (string) $account));
    }
}
