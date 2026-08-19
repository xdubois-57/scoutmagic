<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Status is never stored — always computed live by matching imported bank
 * transactions (on the receivable's own account) whose free text contains
 * the receivable's structured communication. A single receivable can be
 * settled across several transactions (module spec: "un paiement peut
 * être effectué en plusieurs versements"), so the matched amounts are
 * summed rather than expecting a single exact match.
 */
class ExpectedReceivableService implements ExpectedReceivableInterface
{
    public function __construct(
        private ExpectedReceivableRepository $repository,
        private TransactionRepository $transactionRepository
    ) {
    }

    /**
     * @throws FinanceException when $communication carries no digit — the
     *         status computation matches on its digits alone, so such a
     *         receivable could never be settled by any transaction, and
     *         (before sumMatchingCredits() guarded it) an empty needle made
     *         every credit on the account look like a payment for it. This
     *         is a public cross-module API (ARCHITECTURE.md §7.5), so the
     *         caller is not assumed to have gone through
     *         Service\StructuredCommunicationService::generate().
     */
    public function createReceivable(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountCents,
        string $communication,
        ?string $label
    ): int {
        if ($this->digitsOnly($communication) === '') {
            throw new FinanceException('La communication doit contenir au moins un chiffre.');
        }

        return $this->repository->create($sourceModule, $sourceReferenceId, $accountId, $amountCents, $communication, $label);
    }

    /**
     * @return array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'}
     */
    public function getReceivableStatus(int $receivableId): array
    {
        $receivable = $this->repository->findById($receivableId);
        if ($receivable === null) {
            return ['amount_due' => 0, 'amount_received' => 0, 'status' => 'unpaid'];
        }

        $amountReceived = $this->computeAmountReceivedCents($receivable);

        return [
            'amount_due' => $receivable->amountDueCents,
            'amount_received' => $amountReceived,
            'status' => $this->statusFor($receivable->amountDueCents, $amountReceived),
        ];
    }

    public function deleteReceivablesForSource(string $sourceModule, int $sourceReferenceId): void
    {
        $this->repository->deleteBySource($sourceModule, $sourceReferenceId);
    }

    private function computeAmountReceivedCents(ExpectedReceivable $receivable): int
    {
        return $this->sumMatchingCredits(
            $receivable,
            $this->transactionRepository->findByAccountId($receivable->accountId)
        );
    }

    /**
     * Statuses for many receivables at once, keyed by receivable id — the
     * reconciliation page (Service\ReceivablesOverviewService) needs one
     * per row, and calling getReceivableStatus() in a loop re-read AND
     * re-decrypted every movement on the account once per receivable.
     * Movements are loaded once per distinct account instead.
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'}>
     */
    public function getReceivableStatuses(array $receivables): array
    {
        $transactionsByAccountId = [];
        foreach ($receivables as $receivable) {
            if (!isset($transactionsByAccountId[$receivable->accountId])) {
                $transactionsByAccountId[$receivable->accountId] =
                    $this->transactionRepository->findByAccountId($receivable->accountId);
            }
        }

        $statuses = [];
        foreach ($receivables as $receivable) {
            $amountReceived = $this->sumMatchingCredits(
                $receivable,
                $transactionsByAccountId[$receivable->accountId] ?? []
            );
            $statuses[$receivable->id] = [
                'amount_due' => $receivable->amountDueCents,
                'amount_received' => $amountReceived,
                'status' => $this->statusFor($receivable->amountDueCents, $amountReceived),
            ];
        }

        return $statuses;
    }

    /**
     * Credits on the account whose free text carries the receivable's
     * communication, summed (a receivable can be settled across several
     * transfers).
     *
     * Two things this deliberately does NOT do. It never matches on an
     * empty needle: a communication with no digits at all reduces to '',
     * and str_contains($haystack, '') is always true — which used to count
     * *every* credit on the account as settling the receivable and report
     * it "paid". createReceivable() now rejects such a communication, and
     * this is the second line of defence for rows written before it did.
     * And it matches each field separately rather than concatenating them:
     * stripping the separators from "label + comment + extra" glued the
     * digits of adjacent fields together, so a communication could be
     * "found" straddling a boundary where it appears in neither field.
     *
     * @param \Modules\Finance\Repository\Transaction[] $transactions
     */
    private function sumMatchingCredits(ExpectedReceivable $receivable, array $transactions): int
    {
        $needle = $this->digitsOnly($receivable->communication);
        if ($needle === '') {
            return 0;
        }

        $total = 0;
        foreach ($transactions as $transaction) {
            if ($transaction->amount <= 0) {
                continue; // only credits (money coming in) can settle a receivable
            }

            foreach ([$transaction->label, $transaction->comment, $transaction->extraDetails] as $field) {
                if ($field !== null && str_contains($this->digitsOnly($field), $needle)) {
                    $total += (int) round($transaction->amount * 100);
                    break;
                }
            }
        }

        return $total;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @return 'paid'|'partial'|'unpaid'
     */
    private function statusFor(int $amountDueCents, int $amountReceivedCents): string
    {
        if ($amountReceivedCents <= 0) {
            return 'unpaid';
        }
        if ($amountReceivedCents >= $amountDueCents) {
            return 'paid';
        }
        return 'partial';
    }
}
