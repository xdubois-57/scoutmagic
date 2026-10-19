<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\ReceivableStanding;
use Modules\Finance\Api\RefundCandidate;
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
        private ReceivableAllocationService $allocations,
        private \Modules\Finance\Repository\CategoryRepository $categories
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
        int $categoryId,
        ?int $memberId = null
    ): int {
        // Every receivable says where its payments are booked (issue #838):
        // a movement allocated to it takes this category, before any rule
        // or the AI is asked. An active category only — an archived one
        // would put new money where the treasurer chose to stop putting it.
        $category = $this->categories->findById($categoryId);
        if ($category === null || !$category->isActive) {
            throw new FinanceException(
                'Choisissez une catégorie Finances active pour cette créance.'
            );
        }

        // Every receivable carries a valid Belgian structured
        // communication (issue #837). Automatic matching only ever reads
        // those twelve digits, and « Paiements à traiter » counts a credit
        // carrying a valid one that matches nothing as a decision waiting
        // for the treasurer — which only means something if no receivable
        // can be raised under anything looser. Stored in one canonical
        // spelling, whichever the caller used.
        if (!StructuredCommunicationService::isValid($communication)) {
            throw new FinanceException(
                'La communication doit être une communication structurée valide (+++123/4567/89002+++).'
            );
        }

        $id = $this->repository->create(
            $sourceModule,
            $sourceReferenceId,
            $accountId,
            $amountCents,
            StructuredCommunicationService::format(substr($this->digitsOnly($communication), 0, 10)),
            $label,
            $memberId,
            $category->id
        );

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
        // nothing had been paid past it. Less what has already gone back
        // (chantier #927): a refunded surplus is no longer money held, so
        // an amount raised back over what was kept owes nobody anything.
        $settlement = $this->allocations->refreshAndSettle([$receivable])[$receivable->id];
        $received = $settlement->amountDesignatedCents - $settlement->amountRefundedCents;

        if (!$allowBelowReceived && $amountCents < $received) {
            throw new FinanceException(sprintf(
                'Le nouveau montant (%s €) est inférieur à ce qui a déjà été reçu (%s €%s). '
                . 'Cela créerait un trop-perçu à rembourser : confirmez explicitement si c\'est voulu.',
                number_format($amountCents / 100, 2, ',', ' '),
                number_format($received / 100, 2, ',', ' '),
                $settlement->amountRefundedCents > 0 ? ', remboursements déduits' : ''
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

    public function getReceivableStanding(string $sourceModule, int $receivableId): ?ReceivableStanding
    {
        $receivable = $this->ownedBy($sourceModule, $receivableId);
        if ($receivable === null) {
            return null;
        }

        $settlement = $this->allocations->refreshAndSettle([$receivable])[$receivable->id];

        // What the payer is still owed or still owes is read against what
        // they have NET of the refunds: a refund given, then the amount
        // raised again, is money expected once more — never « paid »
        // because the credit's share alone covers the new amount.
        $netReceived = $settlement->amountDesignatedCents - $settlement->amountRefundedCents;
        $status = match (true) {
            $settlement->isWaived() => ReceivableSettlement::STATUS_WAIVED,
            $netReceived <= 0 => ReceivableSettlement::STATUS_UNPAID,
            $netReceived >= $settlement->amountDueCents => ReceivableSettlement::STATUS_PAID,
            default => ReceivableSettlement::STATUS_PARTIAL,
        };

        return new ReceivableStanding(
            amountDueCents: $settlement->amountDueCents,
            amountReceivedCents: $settlement->amountDesignatedCents,
            amountRefundedCents: $settlement->amountRefundedCents,
            amountOverpaidCents: $settlement->amountOverpaidCents,
            amountRemainingCents: $settlement->isWaived() ? 0 : max(0, $settlement->amountDueCents - $netReceived),
            status: $status
        );
    }

    public function getReceivableMovements(string $sourceModule, int $receivableId): array
    {
        $receivable = $this->ownedBy($sourceModule, $receivableId);

        return $receivable !== null ? $this->allocations->movementsFor($receivable) : [];
    }

    public function findRefundCandidates(string $sourceModule, int $receivableId, \DateTimeImmutable $since): array
    {
        $receivable = $this->ownedBy($sourceModule, $receivableId);
        if ($receivable === null) {
            return [];
        }

        $candidates = [];
        foreach ($this->allocations->refundCandidatesFor($receivable, $since->format('Y-m-d')) as $debit) {
            $candidates[] = new RefundCandidate(
                transactionId: $debit->id,
                date: $debit->transactionDate,
                amountCents: (int) round(-$debit->amount * 100),
                counterpartyName: $debit->counterpartyName
            );
        }

        return $candidates;
    }

    public function reconcileRefund(
        string $sourceModule,
        int $receivableId,
        int $transactionId,
        \DateTimeImmutable $since,
        ?int $actorUserAccountId
    ): void {
        $receivable = $this->ownedBy($sourceModule, $receivableId);
        if ($receivable === null) {
            throw new FinanceException("Cette créance n'existe pas.");
        }

        $this->allocations->refundFromCandidate(
            $receivable,
            $transactionId,
            $since->format('Y-m-d'),
            $actorUserAccountId
        );
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

    /**
     * The receivable, when $sourceModule is the module that raised it —
     * and null otherwise, exactly as for an id that does not exist, so a
     * module asking about somebody else's receivable learns nothing, not
     * even that it exists.
     */
    private function ownedBy(string $sourceModule, int $receivableId): ?ExpectedReceivable
    {
        $receivable = $this->repository->findById($receivableId);

        return $receivable !== null && $receivable->sourceModule === $sourceModule ? $receivable : null;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
