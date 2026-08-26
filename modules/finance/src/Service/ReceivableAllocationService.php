<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
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
        foreach ($this->allocationRepository->findByTransactionIds($transactionIds) as $transactionId => $allocations) {
            foreach ($allocations as $allocation) {
                if ($allocation->amountCents > 0) {
                    $usedByTransaction[$transactionId] += $allocation->amountCents;
                }
            }
        }

        $changed = 0;

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
                    $remainingOnTransaction = self::toCents($transaction->amount) - ($usedByTransaction[$transaction->id] - $ownShare);

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
                    } elseif ($existing->amountCents !== $amount) {
                        $this->allocationRepository->update(
                            $allocationId,
                            $amount,
                            ReceivableAllocation::SOURCE_AUTO,
                            null
                        );
                        $changed++;
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

        return $changed;
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
    public function allocate(int $transactionId, int $receivableId, int $amountCents, Role $viewerRole, ?int $actorUserAccountId): void
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
            $this->allocationRepository->update($existing->id, $amountCents, ReceivableAllocation::SOURCE_MANUAL, $actorUserAccountId);
            return;
        }

        $this->allocationRepository->create(
            $transactionId,
            $receivableId,
            $amountCents,
            ReceivableAllocation::SOURCE_MANUAL,
            $actorUserAccountId
        );
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
    public function split(int $transactionId, array $amountCentsByReceivableId, Role $viewerRole, ?int $actorUserAccountId): void
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
    public function allocateRefund(int $transactionId, int $receivableId, int $amountCents, Role $viewerRole, ?int $actorUserAccountId): void
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
            $this->allocationRepository->update($existing->id, -$amountCents, ReceivableAllocation::SOURCE_MANUAL, $actorUserAccountId);
            return;
        }

        $this->allocationRepository->create(
            $transactionId,
            $receivableId,
            -$amountCents,
            ReceivableAllocation::SOURCE_MANUAL,
            $actorUserAccountId
        );
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
            throw new FinanceException("Le trop-perçu n'a pas pu être imputé en entier : rapprochez le mouvement à la main.");
        }
    }

    /**
     * Abandons a receivable: a dispense, a goodwill gesture, an invoicing
     * mistake. It stops being expected and **nothing enters the account**
     * — which is the whole reason this is not expressed as a payment.
     *
     * @throws FinanceException
     */
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
                // counts as arrived-and-unabsorbed.
                if (isset($byTransaction[$transaction->id]) && $byTransaction[$transaction->id]->amountCents <= 0) {
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
        if (!$this->accountVisibility->isVisibleTo($this->accountRepository->findById($receivable->accountId), $viewerRole)) {
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
     * boundary where it appears in neither.
     *
     * @return list<string>
     */
    private function communicationsOf(Transaction $transaction): array
    {
        $found = [];
        foreach ([$transaction->label, $transaction->comment, $transaction->extraDetails] as $field) {
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
