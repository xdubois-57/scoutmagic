<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Member\Household\HouseholdService;
use Core\Member\MemberService;
use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * What the automatic matching could not settle on its own — four
 * situations, four different gestures, and one screen.
 *
 * **À répartir** — one transfer covering several receivables of the same
 * household. This is the most frequent case by far: the site asks for one
 * transfer per receivable, and a share of families pays in one go anyway.
 * The site knows the household, its receivables and their total, so it
 * proposes the split and a human confirms.
 *
 * **Non imputés** — a credit carrying a valid structured communication
 * that names no receivable anywhere. Attach it to a receivable, or declare
 * it no payment for a receivable at all — persisted, so it does not come
 * back. A credit with no valid structured communication is an ordinary
 * movement and is not listed here at all (issue #837).
 *
 * **Trop-perçus** — centred on the RECEIVABLE, never on the transaction:
 * "60,00 € reçus pour 45,00 € dus", with the instalments underneath.
 * Two instalments of 30 € for a receivable of 45 € show an excess on
 * neither one taken alone.
 *
 * **Mauvais compte** — two symmetric signals, and **no allocation across
 * accounts**. The money has to physically arrive on the right account:
 * imputing at a distance would mark a receivable settled while the
 * account concerned received nothing, and that section's books would say
 * something untrue. So there is no gesture here at all — only the two
 * signals, which disappear when the transfer is made and both accounts
 * re-imported.
 *
 * The four are mutually exclusive: one situation, one tab, decided in a
 * fixed order (build()), and every row has a way out, so the screen's
 * resting state is empty.
 *
 * **A deliberate exception to the account partition** (§8.69) lives in
 * that last tab, and it is minimal: each side learns that a movement
 * exists elsewhere — its date, its amount, the account's name — and
 * nothing else. No label, no counterparty, no communication of the other
 * account's own receivables. Without it the treasurer still waiting would
 * chase a family that has already paid. Documented as an exception on
 * purpose, so that nobody closes it later believing they are plugging a
 * leak.
 */
class ReconciliationService
{
    public function __construct(
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationRepository $allocations,
        private TransactionRepository $transactions,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility,
        private ReceivableAllocationService $allocationService,
        private MemberService $members,
        private HouseholdService $households
    ) {
    }

    /**
     * How many situations on this account are waiting for a treasurer's
     * hand — the four tabs of the reconciliation screen, added up.
     *
     * Deliberately a call to build() rather than four cheaper COUNT
     * queries: a tile saying "3 à traiter" over a screen showing four
     * rows is worse than no tile, and two ways of counting the same
     * thing are two answers waiting to disagree. Everything build()
     * reads is per-account and already read by the reconciliation pass
     * it triggers, so this costs one pass, not a second data model.
     *
     * @return array<string, int> the same `counts` shape build() returns,
     *         so a caller can both total it and name what is in it
     * @throws FinanceException when the account is unknown or out of reach
     */
    public function pendingCounts(int $accountId, int $scoutYearId, Role $viewerRole): array
    {
        return $this->build($accountId, $scoutYearId, $viewerRole)['counts'];
    }

    /**
     * Everything the reconciliation screen shows for one account.
     *
     * @return array{
     *     account: Account,
     *     split: array<int, array<string, mixed>>,
     *     orphans: array<int, array<string, mixed>>,
     *     overpaid: array<int, array<string, mixed>>,
     *     cross_account: array{
     *         received_here: array<int, array<string, mixed>>,
     *         paid_elsewhere: array<int, array<string, mixed>>
     *     },
     *     counts: array<string, int>
     * }
     * @throws FinanceException when the account is unknown or out of reach
     */
    public function build(int $accountId, int $scoutYearId, Role $viewerRole): array
    {
        $account = $this->accountRepository->findById($accountId);
        if (!$this->accountVisibility->isVisibleTo($account, $viewerRole)) {
            throw new FinanceException("Ce compte n'existe pas ou ne vous est pas accessible.");
        }
        \assert($account !== null);

        // The allocations have to be up to date before anything here is
        // read, or the screen would offer to fix what the automatic pass
        // was about to settle by itself.
        $this->allocationService->reconcileAccount($account->id);

        $receivables = $this->receivables->findByAccountId($account->id);
        $settlements = $this->allocationService->settlementsFor($receivables);
        $credits = array_values(array_filter(
            $this->transactions->findByAccountId($account->id),
            static fn(Transaction $transaction): bool => $transaction->amount > 0
        ));

        $allocationsByTransaction = $this->allocations->findByTransactionIds(
            array_map(static fn(Transaction $transaction): int => $transaction->id, $credits)
        );

        $byCommunication = [];
        foreach ($receivables as $receivable) {
            $byCommunication[self::digitsOnly($receivable->communication)] = $receivable;
        }

        $identities = [];
        foreach ($this->members->findDirectoryForYear($scoutYearId) as $entry) {
            $identities[$entry->memberId] = $entry;
        }
        $householdByMemberId = $this->householdIndex($scoutYearId);

        // Every receivable of every OTHER account, by communication: what
        // turns an unallocated credit here into « Mauvais compte ».
        $accountNames = [];
        $elsewhereByCommunication = [];
        foreach ($this->accountRepository->findAllOrdered() as $other) {
            $accountNames[$other->id] = $other->name;
            if ($other->id === $account->id) {
                continue;
            }
            foreach ($this->receivables->findByAccountId($other->id) as $receivable) {
                $elsewhereByCommunication[self::digitsOnly($receivable->communication)] = $other->id;
            }
        }

        // One credit, one tab at most, decided in a fixed order (issue
        // #837): a credit that already reached a receivable here is a
        // split or nothing; one naming another account's receivable is
        // « Mauvais compte »; one naming a receivable of this account
        // the automatic pass could not fill is that receivable's
        // trop-perçu; only a valid communication naming nothing at all is
        // « Non imputé ». A credit carrying no valid communication is an
        // ordinary movement and stays on « Mouvements ».
        $split = [];
        $splitReceivableIds = [];
        $orphans = [];
        $receivedHere = [];

        foreach ($credits as $credit) {
            $allocated = 0;
            $allocatedReceivableIds = [];
            foreach ($allocationsByTransaction[$credit->id] ?? [] as $allocation) {
                if ($allocation->amountCents > 0) {
                    $allocated += $allocation->amountCents;
                    $allocatedReceivableIds[] = $allocation->receivableId;
                }
            }

            $remainder = self::toCents($credit->amount) - $allocated;
            if ($remainder <= 0) {
                continue;
            }

            if ($allocatedReceivableIds !== []) {
                $proposal = $this->splitProposal(
                    $credit,
                    $remainder,
                    $allocatedReceivableIds,
                    $receivables,
                    $settlements,
                    $householdByMemberId,
                    $identities
                );
                if ($proposal !== null) {
                    $split[] = $proposal;
                    $splitReceivableIds[$proposal['named_receivable_id']] = true;
                }
                continue;
            }

            // A communication naming a known receivable counts whatever
            // its check digits (a receivable from before the invariant may
            // carry an invalid one); a communication naming nothing counts
            // only when it is a valid one — anything else is free text.
            $elsewhere = null;
            $namesOneHere = false;
            $carriesAValidOne = false;
            foreach ($this->communicationsOf($credit) as $communication) {
                $namesOneHere = $namesOneHere || isset($byCommunication[$communication]);
                $elsewhere ??= isset($elsewhereByCommunication[$communication]) ? $communication : null;
                $carriesAValidOne = $carriesAValidOne || StructuredCommunicationService::isValid($communication);
            }

            if ($elsewhere !== null && !$namesOneHere) {
                $receivedHere[] = [
                    'transaction_id' => $credit->id,
                    'date' => $credit->transactionDate,
                    'amount_cents' => $remainder,
                    'target_account' => $accountNames[$elsewhereByCommunication[$elsewhere]] ?? '',
                    'communication' => self::format($elsewhere),
                ];
                continue;
            }

            if (!$carriesAValidOne || $namesOneHere || $credit->notAReceivableAt !== null) {
                continue;
            }

            $orphans[] = $this->orphanRow($credit, $remainder);
        }

        $overpaid = [];
        foreach ($receivables as $receivable) {
            $settlement = $settlements[$receivable->id] ?? null;
            if ($settlement === null || $settlement->amountOverpaidCents <= 0) {
                continue;
            }
            // The surplus a split proposal is about to place elsewhere is
            // not a trop-perçu yet: confirming the split is the answer.
            if (isset($splitReceivableIds[$receivable->id])) {
                continue;
            }
            $overpaid[] = $this->overpaidRow(
                $receivable,
                $settlement,
                $identities,
                $householdByMemberId,
                $receivables,
                $settlements
            );
        }

        $crossAccount = [
            'received_here' => $receivedHere,
            'paid_elsewhere' => $this->paidElsewhere($account, $receivables, $settlements, $identities),
        ];

        return [
            'account' => $account,
            'split' => $split,
            'orphans' => $orphans,
            'overpaid' => $overpaid,
            'cross_account' => $crossAccount,
            'counts' => [
                'split' => count($split),
                'orphans' => count($orphans),
                'overpaid' => count($overpaid),
                'cross_account' => count($crossAccount['received_here']) + count($crossAccount['paid_elsewhere']),
            ],
        ];
    }

    /**
     * « Ce paiement ne correspond pas à une créance ScoutMagic » — the
     * second way out of « Non imputés », and a lasting one: the credit
     * stays a credit in « Mouvements », it just stops asking.
     *
     * @throws FinanceException when the movement is unknown, out of reach,
     *         or not a credit
     */
    public function declareNotAReceivable(int $transactionId, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $transaction = $this->transactions->findById($transactionId);
        $account = $transaction !== null ? $this->accountRepository->findById($transaction->accountId) : null;
        if ($transaction === null || !$this->accountVisibility->isVisibleTo($account, $viewerRole)) {
            throw new FinanceException("Ce mouvement n'existe pas ou ne vous est pas accessible.");
        }
        if ($transaction->amount <= 0) {
            throw new FinanceException("Ce mouvement est un débit : il n'attend aucune créance.");
        }

        $this->transactions->markNotAReceivable($transaction->id, $actorUserAccountId);
    }

    // ── à répartir ──────────────────────────────────────────────────────

    /**
     * A transfer that named one receivable and carried more than it owed,
     * with other receivables of the same household still open. The
     * proposal fills them in the order the household lists them, never
     * beyond what each still needs, and never beyond what is left of the
     * transfer.
     *
     * Returns null when the leftover has nowhere sensible to go — that is
     * not a split, it is a trop-perçu, and it belongs in the other tab.
     *
     * @param int[] $allocatedReceivableIds
     * @param ExpectedReceivable[] $receivables
     * @param array<int, ReceivableSettlement> $settlements
     * @param array<int, string> $householdByMemberId
     * @param array<int, \Core\Member\MemberDirectoryEntry> $identities
     * @return ?array<string, mixed>
     */
    private function splitProposal(
        Transaction $credit,
        int $remainder,
        array $allocatedReceivableIds,
        array $receivables,
        array $settlements,
        array $householdByMemberId,
        array $identities
    ): ?array {
        $named = null;
        foreach ($receivables as $receivable) {
            if (in_array($receivable->id, $allocatedReceivableIds, true)) {
                $named = $receivable;
                break;
            }
        }
        if ($named === null || $named->memberId === null) {
            return null;
        }

        $household = $householdByMemberId[$named->memberId] ?? null;
        if ($household === null) {
            return null;
        }

        $left = $remainder;
        $lines = [];
        foreach ($receivables as $receivable) {
            if ($left <= 0) {
                break;
            }
            if ($receivable->id === $named->id || $receivable->memberId === null) {
                continue;
            }
            if (($householdByMemberId[$receivable->memberId] ?? null) !== $household) {
                continue;
            }

            $settlement = $settlements[$receivable->id] ?? null;
            if ($settlement === null || $settlement->isWaived()) {
                continue;
            }
            $missing = $settlement->amountRemainingCents();
            if ($missing <= 0) {
                continue;
            }

            $share = min($missing, $left);
            $left -= $share;
            $lines[] = [
                'receivable_id' => $receivable->id,
                'member_id' => $receivable->memberId,
                'name' => $this->displayName($receivable->memberId, $identities),
                'section' => $identities[$receivable->memberId]->sectionName ?? null,
                'amount_cents' => $share,
                'remaining_cents' => $missing,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return [
            'transaction_id' => $credit->id,
            'date' => $credit->transactionDate,
            'amount_cents' => self::toCents($credit->amount),
            'remainder_cents' => $remainder,
            'counterparty' => $credit->counterpartyName,
            'label' => $credit->label,
            'named_receivable_id' => $named->id,
            'named_name' => $this->displayName($named->memberId, $identities),
            'named_amount_cents' => $named->amountDueCents,
            'lines' => $lines,
            'unassigned_cents' => $left,
        ];
    }

    // ── non imputés ─────────────────────────────────────────────────────

    /**
     * A credit carrying a valid structured communication that names no
     * receivable on any account. Two ways out, both a treasurer's: attach
     * it by hand, or declare it no payment for a receivable at all
     * (declareNotAReceivable()).
     *
     * @return array<string, mixed>
     */
    private function orphanRow(Transaction $credit, int $remainder): array
    {
        return [
            'transaction_id' => $credit->id,
            'date' => $credit->transactionDate,
            'amount_cents' => $remainder,
            'counterparty' => $credit->counterpartyName,
            'label' => $credit->label,
            'reason' => 'La communication structurée de ce virement ne correspond à aucune créance.',
        ];
    }

    // ── trop-perçus ─────────────────────────────────────────────────────

    /**
     * @param array<int, \Core\Member\MemberDirectoryEntry> $identities
     * @param array<int, string> $householdByMemberId
     * @param ExpectedReceivable[] $receivables
     * @param array<int, ReceivableSettlement> $settlements
     * @return array<string, mixed>
     */
    private function overpaidRow(
        ExpectedReceivable $receivable,
        ReceivableSettlement $settlement,
        array $identities,
        array $householdByMemberId,
        array $receivables,
        array $settlements
    ): array {
        $instalments = [];
        foreach ($this->allocations->findByReceivableId($receivable->id) as $allocation) {
            if ($allocation->amountCents === 0) {
                continue;
            }
            $transaction = $this->transactions->findById($allocation->transactionId);
            if ($transaction === null) {
                continue;
            }
            $instalments[] = [
                'transaction_id' => $transaction->id,
                'date' => $transaction->transactionDate,
                'amount_cents' => self::toCents(abs($transaction->amount)),
                'is_refund' => $allocation->amountCents < 0,
            ];
        }

        // "Impute it on another receivable of the household" is often the
        // right answer: a parent who rounds the amount up means to pay,
        // not to be sent 6,75 € back.
        $siblings = [];
        $household = $receivable->memberId !== null ? ($householdByMemberId[$receivable->memberId] ?? null) : null;
        if ($household !== null) {
            foreach ($receivables as $other) {
                if ($other->id === $receivable->id || $other->memberId === null) {
                    continue;
                }
                if (($householdByMemberId[$other->memberId] ?? null) !== $household) {
                    continue;
                }
                $otherSettlement = $settlements[$other->id] ?? null;
                if ($otherSettlement === null || $otherSettlement->amountRemainingCents() <= 0) {
                    continue;
                }
                $siblings[] = [
                    'receivable_id' => $other->id,
                    'name' => $this->displayName($other->memberId, $identities),
                    'remaining_cents' => $otherSettlement->amountRemainingCents(),
                ];
            }
        }

        return [
            'receivable_id' => $receivable->id,
            'member_id' => $receivable->memberId,
            'name' => $this->displayName($receivable->memberId, $identities),
            'section' => $receivable->memberId !== null
                ? ($identities[$receivable->memberId]->sectionName ?? null)
                : null,
            'amount_due' => $settlement->amountDueCents,
            'amount_received' => $settlement->amountDesignatedCents,
            'amount_overpaid' => $settlement->amountOverpaidCents,
            'refund_state' => $settlement->refundState,
            'instalments' => $instalments,
            'siblings' => $siblings,
        ];
    }

    // ── mauvais compte ──────────────────────────────────────────────────

    /**
     * « Mauvais compte », the half this account cannot see from its own
     * credits: a receivable of THIS account that nobody paid here, whose
     * communication turns up on a credit of another account. The other
     * half, a credit here naming another account's receivable, is decided
     * with the rest of the credits in build().
     *
     * What crosses the boundary is deliberately the smallest thing that
     * makes each side act: a date, an amount, an account name. Never a
     * label, never a counterparty, never the other account's own
     * receivables.
     *
     * @param ExpectedReceivable[] $receivables
     * @param array<int, ReceivableSettlement> $settlements
     * @param array<int, \Core\Member\MemberDirectoryEntry> $identities
     * @return list<array<string, mixed>>
     */
    private function paidElsewhere(Account $account, array $receivables, array $settlements, array $identities): array
    {
        $paidElsewhere = [];
        $unpaidByCommunication = [];
        foreach ($receivables as $receivable) {
            $settlement = $settlements[$receivable->id] ?? null;
            if ($settlement === null || $settlement->status !== ReceivableSettlement::STATUS_UNPAID) {
                continue;
            }
            $unpaidByCommunication[self::digitsOnly($receivable->communication)] = $receivable;
        }

        if ($unpaidByCommunication !== []) {
            foreach ($this->accountRepository->findAllOrdered() as $other) {
                if ($other->id === $account->id) {
                    continue;
                }
                foreach ($this->transactions->findByAccountId($other->id) as $transaction) {
                    if ($transaction->amount <= 0) {
                        continue;
                    }
                    foreach ($this->communicationsOf($transaction) as $communication) {
                        if (!isset($unpaidByCommunication[$communication])) {
                            continue;
                        }
                        $receivable = $unpaidByCommunication[$communication];
                        $paidElsewhere[] = [
                            'receivable_id' => $receivable->id,
                            'name' => $this->displayName($receivable->memberId, $identities),
                            'amount_cents' => self::toCents($transaction->amount),
                            'date' => $transaction->transactionDate,
                            'other_account' => $other->name,
                        ];
                        break;
                    }
                }
            }
        }

        return $paidElsewhere;
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * members.id => the blind index of the household it belongs to. A
     * member at two addresses belongs to two households, and takes the
     * first — which household a payment "is" is not something the site
     * knows, and this only decides which siblings get proposed.
     *
     * @return array<int, string>
     */
    private function householdIndex(int $scoutYearId): array
    {
        $index = [];
        foreach ($this->households->householdsForYear($scoutYearId) as $blindIndex => $household) {
            foreach ($household->members as $member) {
                $index[$member->memberId] ??= $blindIndex;
            }
        }

        return $index;
    }

    /**
     * @param array<int, \Core\Member\MemberDirectoryEntry> $identities
     */
    private function displayName(?int $memberId, array $identities): string
    {
        if ($memberId === null) {
            return '—';
        }
        $entry = $identities[$memberId] ?? null;
        if ($entry === null) {
            return 'Membre #' . $memberId;
        }

        return trim($entry->lastName . ' ' . $entry->firstName);
    }

    /**
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

    private static function format(string $digits): string
    {
        return strlen($digits) === 12
            ? '+++' . substr($digits, 0, 3) . '/' . substr($digits, 3, 4) . '/' . substr($digits, 7, 5) . '+++'
            : $digits;
    }

    private static function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
