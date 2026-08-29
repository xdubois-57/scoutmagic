<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;

/**
 * The cross-module face of "money we expect to receive"
 * (Api\ExpectedReceivableInterface): register one, change its amount,
 * ask where it stands, drop the ones a deleted source owned.
 *
 * **Status is the sum of a receivable's allocations, plus its abandon.**
 * It used to be refabricated on every display instead, by summing the
 * whole amount of every credit whose text carried the communication —
 * nothing written down, so nothing correctable: a transfer covering three
 * siblings could not be split, a credit nobody's communication matched
 * could not be attached by hand, and a treasurer who knew better had no
 * way to say so. Service\ReceivableAllocationService owns the writing and
 * the arithmetic; this class is the door consuming modules already use.
 *
 * Allocations are written at bank import, and again here whenever a
 * receivable appears or its amount moves — a rental deposit is routinely
 * paid before the receivable that expects it exists, and its status has
 * to be right the first time it is asked for, not at the next import.
 */
class ExpectedReceivableService implements ExpectedReceivableInterface
{
    public function __construct(
        private ExpectedReceivableRepository $repository,
        private ReceivableAllocationService $allocations
    ) {
    }

    /**
     * @throws FinanceException when $communication carries no digit — a
     *         payment is matched on the twelve digits of a structured
     *         communication, so such a receivable could never be settled
     *         by anything, and an empty needle once made every credit on
     *         the account look like a payment for it. This is a public
     *         cross-module API (ARCHITECTURE.md §7.5), so the caller is
     *         not assumed to have gone through
     *         Service\StructuredCommunicationService::generate().
     */
    public function createReceivable(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountCents,
        string $communication,
        ?string $label,
        ?int $memberId = null
    ): int {
        if ($this->digitsOnly($communication) === '') {
            throw new FinanceException('La communication doit contenir au moins un chiffre.');
        }

        $id = $this->repository->create($sourceModule, $sourceReferenceId, $accountId, $amountCents, $communication, $label, $memberId);

        // The payment can predate the receivable — a rental's security
        // deposit routinely arrives before the booking is confirmed — and
        // the status has to be right the first time somebody asks, not at
        // the next bank import.
        $receivable = $this->repository->findById($id);
        if ($receivable !== null) {
            $this->allocations->reconcileReceivable($receivable);
        }

        return $id;
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

        // What ARRIVED for this receivable, not what it was able to
        // absorb. Those two parted company the day allocations started
        // being capped at the amount due: a receivable of 467,50 € that
        // has seen 500 € come in has allocated 467,50 €, and comparing
        // against that would wave through a lowering to 480 € as if
        // nothing had been paid past it.
        $received = $this->allocations->refreshAndSettle([$receivable])[$receivable->id]->amountDesignatedCents;

        if (!$allowBelowReceived && $amountCents < $received) {
            throw new FinanceException(sprintf(
                'Le nouveau montant (%s €) est inférieur à ce qui a déjà été reçu (%s €). '
                . 'Cela créerait un trop-perçu à rembourser : confirmez explicitement si c\'est voulu.',
                number_format($amountCents / 100, 2, ',', ' '),
                number_format($received / 100, 2, ',', ' ')
            ));
        }

        $this->repository->updateAmount($receivableId, $amountCents);

        // Raising the amount frees room a credit could not be allocated
        // into; lowering it takes room away. Either way the automatic
        // allocations have to be revised, and the surplus a lowering
        // creates has to become visible as a trop-perçu rather than stay
        // buried in a receivable reading "paid" for more than it is worth.
        $updated = $this->repository->findById($receivableId);
        if ($updated !== null) {
            $this->allocations->reconcileReceivable($updated);
        }
    }

    /**
     * @return array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'|'waived'}
     */
    public function getReceivableStatus(int $receivableId): array
    {
        $receivable = $this->repository->findById($receivableId);
        if ($receivable === null) {
            return ['amount_due' => 0, 'amount_received' => 0, 'status' => 'unpaid'];
        }

        return $this->allocations->refreshAndSettle([$receivable])[$receivable->id]->toApiArray();
    }

    public function deleteReceivablesForSource(string $sourceModule, int $sourceReferenceId): void
    {
        $this->repository->deleteBySource($sourceModule, $sourceReferenceId);
    }

    /**
     * Statuses for many receivables at once, keyed by receivable id — the
     * reconciliation page (Service\ReceivablesOverviewService) needs one
     * per row, and asking one at a time re-read AND re-decrypted every
     * movement on the account once per receivable.
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'|'waived'}>
     */
    public function getReceivableStatuses(array $receivables): array
    {
        $statuses = [];
        foreach ($this->allocations->refreshAndSettle($receivables) as $receivableId => $settlement) {
            $statuses[$receivableId] = $settlement->toApiArray();
        }

        return $statuses;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
