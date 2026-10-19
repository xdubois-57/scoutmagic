<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\ReceivableMovement;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Who paid what, written down.
 *
 * Before this service a receivable's status was refabricated on every
 * display — the whole amount of every credit whose text carried its
 * communication, summed. Nothing was recorded, so nothing could be
 * corrected: a single transfer covering three siblings could not be
 * split, a credit no communication matched could not be attached, and a
 * treasurer who knew better than the machine had no way to say so.
 *
 * Three rules make the result trustworthy, and each one exists because
 * its absence produces a specific, quiet lie:
 *
 * 1. **Never allocate beyond what is still due.** A receivable already
 *    covered absorbs nothing more; one half paid absorbs exactly the
 *    balance. That single rule handles paying too much, paying in
 *    instalments that overshoot, and paying twice a month apart — not
 *    three cases, one. The surplus stays unallocated rather than being
 *    hidden inside a receivable that reads "paid" for more than it was
 *    ever worth.
 * 2. **Re-importing a statement doubles nothing.** finance_transactions
 *    already refuses a line it has seen (its unique account +
 *    bank_reference index), so the second import inserts no movement to
 *    allocate; and the unique (transaction, receivable) pair makes this
 *    pass idempotent even when it runs twice over the same movements.
 * 3. **The automatic pass never touches a human's row.** Provenance is
 *    what guarantees it — the same mechanism, for the same reason, as
 *    finance_transactions.category_source. Removing an automatic
 *    allocation therefore writes a zero-amount MANUAL row rather than
 *    deleting: a deletion leaves no trace, and the next import would put
 *    the allocation straight back.
 *
 * An allocation never joins two accounts. Money paid onto the wrong
 * account is reported on both sides and transferred for real; imputing it
 * at a distance would mark a receivable settled while the account
 * concerned received nothing, and that section's books would say
 * something untrue.
 */
class ReceivableAllocationService
{
    public function __construct(
        private ExpectedReceivableRepository $receivableRepository,
        private ReceivableAllocationRepository $allocationRepository,
        private TransactionRepository $transactionRepository,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility
    ) {
    }

    // ── the automatic pass ──────────────────────────────────────────────

    /**
     * Matches every credit on an account against every receivable booked
     * against it, and writes what it finds.
     *
     * Called after a bank import, when a receivable is created (its
     * payment may have arrived before it did — a rental deposit routinely
     * does), when a receivable's amount changes, and nightly by
     * Task\ReconcileReceivablesHandler for anything the other three
     * missed.
     *
     * @return int allocations created or revised — 0 means "nothing had
     *             changed", which is the normal answer on a re-run
     */
    public function reconcileAccount(int $accountId): int
    {
        $receivables = $this->receivableRepository->findByAccountId($accountId);
        if ($receivables === []) {
            return 0;
        }

        return $this->reconcile($receivables, $this->transactionRepository->findByAccountId($accountId));
    }

    /**
     * The same pass narrowed to one receivable — what createReceivable()
     * calls, so a status is right the instant it is asked for rather than
     * at the next import.
     */
    public function reconcileReceivable(ExpectedReceivable $receivable): int
    {
        return $this->reconcile([$receivable], $this->transactionRepository->findByAccountId($receivable->accountId));
    }

    /**
     * The pass narrowed to a set of receivables, grouped by account so
     * each account's movements are read once.
     *
     * @param ExpectedReceivable[] $receivables
     */
    public function reconcileReceivables(array $receivables): int
    {
        $byAccount = [];
        foreach ($receivables as $receivable) {
            $byAccount[$receivable->accountId][] = $receivable;
        }

        $changed = 0;
        foreach ($byAccount as $accountId => $group) {
            $changed += $this->reconcile($group, $this->transactionRepository->findByAccountId($accountId));
        }

        return $changed;
    }

    /**
     * Bring the allocations up to date, then report where the receivables
     * stand — the shape every status read in the application goes through.
     *
     * Reconciling on a read looks like a write where none was asked for,
     * and it is deliberate. Before allocations existed, a status was
     * recomputed from scratch on *every* read: this pass costs the same
     * scan and, unlike that one, leaves something behind that a treasurer
     * can then correct. It writes only what changed, so the second read
     * writes nothing. Without it a status would be stale exactly when it
     * matters most — a receivable created after its payment arrived, an
     * import that failed halfway, an installation whose whole history
     * predates the allocation model.
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, ReceivableSettlement>
     */
    public function refreshAndSettle(array $receivables): array
    {
        $this->reconcileReceivables($receivables);

        return $this->settlementsFor($receivables);
    }

    /**
     * @param ExpectedReceivable[] $receivables all on the same account
     * @param Transaction[] $transactions every movement on that account
     */
    private function reconcile(array $receivables, array $transactions): int
    {
        if ($receivables === []) {
            return 0;
        }

        /** @var array<string, ExpectedReceivable[]> $byCommunication */
        $byCommunication = [];
        foreach ($receivables as $receivable) {
            $digits = self::digitsOnly($receivable->communication);
            if (strlen($digits) !== 12) {
                // Nothing extract() can produce equals it, so it could
                // never be matched — see ExpectedReceivableService.
                continue;
            }
            $byCommunication[$digits][] = $receivable;
        }

        if ($byCommunication === []) {
            return 0;
        }

        // The running state the whole pass reads and writes: what each
        // receivable has already absorbed, and what each movement has
        // already given away. Seeded from the database, then kept up to
        // date in memory so two movements matching the same receivable
        // in one pass cannot both fill it.
        $allocatedByReceivable = [];
        $existingByPair = [];
        // One query for the whole set, not one per receivable: this pass
        // runs on every status read (refreshAndSettle), so an account
        // with 200 receivables was issuing 200 queries to build a map
        // that findByReceivableIds() already returns in one — the same
        // batched call settlementsFor() below has always used.
        $allocationsByReceivable = $this->allocationRepository->findByReceivableIds(
            array_map(static fn(ExpectedReceivable $receivable): int => $receivable->id, $receivables)
        );
        foreach ($receivables as $receivable) {
            $allocatedByReceivable[$receivable->id] = 0;
            foreach ($allocationsByReceivable[$receivable->id] ?? [] as $allocation) {
                $existingByPair[$allocation->transactionId][$allocation->receivableId] = $allocation;
                if ($allocation->amountCents > 0) {
                    $allocatedByReceivable[$receivable->id] += $allocation->amountCents;
                }
            }
        }

        $transactionIds = array_map(static fn(Transaction $transaction): int => $transaction->id, $transactions);
        $usedByTransaction = array_fill_keys($transactionIds, 0);
        // What each debit already gives back, to any receivable — so a
        // refund never hands out more than the debit carries.
        $refundedByTransaction = array_fill_keys($transactionIds, 0);
        foreach ($this->allocationRepository->findByTransactionIds($transactionIds) as $transactionId => $allocations) {
            foreach ($allocations as $allocation) {
                if ($allocation->amountCents > 0) {
                    $usedByTransaction[$transactionId] += $allocation->amountCents;
                } elseif ($allocation->amountCents < 0) {
                    $refundedByTransaction[$transactionId] -= $allocation->amountCents;
                }
            }
        }

        $changed = 0;
        $touched = [];

        foreach ($transactions as $transaction) {
            if ($transaction->amount <= 0) {
                continue; // only money coming in can settle a receivable
            }

            foreach ($this->communicationsOf($transaction) as $communication) {
                foreach ($byCommunication[$communication] ?? [] as $receivable) {
                    $existing = $existingByPair[$transaction->id][$receivable->id] ?? null;

                    // A human has spoken about this exact pair — including
                    // by removing the allocation, which is why removal
                    // writes a zero-amount row instead of deleting.
                    if ($existing !== null && $existing->isManual()) {
                        continue;
                    }

                    $ownShare = $existing !== null ? max(0, $existing->amountCents) : 0;
                    $remainingDue = $receivable->amountDueCents - ($allocatedByReceivable[$receivable->id] - $ownShare);
                    $remainingOnTransaction = self::toCents(
                        $transaction->amount
                    ) - ($usedByTransaction[$transaction->id] - $ownShare);

                    $amount = max(0, min($remainingDue, $remainingOnTransaction));

                    if ($existing === null && $amount === 0) {
                        continue;
                    }

                    $allocationId = $existing?->id;
                    if ($allocationId === null) {
                        $allocationId = $this->allocationRepository->create(
                            $transaction->id,
                            $receivable->id,
                            $amount,
                            ReceivableAllocation::SOURCE_AUTO,
                            null
                        );
                        $changed++;
                        $touched[$transaction->id] = true;
                    } elseif ($existing->amountCents !== $amount) {
                        $this->allocationRepository->update(
                            $allocationId,
                            $amount,
                            ReceivableAllocation::SOURCE_AUTO,
                            null
                        );
                        $changed++;
                        $touched[$transaction->id] = true;
                    }

                    $allocatedByReceivable[$receivable->id] += $amount - $ownShare;
                    $usedByTransaction[$transaction->id] += $amount - $ownShare;
                    $existingByPair[$transaction->id][$receivable->id] = new ReceivableAllocation(
                        id: $allocationId,
                        transactionId: $transaction->id,
                        receivableId: $receivable->id,
                        amountCents: $amount,
                        source: ReceivableAllocation::SOURCE_AUTO,
                        createdBy: null,
                        createdAt: date('Y-m-d H:i:s')
                    );
                }
            }
        }

        $refunds = $this->reconcileRefunds(
            $byCommunication,
            $transactions,
            $existingByPair,
            $allocatedByReceivable,
            $usedByTransaction,
            $refundedByTransaction
        );
        foreach ($refunds as $transactionId => $count) {
            $changed += $count;
            $touched[$transactionId] = true;
        }

        $this->recategorize(array_keys($touched));

        return $changed;
    }

    /**
     * The second half of the automatic pass: a DEBIT whose text carries a
     * receivable's communication is the refund of that receivable's
     * surplus (chantier #927, §2.6) — the transfer a manager makes from
     * the bank, with the communication the payer used, to give back a
     * security deposit or an overpayment.
     *
     * **Never beyond the surplus.** What a debit gives back is capped at
     * what the receivable still owes back at that point — the money that
     * arrived designating it, less what it was worth, less what earlier
     * refunds already returned. The rest of the debit stays unreconciled:
     * a refund of 200 € against a surplus of 196 € is 196 € of refund and
     * 4 € of something a treasurer has to look at, never a receivable
     * reading "refunded" for more than it was owed. A debit that names a
     * receivable carrying no surplus at all writes nothing — that is
     * also why a debit never settles anything (rule 1 of this class).
     *
     * Runs after the credits so the surplus it reads is the one this very
     * pass just left. The unique pair makes a re-run (and a re-import)
     * write nothing, and a human's row on the pair is never touched, as
     * for the credits. One difference, deliberate: **an automatic refund
     * only ever grows** (a surplus that grows later, up to what the debit
     * carries), it is never reduced. A credit's allocation says how much
     * of it a receivable absorbs, which moves with the amount due; a
     * refund says the money went back to the payer, which a price
     * changing afterwards does not undo.
     *
     * @param array<string, ExpectedReceivable[]> $byCommunication
     * @param Transaction[] $transactions
     * @param array<int, array<int, ReceivableAllocation>> $existingByPair
     * @param array<int, int> $allocatedByReceivable
     * @param array<int, int> $usedByTransaction positive allocations per movement
     * @param array<int, int> $refundedByDebit what each debit already gives back, as a positive number
     * @return array<int, int> rows changed, per debit
     */
    private function reconcileRefunds(
        array $byCommunication,
        array $transactions,
        array $existingByPair,
        array $allocatedByReceivable,
        array $usedByTransaction,
        array $refundedByDebit
    ): array {
        $debits = [];
        foreach ($transactions as $transaction) {
            if ($transaction->amount >= 0) {
                continue;
            }
            foreach ($this->communicationsOf($transaction) as $communication) {
                if (isset($byCommunication[$communication])) {
                    $debits[] = $transaction;
                    break;
                }
            }
        }
        if ($debits === []) {
            return [];
        }

        // What each receivable has received designating it — absorbed or
        // not — computed the way settlementsFor() computes it, from the
        // state this pass has just left in memory, and what was already
        // refunded on it, per debit, so a refund can be revised without
        // counting itself.
        $receivablesById = [];
        foreach ($byCommunication as $receivables) {
            foreach ($receivables as $receivable) {
                $receivablesById[$receivable->id] = $receivable;
            }
        }
        $designated = [];
        foreach ($receivablesById as $receivableId => $receivable) {
            $designated[$receivableId] = $allocatedByReceivable[$receivableId] ?? 0;
        }
        $refunded = array_fill_keys(array_keys($receivablesById), 0);
        foreach ($transactions as $transaction) {
            foreach ($existingByPair[$transaction->id] ?? [] as $receivableId => $allocation) {
                if ($allocation->amountCents < 0 && isset($refunded[$receivableId])) {
                    $refunded[$receivableId] -= $allocation->amountCents;
                }
            }
            if ($transaction->amount <= 0) {
                continue;
            }
            // The rest of a credit naming SEVERAL communications is nobody's
            // surplus in particular, so it is nobody's: counting it for each
            // would let two refunds hand the same money back twice. A
            // treasurer decides whose it is.
            $communications = $this->communicationsOf($transaction);
            $left = count($communications) > 1
                ? 0
                : self::toCents($transaction->amount) - ($usedByTransaction[$transaction->id] ?? 0);
            foreach ($communications as $communication) {
                foreach ($byCommunication[$communication] ?? [] as $receivable) {
                    $pair = $existingByPair[$transaction->id][$receivable->id] ?? null;
                    // A person's « pays nothing here » rules the movement out;
                    // the pass's own share brought to 0 (a receivable lowered
                    // to 0) does not — what arrived is still the surplus.
                    if ($pair !== null && $pair->isManual() && $pair->amountCents <= 0) {
                        continue;
                    }
                    $designated[$receivable->id] += $left;
                }
            }
        }

        $changed = [];
        foreach ($debits as $debit) {
            foreach ($this->communicationsOf($debit) as $communication) {
                foreach ($byCommunication[$communication] ?? [] as $receivable) {
                    $existing = $existingByPair[$debit->id][$receivable->id] ?? null;
                    if ($existing !== null && $existing->isManual()) {
                        continue;
                    }

                    $ownShare = $existing !== null ? max(0, -$existing->amountCents) : 0;
                    $surplus = $designated[$receivable->id] - $receivable->amountDueCents
                        - ($refunded[$receivable->id] - $ownShare);
                    $leftOnDebit = self::toCents(-$debit->amount) - (($refundedByDebit[$debit->id] ?? 0) - $ownShare);
                    // Never below what this debit already gave back: the
                    // money left, and a surplus shrinking afterwards does
                    // not bring it back onto the account.
                    $amount = max($ownShare, min($surplus, $leftOnDebit));

                    if ($amount === $ownShare) {
                        continue;
                    }
                    if ($existing === null) {
                        $allocationId = $this->allocationRepository->create(
                            $debit->id,
                            $receivable->id,
                            -$amount,
                            ReceivableAllocation::SOURCE_AUTO,
                            null
                        );
                    } else {
                        $this->allocationRepository->update(
                            $existing->id,
                            -$amount,
                            ReceivableAllocation::SOURCE_AUTO,
                            null
                        );
                        $allocationId = $existing->id;
                    }

                    $changed[$debit->id] = ($changed[$debit->id] ?? 0) + 1;
                    $refunded[$receivable->id] += $amount - $ownShare;
                    $refundedByDebit[$debit->id] = ($refundedByDebit[$debit->id] ?? 0) + $amount - $ownShare;
                    $existingByPair[$debit->id][$receivable->id] = new ReceivableAllocation(
                        id: $allocationId,
                        transactionId: $debit->id,
                        receivableId: $receivable->id,
                        amountCents: -$amount,
                        source: ReceivableAllocation::SOURCE_AUTO,
                        createdBy: null,
                        createdAt: date('Y-m-d H:i:s')
                    );
                }
            }
        }

        return $changed;
    }

    /**
     * The category a movement takes from the receivables it pays (issue
     * #838) — the receivable's, ahead of any rule and of the AI, because
     * here the site KNOWS what the money is for.
     *
     * One receivable: its category. Several: the one receiving the largest
     * share of the movement. A tie keeps the movement's current category
     * when it is one of the tied ones, and otherwise takes the tied
     * receivable with the lowest id — stable, so a re-run changes nothing.
     * A refund is weighed by its size, like a payment.
     *
     * A movement that no longer pays any receivable naming a category
     * loses a category it had taken from one (it is uncategorised again,
     * and the rules and the AI may look at it); a treasurer's own choice
     * is never overwritten (TransactionRepository::setCategoryFromReceivable()).
     *
     * @param int[] $transactionIds the movements whose allocations changed
     */
    private function recategorize(array $transactionIds): void
    {
        if ($transactionIds === []) {
            return;
        }

        $allocationsByTransaction = $this->allocationRepository->findByTransactionIds($transactionIds);
        $receivableIds = [];
        foreach ($allocationsByTransaction as $allocations) {
            foreach ($allocations as $allocation) {
                $receivableIds[$allocation->receivableId] = true;
            }
        }
        $categoryByReceivable = [];
        foreach ($this->receivableRepository->findByIds(array_keys($receivableIds)) as $receivable) {
            $categoryByReceivable[$receivable->id] = $receivable->categoryId;
        }

        foreach ($this->transactionRepository->findByIds($transactionIds) as $transaction) {
            if ($transaction->categorySource === Transaction::CATEGORY_SOURCE_MANUAL) {
                continue;
            }

            $category = self::dominantCategory(
                $allocationsByTransaction[$transaction->id] ?? [],
                $categoryByReceivable,
                $transaction->categoryId
            );

            if ($category === null) {
                if ($transaction->categorySource === Transaction::CATEGORY_SOURCE_RECEIVABLE) {
                    $this->transactionRepository->setCategoryFromReceivable($transaction->id, null);
                }
                continue;
            }

            if ($transaction->categoryId !== $category
                || $transaction->categorySource !== Transaction::CATEGORY_SOURCE_RECEIVABLE
            ) {
                $this->transactionRepository->setCategoryFromReceivable($transaction->id, $category);
            }
        }
    }

    /**
     * @param ReceivableAllocation[] $allocations one movement's
     * @param array<int, ?int> $categoryByReceivable
     */
    private static function dominantCategory(array $allocations, array $categoryByReceivable, ?int $current): ?int
    {
        $best = 0;
        /** @var array<int, int> $tied receivable id => category id */
        $tied = [];
        foreach ($allocations as $allocation) {
            $category = $categoryByReceivable[$allocation->receivableId] ?? null;
            $weight = abs($allocation->amountCents);
            if ($category === null || $weight === 0) {
                continue;
            }
            if ($weight > $best) {
                $best = $weight;
                $tied = [$allocation->receivableId => $category];
            } elseif ($weight === $best) {
                $tied[$allocation->receivableId] = $category;
            }
        }

        if ($tied === []) {
            return null;
        }
        if ($current !== null && in_array($current, $tied, true)) {
            return $current;
        }
        ksort($tied);

        return reset($tied);
    }

    // ── what a human does ───────────────────────────────────────────────

    /**
     * Attaches (or corrects) an amount of one movement to one receivable,
     * on a treasurer's say-so.
     *
     * $amountCents of 0 is the removal: it records that this movement
     * pays nothing towards this receivable, which the automatic pass then
     * respects. Deleting the row instead would leave the next import free
     * to re-create the allocation the treasurer had just taken away.
     *
     * @throws FinanceException when either side is unknown or out of the
     *         caller's reach, when the two sit on different accounts, when
     *         the movement is a debit, or when the amount would take
     *         either side past what it has left.
     */
    public function allocate(
        int $transactionId,
        int $receivableId,
        int $amountCents,
        Role $viewerRole,
        ?int $actorUserAccountId
    ): void
    {
        [$transaction, $receivable] = $this->requirePair($transactionId, $receivableId, $viewerRole);

        if ($amountCents < 0) {
            throw new FinanceException('Une imputation ne peut pas être négative.');
        }
        if ($transaction->amount <= 0) {
            throw new FinanceException("Ce mouvement est un débit : il ne peut pas régler une créance.");
        }

        $existing = $this->allocationRepository->findPair($transactionId, $receivableId);
        $ownShare = $existing !== null ? max(0, $existing->amountCents) : 0;

        $remainingDue = $receivable->amountDueCents - ($this->allocatedCents($receivableId) - $ownShare);
        if ($amountCents > $remainingDue) {
            throw new FinanceException(sprintf(
                'Cette créance n\'attend plus que %s €. Une créance n\'absorbe jamais plus que ce qu\'elle doit : '
                . 'le surplus reste non imputé et apparaît en trop-perçu.',
                self::euros($remainingDue)
            ));
        }

        $remainingOnTransaction = self::toCents($transaction->amount) - ($this->usedCents($transactionId) - $ownShare);
        if ($amountCents > $remainingOnTransaction) {
            throw new FinanceException(sprintf(
                'Il ne reste que %s € à répartir sur ce mouvement.',
                self::euros($remainingOnTransaction)
            ));
        }

        if ($existing !== null) {
            $this->allocationRepository->update(
                $existing->id,
                $amountCents,
                ReceivableAllocation::SOURCE_MANUAL,
                $actorUserAccountId
            );
        } else {
            $this->allocationRepository->create(
                $transactionId,
                $receivableId,
                $amountCents,
                ReceivableAllocation::SOURCE_MANUAL,
                $actorUserAccountId
            );
        }

        $this->recategorize([$transactionId]);
    }

    /**
     * Spreads one movement over several receivables in a single gesture —
     * the household that pays for three children with one transfer. All
     * or nothing: a partly-applied split would leave the treasurer
     * guessing which lines took.
     *
     * @param array<int, int> $amountCentsByReceivableId
     * @throws FinanceException
     */
    public function split(
        int $transactionId,
        array $amountCentsByReceivableId,
        Role $viewerRole,
        ?int $actorUserAccountId
    ): void
    {
        if ($amountCentsByReceivableId === []) {
            throw new FinanceException('Indiquez au moins une créance à couvrir.');
        }

        foreach ($amountCentsByReceivableId as $receivableId => $amountCents) {
            $this->allocate($transactionId, (int) $receivableId, (int) $amountCents, $viewerRole, $actorUserAccountId);
        }
    }

    /**
     * Records a debit as paying back part of a receivable's surplus.
     *
     * This is what closes the overpayment cycle, and it closes on the
     * account statement: the state becomes "remboursé" because the money
     * left, never because somebody ticked a box.
     *
     * @throws FinanceException
     */
    public function allocateRefund(
        int $transactionId,
        int $receivableId,
        int $amountCents,
        Role $viewerRole,
        ?int $actorUserAccountId
    ): void
    {
        [$transaction, $receivable] = $this->requirePair($transactionId, $receivableId, $viewerRole);

        if ($amountCents <= 0) {
            throw new FinanceException('Le montant remboursé doit être positif.');
        }
        if ($transaction->amount >= 0) {
            throw new FinanceException("Un remboursement est un débit : ce mouvement n'en est pas un.");
        }
        if ($amountCents > self::toCents(abs($transaction->amount))) {
            throw new FinanceException('Ce mouvement ne porte pas un montant aussi élevé.');
        }

        $settlement = $this->settlementFor($receivable);
        if ($amountCents > $settlement->amountOverpaidCents) {
            throw new FinanceException(sprintf(
                'Le trop-perçu de cette créance est de %s €.',
                self::euros($settlement->amountOverpaidCents)
            ));
        }

        $existing = $this->allocationRepository->findPair($transactionId, $receivableId);
        if ($existing !== null) {
            $this->allocationRepository->update(
                $existing->id,
                -$amountCents,
                ReceivableAllocation::SOURCE_MANUAL,
                $actorUserAccountId
            );
        } else {
            $this->allocationRepository->create(
                $transactionId,
                $receivableId,
                -$amountCents,
                ReceivableAllocation::SOURCE_MANUAL,
                $actorUserAccountId
            );
        }

        // The money going back is booked where it came in.
        $this->recategorize([$transactionId]);
    }

    /**
     * The debits that could be the refund of this receivable's surplus:
     * on its own account, booked on or after $sinceDate, reconciled with
     * nothing, and no larger than what is still owed back. Empty when
     * nothing is owed back.
     *
     * Reconciles first, so a debit the communication already matched is
     * not offered again, and the surplus is the current one.
     *
     * Reached through Api\ExpectedReceivableInterface, by the module that
     * raised the receivable: there is no finance viewer to check an
     * account partition against, and the caller's own rules decided who
     * stands in front of the list — which is why a candidate carries so
     * little (Api\RefundCandidate).
     *
     * @return list<Transaction>
     */
    public function refundCandidatesFor(ExpectedReceivable $receivable, string $sinceDate): array
    {
        $surplus = $this->refreshAndSettle([$receivable])[$receivable->id]->amountOverpaidCents;
        if ($surplus <= 0) {
            return [];
        }

        $debits = $this->transactionRepository->findDebitsSince($receivable->accountId, $sinceDate);
        $allocations = $this->allocationRepository->findByTransactionIds(
            array_map(static fn(Transaction $debit): int => $debit->id, $debits)
        );

        $candidates = [];
        foreach ($debits as $debit) {
            if (self::toCents(-$debit->amount) <= $surplus
                && self::isUnreconciledFor($allocations[$debit->id] ?? [], $receivable->id)
            ) {
                $candidates[] = $debit;
            }
        }

        return $candidates;
    }

    /**
     * Records a candidate debit as the refund of this receivable's
     * surplus — its whole amount, written as a person's decision so the
     * automatic pass never revises it.
     *
     * **Everything refundCandidatesFor() filtered on is checked again,
     * under a lock**: the receivable's row first, then the movement's, in
     * that order everywhere. The first stops two decisions on the same
     * receivable from each fitting under a surplus they then exceed
     * together; the second stops one debit from becoming the refund of two
     * receivables at once. The matching runs inside the lock before the
     * checks, so a debit the communication matched in the meantime is
     * seen as reconciled rather than claimed twice.
     *
     * @return int the cents refunded
     * @throws FinanceException
     */
    public function refundFromCandidate(
        ExpectedReceivable $receivable,
        int $transactionId,
        string $sinceDate,
        ?int $actorUserAccountId
    ): int {
        return $this->receivableRepository->withReceivableLocked(
            $receivable->id,
            function () use ($receivable, $transactionId, $sinceDate, $actorUserAccountId): int {
                $this->transactionRepository->lockForUpdate($transactionId);

                $current = $this->receivableRepository->findById($receivable->id);
                if ($current === null) {
                    throw new FinanceException("Cette créance n'existe pas.");
                }
                $surplus = $this->refreshAndSettle([$current])[$current->id]->amountOverpaidCents;

                $debit = $this->transactionRepository->findById($transactionId);
                if ($debit === null || $debit->accountId !== $current->accountId) {
                    throw new FinanceException("Ce débit n'est pas un mouvement du compte de cette créance.");
                }
                if ($debit->amount >= 0) {
                    throw new FinanceException("Un remboursement est un débit : ce mouvement n'en est pas un.");
                }
                if ($debit->transactionDate < $sinceDate) {
                    throw new FinanceException('Ce débit est antérieur à la date à partir de laquelle un '
                        . 'remboursement est cherché.');
                }
                $onDebit = $this->allocationRepository->findByTransactionId($debit->id);
                if (!self::isUnreconciledFor($onDebit, $current->id)) {
                    throw new FinanceException('Ce débit est déjà rapproché.');
                }
                $amountCents = self::toCents(-$debit->amount);
                if ($amountCents > $surplus) {
                    throw new FinanceException($surplus > 0
                        ? sprintf(
                            'Ce débit (%s €) dépasse le trop-perçu de cette créance (%s €).',
                            self::euros($amountCents),
                            self::euros($surplus)
                        )
                        : 'Cette créance ne porte aucun trop-perçu à rembourser.');
                }

                $this->allocationRepository->create(
                    $debit->id,
                    $current->id,
                    -$amountCents,
                    ReceivableAllocation::SOURCE_MANUAL,
                    $actorUserAccountId
                );
                $this->recategorize([$debit->id]);

                return $amountCents;
            }
        );
    }

    /**
     * Whether a debit is free to become this receivable's refund: nothing
     * allocated on it to any receivable, and no person having already
     * said, for this very pair, that it is no refund of this one.
     *
     * @param ReceivableAllocation[] $allocations the debit's
     */
    private static function isUnreconciledFor(array $allocations, int $receivableId): bool
    {
        foreach ($allocations as $allocation) {
            if ($allocation->amountCents !== 0) {
                return false;
            }
            if ($allocation->receivableId === $receivableId && $allocation->isManual()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves a receivable's surplus onto another receivable of the same
     * household, in one gesture.
     *
     * This is often the right answer rather than a refund: a parent who
     * rounds 38,25 € up to 45 € means to pay, not to be sent 6,75 € back.
     *
     * The money never teleports. The surplus is, physically, the
     * unallocated remainder of the credits that named the first
     * receivable, so those credits are the ones allocated to the second —
     * oldest first, and never past what either side has left. Both
     * receivables must sit on the same account, which
     * Service\ReceivableAllocationService::allocate() enforces for each
     * step.
     *
     * @throws FinanceException
     */
    public function transferOverpayment(
        int $fromReceivableId,
        int $toReceivableId,
        int $amountCents,
        Role $viewerRole,
        ?int $actorUserAccountId
    ): void {
        $from = $this->requireReceivable($fromReceivableId, $viewerRole);
        $to = $this->requireReceivable($toReceivableId, $viewerRole);

        if ($from->id === $to->id) {
            throw new FinanceException('Choisissez une autre créance que celle qui porte le trop-perçu.');
        }
        if ($amountCents <= 0) {
            throw new FinanceException('Le montant à imputer doit être positif.');
        }

        $surplus = $this->settlementFor($from)->amountOverpaidCents;
        if ($amountCents > $surplus) {
            throw new FinanceException(sprintf('Le trop-perçu de cette créance est de %s €.', self::euros($surplus)));
        }

        $target = $this->settlementFor($to)->amountRemainingCents();
        if ($amountCents > $target) {
            throw new FinanceException(sprintf("Cette créance n'attend plus que %s €.", self::euros($target)));
        }

        $digits = self::digitsOnly($from->communication);
        $left = $amountCents;

        foreach ($this->transactionRepository->findByAccountId($from->accountId) as $transaction) {
            if ($left <= 0) {
                break;
            }
            if ($transaction->amount <= 0 || !in_array($digits, $this->communicationsOf($transaction), true)) {
                continue;
            }

            $available = $this->unallocatedCentsOf($transaction);
            if ($available <= 0) {
                continue;
            }

            $existing = $this->allocationRepository->findPair($transaction->id, $to->id);
            $share = min($available, $left);
            $this->allocate(
                $transaction->id,
                $to->id,
                ($existing !== null ? max(0, $existing->amountCents) : 0) + $share,
                $viewerRole,
                $actorUserAccountId
            );
            $left -= $share;
        }

        if ($left > 0) {
            // Nothing was left lying on a movement that names this
            // receivable — the surplus the screen showed came from
            // somewhere this gesture cannot reach.
            throw new FinanceException("Le trop-perçu n'a pas pu être imputé en entier : rapprochez le mouvement à la "
                . "main.");
        }
    }

    /**
     * Abandons a receivable: a dispense, a goodwill gesture, an invoicing
     * mistake. It stops being expected and **nothing enters the account**
     * — which is the whole reason this is not expressed as a payment.
     *
     * @throws FinanceException
     */
    /**
     * Where a receivable came from, once its own visibility is settled.
     *
     * Exposed so a caller can refuse a receivable that does not belong to
     * the thing its URL names, without ever learning of one it may not see:
     * the visibility predicate runs first, and an invisible receivable is
     * reported as absent here exactly as it is everywhere else. The caller
     * gets the source and decides — this service knows nothing of campaigns
     * and is not about to (issue #582).
     *
     * @return array{0: string, 1: int} the source module, then its reference
     * @throws FinanceException
     */
    public function sourceOfReceivable(int $receivableId, Role $viewerRole): array
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        return [$receivable->sourceModule, $receivable->sourceReferenceId];
    }

    public function waive(int $receivableId, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        $this->receivableRepository->setWaived($receivable->id, date('Y-m-d H:i:s'), $actorUserAccountId);
    }

    /**
     * @throws FinanceException
     */
    public function cancelWaiver(int $receivableId, Role $viewerRole): void
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        $this->receivableRepository->setWaived($receivable->id, null, null);
    }

    /**
     * "This surplus is owed back." The one human decision in the
     * overpayment cycle — an unallocated remainder is neutral until
     * somebody says otherwise, because it may just as well be a payment
     * for something not invoiced yet.
     *
     * @throws FinanceException
     */
    public function requestRefund(int $receivableId, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        if ($this->settlementFor($receivable)->amountOverpaidCents <= 0) {
            throw new FinanceException("Cette créance ne porte aucun trop-perçu à rembourser.");
        }

        $this->receivableRepository->setRefundRequested($receivable->id, date('Y-m-d H:i:s'), $actorUserAccountId);
    }

    /**
     * @throws FinanceException
     */
    public function cancelRefundRequest(int $receivableId, Role $viewerRole): void
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        $this->receivableRepository->setRefundRequested($receivable->id, null, null);
    }

    // ── reading ─────────────────────────────────────────────────────────

    public function settlementFor(ExpectedReceivable $receivable): ReceivableSettlement
    {
        return $this->settlementsFor([$receivable])[$receivable->id];
    }

    /**
     * Settlements for many receivables at once, keyed by receivable id.
     *
     * Batched deliberately: a campaign screen holds a few hundred lines,
     * and asking one at a time re-read and re-decrypted every movement on
     * the account once per line — the same trap
     * ExpectedReceivableService::getReceivableStatuses() was written to
     * avoid.
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, ReceivableSettlement>
     */
    /**
     * The settlements as the STORED allocations alone tell them — one
     * query, no account-history scan, no writes. Authoritative for what
     * the home payment band reads: amountAllocated, amountRemaining,
     * amountRefunded, and the status (waived/unpaid/partial/paid) —
     * statusFor() derives entirely from stored allocations.
     * amountDesignated is reported as the allocated floor (the
     * arrived-but-unabsorbed share needs the credit scan settlementsFor()
     * pays for), so overpaid/refundState may under-report; a surface that
     * shows those must use settlementsFor() or refreshAndSettle().
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, ReceivableSettlement>
     */
    public function storedSettlementsFor(array $receivables): array
    {
        if ($receivables === []) {
            return [];
        }

        $allocationsByReceivable = $this->allocationRepository->findByReceivableIds(
            array_map(static fn(ExpectedReceivable $receivable): int => $receivable->id, $receivables)
        );

        $settlements = [];
        foreach ($receivables as $receivable) {
            $allocated = 0;
            $refunded = 0;
            foreach ($allocationsByReceivable[$receivable->id] ?? [] as $allocation) {
                if ($allocation->amountCents > 0) {
                    $allocated += $allocation->amountCents;
                } elseif ($allocation->amountCents < 0) {
                    $refunded += -$allocation->amountCents;
                }
            }

            $grossOverpaid = max(0, $allocated - $receivable->amountDueCents);

            $settlements[$receivable->id] = new ReceivableSettlement(
                receivableId: $receivable->id,
                amountDueCents: $receivable->amountDueCents,
                amountAllocatedCents: $allocated,
                amountDesignatedCents: $allocated,
                amountRefundedCents: $refunded,
                amountOverpaidCents: max(0, $grossOverpaid - $refunded),
                status: $this->statusFor($receivable, $allocated),
                refundState: $this->refundStateFor($receivable, $grossOverpaid, $refunded)
            );
        }

        return $settlements;
    }

    /**
     * What this account is still waiting for, in cents: the remainder of
     * every unpaid or partly paid receivable booked against it — never
     * the amounts first asked, and nothing for a paid or waived one. The
     * dashboard's « Paiements encore attendus » (issue #838).
     *
     * Reads the allocations as they stand: the dashboard calls it right
     * after the reconciliation pass it already triggers.
     */
    public function amountStillExpectedCents(int $accountId): int
    {
        $total = 0;
        foreach ($this->settlementsFor($this->receivableRepository->findByAccountId($accountId)) as $settlement) {
            if (in_array(
                $settlement->status,
                [ReceivableSettlement::STATUS_UNPAID, ReceivableSettlement::STATUS_PARTIAL],
                true
            )) {
                $total += $settlement->amountRemainingCents();
            }
        }

        return $total;
    }

    /**
     * The full settlement picture — stored allocations PLUS the credit
     * scan that finds arrived-but-unabsorbed designated money, which
     * storedSettlementsFor() deliberately skips.
     *
     * @param ExpectedReceivable[] $receivables
     * @return array<int, ReceivableSettlement>
     */
    public function settlementsFor(array $receivables): array
    {
        if ($receivables === []) {
            return [];
        }

        $allocationsByReceivable = $this->allocationRepository->findByReceivableIds(
            array_map(static fn(ExpectedReceivable $receivable): int => $receivable->id, $receivables)
        );

        // Credits per account, loaded once, plus what each of them has
        // already given away — the basis for "what arrived designating
        // this receivable that it could not absorb".
        $transactionsByAccount = [];
        foreach ($receivables as $receivable) {
            if (!isset($transactionsByAccount[$receivable->accountId])) {
                $transactionsByAccount[$receivable->accountId] =
                    $this->transactionRepository->findByAccountId($receivable->accountId);
            }
        }

        $creditIds = [];
        $communicationsByTransaction = [];
        foreach ($transactionsByAccount as $transactions) {
            foreach ($transactions as $transaction) {
                if ($transaction->amount <= 0 || isset($communicationsByTransaction[$transaction->id])) {
                    continue;
                }
                $creditIds[] = $transaction->id;
                $communicationsByTransaction[$transaction->id] = $this->communicationsOf($transaction);
            }
        }

        $usedByTransaction = array_fill_keys($creditIds, 0);
        foreach ($this->allocationRepository->findByTransactionIds($creditIds) as $transactionId => $allocations) {
            foreach ($allocations as $allocation) {
                if ($allocation->amountCents > 0) {
                    $usedByTransaction[$transactionId] += $allocation->amountCents;
                }
            }
        }

        $settlements = [];
        foreach ($receivables as $receivable) {
            $allocations = $allocationsByReceivable[$receivable->id] ?? [];

            $allocated = 0;
            $refunded = 0;
            /** @var array<int, ReceivableAllocation> $byTransaction */
            $byTransaction = [];
            foreach ($allocations as $allocation) {
                $byTransaction[$allocation->transactionId] = $allocation;
                if ($allocation->amountCents > 0) {
                    $allocated += $allocation->amountCents;
                } elseif ($allocation->amountCents < 0) {
                    $refunded += -$allocation->amountCents;
                }
            }

            $designated = $allocated;
            $digits = self::digitsOnly($receivable->communication);
            foreach ($transactionsByAccount[$receivable->accountId] ?? [] as $transaction) {
                if ($transaction->amount <= 0) {
                    continue;
                }
                if (!in_array($digits, $communicationsByTransaction[$transaction->id] ?? [], true)) {
                    continue;
                }
                // A movement a treasurer has already spoken about — by
                // attaching a share of it, or by saying it pays nothing
                // here — has had its say. Only the part still lying
                // unattached on a movement that names this receivable
                // counts as arrived-and-unabsorbed. The pass's own share
                // brought to 0 is not a treasurer speaking: a receivable
                // lowered to 0 after a payment keeps it all as surplus.
                $pair = $byTransaction[$transaction->id] ?? null;
                if ($pair !== null && $pair->isManual() && $pair->amountCents <= 0) {
                    continue;
                }
                // The rest of a movement naming several communications is
                // nobody's in particular: it stays out of every surplus
                // (and so out of every automatic or proposed refund).
                if (count($communicationsByTransaction[$transaction->id]) > 1) {
                    continue;
                }
                $designated += self::toCents($transaction->amount) - ($usedByTransaction[$transaction->id] ?? 0);
            }

            $overpaid = max(0, $designated - $receivable->amountDueCents - $refunded);
            $grossOverpaid = max(0, $designated - $receivable->amountDueCents);

            $settlements[$receivable->id] = new ReceivableSettlement(
                receivableId: $receivable->id,
                amountDueCents: $receivable->amountDueCents,
                amountAllocatedCents: $allocated,
                amountDesignatedCents: $designated,
                amountRefundedCents: $refunded,
                amountOverpaidCents: $overpaid,
                status: $this->statusFor($receivable, $allocated),
                refundState: $this->refundStateFor($receivable, $grossOverpaid, $refunded)
            );
        }

        return $settlements;
    }

    /**
     * The allocations against one receivable, newest last — the "detail
     * of the instalments" a trop-perçu has to show, and the audit trail
     * behind a status nobody typed.
     *
     * @return ReceivableAllocation[]
     */
    public function allocationsFor(int $receivableId): array
    {
        return $this->allocationRepository->findByReceivableId($receivableId);
    }

    /**
     * The movements that pay or refund one receivable, oldest first, each
     * with this receivable's share only — what the module that raised it
     * shows its payer (Api\ExpectedReceivableInterface::
     * getReceivableMovements()). A zero-amount allocation is a person
     * saying the movement pays nothing here, so it is not one of them.
     *
     * Reads what is written and writes nothing.
     *
     * @return list<ReceivableMovement>
     */
    public function movementsFor(ExpectedReceivable $receivable): array
    {
        $allocations = array_filter(
            $this->allocationRepository->findByReceivableId($receivable->id),
            static fn(ReceivableAllocation $allocation): bool => $allocation->amountCents !== 0
        );
        $byTransaction = [];
        foreach ($allocations as $allocation) {
            $byTransaction[$allocation->transactionId] = $allocation;
        }

        $transactions = $this->transactionRepository->findByIds(array_keys($byTransaction));
        usort(
            $transactions,
            static fn(Transaction $a, Transaction $b): int
                => [$a->transactionDate, $a->id] <=> [$b->transactionDate, $b->id]
        );

        $movements = [];
        foreach ($transactions as $transaction) {
            // Never across two accounts (an allocation cannot be written
            // so), and checked rather than assumed: this list is handed to
            // another module.
            if ($transaction->accountId !== $receivable->accountId) {
                continue;
            }
            $allocation = $byTransaction[$transaction->id];
            $movements[] = new ReceivableMovement(
                date: $transaction->transactionDate,
                label: $transaction->label,
                counterpartyName: $transaction->counterpartyName,
                amountCents: $allocation->amountCents,
                automatic: !$allocation->isManual()
            );
        }

        return $movements;
    }

    /**
     * What is left unattached on a movement. Neutral by nature: a
     * remainder is not a trop-perçu until a human says the money is owed
     * back — it may simply be a payment for something not invoiced yet.
     */
    public function unallocatedCentsOf(Transaction $transaction): int
    {
        $used = 0;
        foreach ($this->allocationRepository->findByTransactionId($transaction->id) as $allocation) {
            if ($allocation->amountCents > 0) {
                $used += $allocation->amountCents;
            }
        }

        return max(0, self::toCents($transaction->amount) - $used);
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * @return array{Transaction, ExpectedReceivable}
     * @throws FinanceException
     */
    private function requirePair(int $transactionId, int $receivableId, Role $viewerRole): array
    {
        $receivable = $this->requireReceivable($receivableId, $viewerRole);

        $transaction = $this->transactionRepository->findById($transactionId);
        if ($transaction === null) {
            throw new FinanceException("Ce mouvement n'existe pas.");
        }

        // The invariant, enforced rather than documented: an allocation
        // never joins two accounts. Without this check the "wrong
        // account" case could be closed by imputing at a distance, and
        // the receiving section's books would say it collected money it
        // never saw.
        if ($transaction->accountId !== $receivable->accountId) {
            throw new FinanceException(
                "Ce mouvement et cette créance ne sont pas sur le même compte. L'argent doit d'abord être "
                . 'viré sur le bon compte : rien ne s\'impute à distance.'
            );
        }

        return [$transaction, $receivable];
    }

    /**
     * @throws FinanceException
     */
    private function requireReceivable(int $receivableId, Role $viewerRole): ExpectedReceivable
    {
        $receivable = $this->receivableRepository->findById($receivableId);
        if ($receivable === null) {
            throw new FinanceException("Cette créance n'existe pas.");
        }

        // Same predicate as every other finance page (Service\
        // AccountVisibility): section treasurers are partitioned here as
        // everywhere else, or the partition leaks through this door.
        if (!$this->accountVisibility->isVisibleTo(
            $this->accountRepository->findById($receivable->accountId),
            $viewerRole
        )) {
            throw new FinanceException("Cette créance n'existe pas.");
        }

        return $receivable;
    }

    private function allocatedCents(int $receivableId): int
    {
        $total = 0;
        foreach ($this->allocationRepository->findByReceivableId($receivableId) as $allocation) {
            if ($allocation->amountCents > 0) {
                $total += $allocation->amountCents;
            }
        }

        return $total;
    }

    private function usedCents(int $transactionId): int
    {
        $total = 0;
        foreach ($this->allocationRepository->findByTransactionId($transactionId) as $allocation) {
            if ($allocation->amountCents > 0) {
                $total += $allocation->amountCents;
            }
        }

        return $total;
    }

    /**
     * @return ReceivableSettlement::STATUS_*
     */
    private function statusFor(ExpectedReceivable $receivable, int $allocatedCents): string
    {
        if ($receivable->isWaived()) {
            return ReceivableSettlement::STATUS_WAIVED;
        }
        if ($allocatedCents <= 0) {
            return ReceivableSettlement::STATUS_UNPAID;
        }
        if ($allocatedCents >= $receivable->amountDueCents) {
            return ReceivableSettlement::STATUS_PAID;
        }

        return ReceivableSettlement::STATUS_PARTIAL;
    }

    /**
     * @return ReceivableSettlement::REFUND_*
     */
    private function refundStateFor(ExpectedReceivable $receivable, int $grossOverpaidCents, int $refundedCents): string
    {
        if ($grossOverpaidCents <= 0) {
            return ReceivableSettlement::REFUND_NONE;
        }
        if ($refundedCents >= $grossOverpaidCents) {
            return ReceivableSettlement::REFUND_DONE;
        }
        if ($receivable->isRefundRequested()) {
            return ReceivableSettlement::REFUND_REQUESTED;
        }

        return ReceivableSettlement::REFUND_OPEN;
    }

    /**
     * Every structured communication a movement's free text spells out,
     * across its three free-text fields — matched field by field, never
     * on a concatenation, so a communication cannot be assembled across a
     * boundary where it appears in neither. The structured communication a
     * format carries in a field of its own (CODA) is read first.
     *
     * @return list<string>
     */
    private function communicationsOf(Transaction $transaction): array
    {
        $found = [];
        $fields = [
            $transaction->structuredCommunication,
            $transaction->label,
            $transaction->comment,
            $transaction->extraDetails,
        ];
        foreach ($fields as $field) {
            if ($field === null) {
                continue;
            }
            foreach (StructuredCommunicationService::extract($field) as $communication) {
                if (!in_array($communication, $found, true)) {
                    $found[] = $communication;
                }
            }
        }

        return $found;
    }

    private static function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }
}
