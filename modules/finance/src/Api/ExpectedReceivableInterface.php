<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5).
 * Registers and tracks generic "money we expect to receive" entries,
 * keyed by (source_module, source_reference_id) — the consuming module
 * never touches Finance's internal tables directly.
 */
interface ExpectedReceivableInterface
{
    /**
     * Registers an expected payment. $amountCents and $communication are
     * caller-computed (see StructuredCommunicationInterface::generate()).
     * Returns the new receivable's id.
     *
     * **Every receivable carries a valid Belgian structured
     * communication** — twelve digits whose last two are the mod-97 check
     * of the first ten, in any of the spellings
     * StructuredCommunicationInterface::generate() and the banks use
     * (issue #837). Settlement is detected by finding exactly those twelve
     * digits on an incoming transaction, and « Paiements à traiter »
     * treats a credit carrying a valid one that matches no receivable as a
     * decision the treasurer owes; neither means anything if a receivable
     * could be raised under looser text. Anything else is rejected with a
     * Modules\Finance\Api\FinanceException, and what is stored is the
     * canonical `+++NNN/NNNN/NNNNN+++` spelling.
     *
     * **Every receivable names a category** — an active
     * `finance_categories.id` — under which its payments are booked
     * (issue #838): a movement allocated to it takes that category, before
     * any categorisation rule or the AI is asked. A consuming module asks
     * its user for it next to the account (FinanceAccountInterface::
     * getCategories()); an unknown or archived one is rejected with a
     * FinanceException.
     *
     * $memberId names the debtor when the debtor is a member of the unit
     * — `members.id`, the persistent identity, so the link survives the
     * scout year. It is **optional on purpose**: a module invoicing
     * somebody who is not a member (an outside renter) has nobody to
     * name, and making it mandatory would shut that case out.
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
    ): int;

    /**
     * Changes what an existing receivable expects, keeping its
     * communication — and therefore every payment already matched against
     * it.
     *
     * A receivable's amount is not fixed for life: a negotiated price
     * changes, a final settlement replaces an estimate, an order is
     * amended. Deleting and recreating would mint a new communication and
     * orphan the transfers the payer already made against the old one, so
     * the amount moves in place instead.
     *
     * **Lowering it below what has already come in is refused** unless the
     * caller says, in so many words, that it means to create an
     * overpayment. Silently producing one is how a refund nobody knows
     * about happens: the receivable reads "paid", the surplus sits on the
     * account, and nothing anywhere says somebody is owed money. With
     * $allowBelowReceived the caller has stated it knows, and owes the
     * payer the difference.
     *
     * Deliberately generic: nothing in this signature names a module, and
     * the same call serves a rental's renegotiated price, an event's
     * amended order, or anything later.
     *
     * @throws \Modules\Finance\Api\FinanceException when the receivable
     *         does not exist, when $amountCents is negative, or when it is
     *         below the amount already received and $allowBelowReceived is
     *         false.
     */
    public function updateReceivableAmount(
        int $receivableId,
        int $amountCents,
        bool $allowBelowReceived = false
    ): void;

    /**
     * @return array{amount_due: int, amount_received: int, status: 'paid'|'partial'|'unpaid'}
     */
    public function getReceivableStatus(int $receivableId): array;

    /**
     * Removes every receivable registered for this source instance — a
     * consuming module calls this when the source itself is deleted (e.g.
     * the news module deleting an article's form and all its responses).
     */
    public function deleteReceivablesForSource(string $sourceModule, int $sourceReferenceId): void;

    // ── the refund side (chantier #927, §2.6) ───────────────────────────

    /**
     * Where one receivable stands, refunds included: due, received,
     * refunded, still owed back (trop-perçu), still expected.
     *
     * **Every method from here on names its source module**, and finance
     * answers only for a receivable that module raised: an id that is
     * unknown, or that belongs to another module, is answered exactly as
     * absent — null here, an empty list from the two readings, a refusal
     * from reconcileRefund(). A consuming module reads what it raised and
     * nothing else, whatever id reaches it.
     *
     * Brings the automatic matching up to date before answering, like
     * getReceivableStatus(): a payment or a refund imported a minute ago
     * is counted.
     */
    public function getReceivableStanding(string $sourceModule, int $receivableId): ?ReceivableStanding;

    /**
     * The movements reconciled with one receivable — the payments it
     * absorbed and the refunds of its surplus — oldest first. Read-only:
     * nothing is matched or written by asking, so a caller wanting the
     * latest import reflected asks getReceivableStanding() first.
     *
     * **Only this receivable's share of each movement**, and only the
     * movements that pay or refund it: a transfer shared with another
     * receivable shows this one's part, and nothing of any other
     * receivable or of the rest of the account is ever handed out
     * (ReceivableMovement).
     *
     * @return list<ReceivableMovement>
     */
    public function getReceivableMovements(string $sourceModule, int $receivableId): array;

    /**
     * Debits that could be the refund of this receivable's surplus, for
     * the person who made the transfer without its communication to point
     * at it: debits on the receivable's own account, reconciled with
     * nothing, booked on or after $since, and of at most the surplus
     * still owed back — oldest first. Empty when nothing is owed back.
     *
     * A debit carrying the communication needs none of this: the import
     * reconciles it on its own, up to the surplus.
     *
     * Each candidate says when, how much and to whom, and nothing else
     * (RefundCandidate): these are movements of the unit's account that
     * belong to nobody yet.
     *
     * @return list<RefundCandidate>
     */
    public function findRefundCandidates(string $sourceModule, int $receivableId, \DateTimeImmutable $since): array;

    /**
     * Records one candidate debit as the refund of this receivable's
     * surplus — its whole amount, as a person's decision that the
     * automatic matching never undoes.
     *
     * **Everything findRefundCandidates() filters on is checked again**,
     * under a lock on the receivable and on the movement: a list shown a
     * minute ago is not a promise. The debit must still be on the
     * receivable's account, booked on or after $since, reconciled with
     * nothing, and no larger than what is still owed back; two people
     * pointing at two debits at once cannot together refund more than the
     * surplus, and one debit cannot become the refund of two receivables.
     *
     * $actorUserAccountId is who decided (`user_accounts.id`), kept on the
     * allocation like any manual one.
     *
     * @throws \Modules\Finance\Api\FinanceException with a French sentence
     *         when the receivable is not this module's, or when the debit
     *         is no longer a candidate.
     */
    public function reconcileRefund(
        string $sourceModule,
        int $receivableId,
        int $transactionId,
        \DateTimeImmutable $since,
        ?int $actorUserAccountId
    ): void;

    // ── the incoming side (chantier #980, §2.6) ─────────────────────────

    /**
     * Credits that could be a payment of this receivable, for the person
     * who knows a transfer arrived without its communication — a renter's
     * insurer settling a damage: credits on the receivable's own account,
     * of exactly $amountCents, booked on or after $since, reconciled with
     * nothing, oldest first. With $counterpartyContains, only those whose
     * counterparty name contains it, ignoring case and accents (compared
     * after decryption: the name is encrypted at rest).
     *
     * **Never the account's whole list.** The person asking is not a
     * treasurer, and every candidate is somebody's money: the exact amount
     * is mandatory, and a credit is left out when it carries the
     * communication of any receivable (that one is somebody's payment, and
     * the import matches it on its own), when a treasurer declared it no
     * payment for any receivable, or when a person already said it pays
     * nothing towards this one. Each candidate says when, how much, from
     * whom and what the payer wrote, and nothing else (PaymentCandidate).
     *
     * An id that is unknown or another module's is answered with an empty
     * list, as for the refund side.
     *
     * @return list<PaymentCandidate>
     * @throws \Modules\Finance\Api\FinanceException when $amountCents is
     *         not positive.
     */
    public function findPaymentCandidates(
        string $sourceModule,
        int $receivableId,
        int $amountCents,
        ?string $counterpartyContains,
        \DateTimeImmutable $since
    ): array;

    /**
     * Records one candidate credit as a payment of this receivable — **its
     * whole amount, even beyond what the receivable still expects**: the
     * excess reads as an overpayment until the receivable's amount is
     * raised to take it in (a validated final settlement charging the
     * damage the insurer paid). This is the one written exception to « a
     * receivable never absorbs more than it is owed » (ARCHITECTURE.md
     * §8.81, rule 1); it is a person's decision, which the automatic
     * matching never revises.
     *
     * **Everything findPaymentCandidates() filters on is checked again**,
     * under a lock on the receivable and then on the movement: the credit
     * must still be on the receivable's account, of exactly $amountCents,
     * booked on or after $since, carry no receivable's communication and
     * be reconciled with nothing. Two people pointing at the same credit
     * for two receivables at once cannot both have it.
     *
     * $actorUserAccountId is who decided (`user_accounts.id`), handed back
     * as ReceivableMovement::$reconciledBy.
     *
     * @throws \Modules\Finance\Api\FinanceException with a French sentence
     *         when the receivable is not this module's, or when the credit
     *         is no longer a candidate.
     */
    public function reconcilePayment(
        string $sourceModule,
        int $receivableId,
        int $transactionId,
        int $amountCents,
        \DateTimeImmutable $since,
        ?int $actorUserAccountId
    ): void;

    /**
     * Takes a credit a person attached off this receivable again — the
     * undo of reconcilePayment(), for a credit attached by mistake. Only
     * a movement ReceivableMovement::$detachable says so of: a person's
     * attachment of a credit that carries no receivable's communication.
     * Nothing else of the receivable is touched, and the credit becomes a
     * candidate again, for this receivable or another.
     *
     * Checked under the same locks as reconcilePayment(), so detaching
     * twice, or while somebody else does, removes it once. What the
     * consuming module adds to the rule (a settlement that has since
     * counted the payment) is its own to check before calling.
     *
     * @throws \Modules\Finance\Api\FinanceException with a French sentence
     *         when the receivable is not this module's, or when no such
     *         attachment exists on the pair.
     */
    public function detachPayment(string $sourceModule, int $receivableId, int $transactionId): void;
}
