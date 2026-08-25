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
 * transactions (on the receivable's own account) whose free text spells
 * out the receivable's structured communication. A single receivable can
 * be settled across several transactions (module spec: "un paiement peut
 * être effectué en plusieurs versements"), so the matched amounts are
 * summed rather than expecting a single exact match.
 *
 * "Spells out" is exact: the communications a line carries are extracted
 * and compared by equality — see sumMatchingCredits() for why a substring
 * search was not good enough.
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
     * @throws FinanceException when the receivable does not exist, when
     *         $amountCents is negative, or when lowering it below what has
     *         already come in without $allowBelowReceived.
     */
    public function updateReceivableAmount(
        int $receivableId,
        int $amountCents,
        bool $allowBelowReceived = false
    ): void {
        if ($amountCents < 0) {
            throw new FinanceException('Un montant attendu ne peut pas être négatif.');
        }

        $receivable = $this->repository->findById($receivableId);
        if ($receivable === null) {
            throw new FinanceException("Cette créance n'existe pas.");
        }

        // Computed live from the matched transfers, like every other status
        // here: what has come in is a fact about the bank statement, never
        // a stored number that could be stale at exactly this moment.
        $received = $this->computeAmountReceivedCents($receivable);

        if (!$allowBelowReceived && $amountCents < $received) {
            throw new FinanceException(sprintf(
                'Le nouveau montant (%s €) est inférieur à ce qui a déjà été reçu (%s €). '
                . 'Cela créerait un trop-perçu à rembourser : confirmez explicitement si c\'est voulu.',
                number_format($amountCents / 100, 2, ',', ' '),
                number_format($received / 100, 2, ',', ' ')
            ));
        }

        $this->repository->updateAmount($receivableId, $amountCents);
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
     * **The comparison is an equality, never an inclusion.** Each field is
     * scanned for the communications it actually spells out
     * (Service\StructuredCommunicationService::extract(), which is where
     * the shapes a bank prints are decided) and the receivable's own
     * twelve digits must be one of them. What that replaced was
     * str_contains(digitsOnly($field), digitsOnly($communication)): a
     * substring search over a field flattened to nothing but digits, which
     * both invented sequences that were in the text nowhere — "12/03/2026
     * 45678 9012" collapses to "12032026456789012" — and accepted a
     * communication found inside a longer account number. While the status
     * was recomputed on every display, a fortuitous match only made a page
     * lie; the moment an allocation is written from it, it marks somebody
     * else's receivable paid and the mistake outlives the page.
     *
     * Two properties are kept from before. Fields are matched one at a
     * time, never concatenated, so a communication cannot be assembled
     * across a field boundary. And a communication that carries no digit
     * matches nothing: it reduces to the empty string, which used to be
     * found in every credit on the account — createReceivable() refuses
     * one now, and this is the second line of defence for rows written
     * before it did. A communication that is not exactly twelve digits is
     * in the same position: nothing extract() returns can equal it, so it
     * stays unpaid rather than matching something approximately.
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
                if ($field !== null && in_array($needle, StructuredCommunicationService::extract($field), true)) {
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
