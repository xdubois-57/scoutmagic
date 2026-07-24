<?php

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

    public function createReceivable(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountCents,
        string $communication,
        ?string $label
    ): int {
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
        $digitsOnlyCommunication = $this->digitsOnly($receivable->communication);
        $total = 0;

        foreach ($this->transactionRepository->findByAccountId($receivable->accountId) as $transaction) {
            if ($transaction->amount <= 0) {
                continue; // only credits (money coming in) can settle a receivable
            }

            $haystack = $this->digitsOnly($transaction->label . ' ' . ($transaction->comment ?? '') . ' ' . ($transaction->extraDetails ?? ''));
            if (str_contains($haystack, $digitsOnlyCommunication)) {
                $total += (int) round($transaction->amount * 100);
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
