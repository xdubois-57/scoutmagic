<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Exception\UserFacingMessage;
use Modules\Rental\Support;

use Core\Journal\JournalService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalPaymentRepository;

/**
 * The **only** place `rental` touches money (§6.19, §6.20).
 *
 * Everything here goes through Finance's four public interfaces
 * (ARCHITECTURE.md §7.5) and never near its tables. All four are
 * **nullable**: with Finance disabled — or simply not installed — every
 * method degrades to "no receivable, no QR, nothing raised", and the rest of
 * the module carries on unchanged. A unit that settles its rentals by hand
 * is a perfectly normal unit, and it must not meet a single error page.
 *
 * **One receivable for the whole booking** (§6.19, issue #927): the rental
 * price plus the security deposit, under one structured communication used
 * for the deposit, the balance, the security deposit and any complement.
 * The deposit is a *threshold* on it — "deposit received" means
 * `amount_received >= deposit_amount` — and so is the rest: the receipts
 * are compared, cumulatively, to the deposit and then to the total, and
 * never split between the rental and the security deposit on screen. Two
 * receivables meant two communications, and a renter paying with the wrong
 * one settled the wrong debt.
 *
 * **Raised before the first contract is generated**, so the contract prints
 * the real communication (`communicationForContract()`), and reused for
 * every regeneration, version or modification after it. Its amount then
 * follows the booking: the direct changes made before the first send, the
 * countersigned modification after it (`updateRentalAmount()`), **0** when
 * the booking is refused, cancelled or expires (`abandonAmount()`) and the
 * kept amount once the final settlement is validated (`settleAmount()`).
 * It is never deleted outside the retention purge (`forgetBooking()`):
 * whatever came in for a booking that ended shows as an overpayment, which
 * is what it is.
 *
 * Journal entries carry the reference and the ids, never the renter
 * (SECURITY.md §5).
 */
class RentalPaymentService
{
    /** What Finance records as the origin of these receivables. */
    public const SOURCE_MODULE = 'rental';

    public function __construct(
        private RentalPaymentRepository $paymentRepository,
        private BookingAudit $bookingAudit,
        private JournalService $journal,
        private ?ExpectedReceivableInterface $receivables = null,
        private ?StructuredCommunicationInterface $communications = null,
        private ?SepaQrCodeInterface $sepaQr = null,
        private ?FinanceAccountInterface $accounts = null
    ) {
    }

    /**
     * Whether Finance is available at all — what the configuration screen
     * asks before offering an account picker, and what every screen asks
     * before showing a payment section.
     */
    public function isAvailable(): bool
    {
        return $this->receivables !== null && $this->communications !== null;
    }

    /**
     * Finance's accounts, for the asset's account picker. Empty when
     * Finance is off, which the template renders as an explanation rather
     * than an empty select.
     *
     * @return array<int, array{id: int, name: string, iban: ?string, holder_name: ?string, section_id: ?int}>
     */
    public function availableAccounts(): array
    {
        return $this->accounts?->getConfiguredAccounts() ?? [];
    }

    public function settingsFor(int $assetId): PaymentSettings
    {
        return $this->paymentRepository->loadSettings($assetId);
    }

    /**
     * @throws RentalException
     */
    public function saveSettings(int $assetId, PaymentSettings $settings): void
    {
        if ($settings->enabled && ($settings->financeAccountId === null || $settings->financeCategoryId === null)) {
            // Half-configured is worse than off: an asset that says
            // "payments on" but can raise nothing looks like it is working.
            //
            // The message names the page and the person, because whoever
            // reads it usually cannot fix it themselves: the account is
            // pinned by unit staff, while this switch belongs to the asset's
            // managers (Controller\RentalConfigController::saveFinanceAccount()).
            throw new RentalException(
                'Aucun compte ou aucune catégorie Finances n\'est encore associé à ce bien : demandez à un '
                . 'chef d\'unité de les choisir depuis « Espace chefs d\'U > Locations ».'
            );
        }

        if ($settings->depositPercentage !== null
            && ($settings->depositPercentage < 0 || $settings->depositPercentage > 100)) {
            throw new RentalException('Un acompte en pourcentage doit être compris entre 0 et 100.');
        }

        foreach ([$settings->depositAmountCents, $settings->securityDepositAmountCents] as $amount) {
            if ($amount !== null && $amount < 0) {
                throw new RentalException('Un montant ne peut pas être négatif.');
            }
        }

        $this->paymentRepository->saveSettings($assetId, $settings);

        $this->journal->log(
            'rental',
            'rental_payment_settings_saved',
            'info',
            'Configuration des paiements enregistrée',
            ['asset_id' => $assetId, 'payments_enabled' => $settings->enabled]
        );
    }

    /**
     * Pin the Finance account this asset's money is expected on, **without
     * touching anything else** — the unit-staff half of the split described
     * in Controller\RentalConfigController::saveFinanceAccount().
     *
     * Reads the stored settings back and replaces one field, rather than
     * taking a whole PaymentSettings: the two screens that write this row
     * own disjoint field sets and neither may blank the other's work. That
     * is the same "every section saves independently" rule the
     * configuration page has always followed, applied across two pages
     * instead of within one.
     *
     * @throws RentalException
     */
    public function saveFinanceAccount(int $assetId, ?int $financeAccountId, ?int $financeCategoryId = null): void
    {
        // Every receivable names its category (issue #838), so an account
        // without one could raise nothing — refused here, where unit staff
        // can still fix it, rather than when a renter is confirmed.
        if ($financeAccountId !== null && $financeCategoryId === null) {
            throw new RentalException(
                'Choisissez aussi la catégorie Finances dans laquelle classer les paiements de ce bien.'
            );
        }

        $current = $this->paymentRepository->loadSettings($assetId);

        $this->saveSettings($assetId, $current->withFinanceAccount($financeAccountId, $financeCategoryId));
    }

    /**
     * Finance's active categories, for the picker beside the account one.
     *
     * @return list<array{id: int, name: string}>
     */
    public function availableCategories(): array
    {
        return $this->accounts?->getCategories() ?? [];
    }

    /**
     * Save everything about the money **except** which account it lands on —
     * the manager half of the same split. The stored account is read back
     * and carried across untouched, so a manager who never sees the picker
     * can never clear it either.
     *
     * @throws RentalException
     */
    public function saveManagedSettings(int $assetId, PaymentSettings $settings): void
    {
        $current = $this->paymentRepository->loadSettings($assetId);

        $this->saveSettings(
            $assetId,
            $settings->withFinanceAccount($current->financeAccountId, $current->financeCategoryId)
        );
    }

    // ── The booking's receivable ────────────────────────────────────────

    /**
     * Raises the booking's single receivable — rental price plus security
     * deposit — or returns the one already raised.
     *
     * **Idempotent, and reuse never moves anything**: called again on a
     * booking that already has a receivable, it returns that one untouched.
     * A second communication would orphan every transfer the renter already
     * made, and the amount is kept in step by the gestures that change it
     * (`updateRentalAmount()`, `abandonAmount()`, `settleAmount()`), not by
     * whoever happens to ask for the receivable next.
     *
     * Returns null, raising nothing, when Finance is unavailable or the
     * asset has payments off. That is the nullable dependency working, not
     * a failure.
     *
     * @throws RentalException when payments are on but nothing could be
     *   raised: no price yet, or Finance refused.
     */
    public function ensureReceivable(
        RentalBooking $booking,
        PaymentSettings $settings,
        ?int $actorMemberId = null
    ): ?int {
        if (!$this->isAvailable() || !$settings->canRaiseReceivables()) {
            return null;
        }

        // Under the asset's lock, re-reading the link inside it: two
        // managers generating the first contract together would otherwise
        // both read « none yet », both raise one, and the link would keep
        // only the last — one contract printing the communication of a
        // receivable the booking no longer points at. Re-entrant, so a
        // caller already holding the lock (confirmation) simply runs.
        return $this->paymentRepository->withAssetLocked(
            $booking->assetId,
            fn(): ?int => $this->raiseOrReuse($booking, $settings)
        );
    }

    /**
     * `ensureReceivable()`'s body, run under the asset's lock.
     *
     * @throws RentalException
     */
    private function raiseOrReuse(RentalBooking $booking, PaymentSettings $settings): ?int
    {
        $existing = $this->paymentRepository->loadBookingPayment($booking->id) ?? [];
        $receivableId = $existing['rental_receivable_id'] ?? null;
        if (is_int($receivableId)) {
            return $receivableId;
        }

        $rentalCents = $booking->effectiveTotalCents();
        if ($rentalCents === null) {
            throw new RentalException(
                "Cette réservation n'a pas encore de prix : impossible de demander un paiement."
            );
        }

        $securityCents = $settings->securityDepositAmount();
        $totalCents = $rentalCents + ($securityCents ?? 0);

        $communication = $this->communications?->generate();
        if ($communication === null) {
            return null;
        }

        try {
            $receivableId = $this->receivables?->createReceivable(
                self::SOURCE_MODULE,
                $booking->id,
                (int) $settings->financeAccountId,
                $totalCents,
                $communication,
                // The label may identify the payer, and Finance encrypts it
                // for exactly that reason — so the reference alone would be
                // needlessly opaque to whoever reconciles the account.
                $booking->reference . ' — ' . $booking->renterName,
                (int) $settings->financeCategoryId
            );
        } catch (\Throwable $e) {
            // Finance's own refusals are written for the person in front of
            // this booking and must survive; anything else is about its
            // internals and has nothing to say here. UserFacingMessage
            // decides which from the class alone, without catching
            // Finance\Api\FinanceException by name (ARCHITECTURE.md §7.5).
            throw new RentalException(
                UserFacingMessage::from(
                    $e,
                    "La créance de cette location n'a pas pu être créée dans les Finances — "
                    . 'vérifiez le compte financier configuré pour les locations, puis réessayez.'
                ),
                0,
                $e
            );
        }

        if ($receivableId === null) {
            return null;
        }

        $this->paymentRepository->linkReceivable(
            $booking->id,
            $receivableId,
            $communication,
            $settings->depositFor($rentalCents),
            $securityCents
        );

        $this->journal->log(
            'rental',
            'rental_receivable_created',
            'info',
            'Créance de location créée pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'amount_cents' => $totalCents]
        );

        return $receivableId;
    }

    /**
     * The structured communication a contract about to be generated prints
     * — raising the receivable first if it does not exist yet (issue #927).
     *
     * Null only in the explicit degraded mode: Finance not available, or
     * payments off on this asset. Then the contract prints « — » and the
     * Finances page says the payments are followed outside the site.
     *
     * **Payments on and no communication is never an outcome**: the
     * generation is refused with the reason instead, because a contract
     * that went out without a communication cannot be corrected afterwards
     * — the renter pays against what they were sent.
     *
     * @throws RentalException
     */
    public function communicationForContract(
        RentalBooking $booking,
        PaymentSettings $settings,
        ?int $actorMemberId = null
    ): ?string {
        if (!$this->isAvailable() || !$settings->enabled) {
            return null;
        }

        // A communication already linked is the one every later version
        // prints, whatever has happened to the asset's account or category
        // since: the renter may already have paid against it.
        $linked = $this->paymentRepository->loadBookingPayment($booking->id);
        if (($linked['rental_receivable_id'] ?? null) !== null
            && is_string($linked['rental_communication'] ?? null)
            && $linked['rental_communication'] !== ''
        ) {
            return $linked['rental_communication'];
        }

        $refusal = 'Le contrat n\'a pas été généré : un contrat ne part jamais sans la communication '
            . 'structurée des paiements, et la créance de cette location n\'a pas pu être créée. ';

        if (!$settings->canRaiseReceivables()) {
            throw new RentalException(
                $refusal . 'Aucun compte ou aucune catégorie Finances n\'est associé à ce bien : demandez à '
                . 'un chef d\'unité de les choisir depuis « Espace chefs d\'U > Locations ».'
            );
        }

        try {
            $receivableId = $this->ensureReceivable($booking, $settings, $actorMemberId);
        } catch (RentalException $e) {
            throw new RentalException($refusal . $e->getMessage(), 0, $e);
        }

        $communication = $receivableId !== null
            ? ($this->paymentRepository->loadBookingPayment($booking->id)['rental_communication'] ?? null)
            : null;
        if ($communication === null || $communication === '') {
            throw new RentalException(
                $refusal . 'Vérifiez le compte financier configuré pour les locations, puis réessayez.'
            );
        }

        return $communication;
    }

    /**
     * Moves the receivable to a new rental price, the security deposit
     * snapshotted with it added on top — **same receivable, same
     * communication, every match kept**.
     *
     * The method a changed price calls: a direct change before the first
     * contract was sent, and the countersignature of a modification after
     * it (issue #927). A modification still being prepared changes nothing
     * here — only its countersignature does. The deposit threshold follows
     * the new price when it is a percentage of it.
     *
     * Refuses to drop below what has already come in unless the caller says
     * it means to create an overpayment — the guard lives in Finance, and
     * this only translates its refusal into the module's own exception.
     * Does nothing when no receivable was ever raised.
     *
     * @throws RentalException
     */
    public function updateRentalAmount(
        RentalBooking $booking,
        PaymentSettings $settings,
        int $rentalTotalCents,
        bool $allowOverpayment = false,
        ?int $actorMemberId = null
    ): void {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id);
        if ($payment === null || $payment['rental_receivable_id'] === null || $this->receivables === null) {
            return;
        }

        $totalCents = $rentalTotalCents + ($payment['security_deposit_amount_cents'] ?? 0);
        $this->moveAmount($payment['rental_receivable_id'], $totalCents, $allowOverpayment);

        $this->paymentRepository->updateDepositAmount($booking->id, $settings->depositFor($rentalTotalCents));

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::PRICE_CHANGED,
            null,
            Support::euros($totalCents),
            'Créance mise à jour',
            $actorMemberId
        );
    }

    /**
     * Brings the receivable to **0** for a booking refused, cancelled or
     * expired (issue #927) — with `allowBelowReceived`, because anything
     * already received is now owed back and must show as an overpayment.
     * The receivable and its communication stay: a transfer still arriving
     * later must find the booking it was meant for.
     *
     * Never throws. The status change it follows has committed and is
     * true; a Finance failure is journaled and written into the booking's
     * history, where whoever opens it will see the amount was not moved.
     */
    public function abandonAmount(RentalBooking $booking, ?int $actorMemberId = null): void
    {
        $this->bringTo($booking, 0, 'Créance ramenée à 0 : la réservation est abandonnée', $actorMemberId);
    }

    /**
     * Brings the receivable to the amount the final settlement keeps —
     * agreed rental, consumptions and withholdings, less any goodwill
     * gesture — once that settlement is validated (issue #927). What was
     * received beyond it, the security deposit to give back first, then
     * shows as an overpayment. The booking's agreed price is not touched:
     * the settlement sits beside it.
     *
     * Never throws, for the reason `abandonAmount()` gives.
     */
    public function settleAmount(RentalBooking $booking, int $keptCents, ?int $actorMemberId = null): void
    {
        $this->bringTo(
            $booking,
            max(0, $keptCents),
            'Créance ramenée au montant conservé par le décompte final',
            $actorMemberId
        );
    }

    /**
     * The first contract went out at $sentAt: the deposit's deadline is
     * set from it (`depositDueDays` after), **once** — a later send, a
     * replacement contract included, never moves it (issue #927).
     *
     * Stored whether or not a receivable exists yet, so a booking whose
     * payments are switched on later still knows when its deposit was due.
     */
    public function contractSent(RentalBooking $booking, PaymentSettings $settings, \DateTimeImmutable $sentAt): void
    {
        $due = $settings->depositDueDate($sentAt);
        if ($due === null) {
            return;
        }

        $this->paymentRepository->setDepositDueDateIfUnset($booking->id, $due);
    }

    // ── Reading where the money is ──────────────────────────────────────

    /**
     * What is owed, what has arrived, and which stage that puts the booking
     * at (§6.19).
     *
     * Every stage is a **threshold on the cumulative receipts**, compared
     * live — the deposit first, then the total, the security deposit
     * included — rather than a stored flag: it can never disagree with the
     * bank statement, and no scheduled task has to keep it in step. Nothing
     * is split between the rental and the security deposit: one
     * communication, one running total.
     *
     * `total_cents` is what the receivable expects — rental plus security
     * deposit, 0 once the booking was abandoned, the kept amount after a
     * validated settlement — and `overpaid_cents` what came in beyond it.
     *
     * @return array{
     *   available: bool, enabled: bool,
     *   total_cents: ?int, rental_cents: ?int, received_cents: int, remaining_cents: int, overpaid_cents: int,
     *   deposit_cents: ?int, deposit_received: bool, fully_paid: bool,
     *   communication: ?string, deposit_due_date: ?string, balance_due_date: ?string,
     *   security_deposit: array{
     *     status: SecurityDepositStatus, amount_cents: ?int, received_cents: int, due_date: ?string,
     *     returned_cents: ?int, withheld_cents: ?int, returned_at: ?string, note: ?string
     *   }
     * }
     */
    public function statusFor(RentalBooking $booking, PaymentSettings $settings): array
    {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id) ?? [];
        $depositStatus = $payment['security_deposit_status'] ?? SecurityDepositStatus::NONE;
        $receivableId = $payment['rental_receivable_id'] ?? null;

        $rentalCents = $booking->effectiveTotalCents();
        // The snapshot once a receivable exists, the asset's setting until
        // then: a deposit changed on the asset afterwards does not rewrite
        // what a renter was asked.
        $securityAmountCents = $receivableId !== null
            ? ($payment['security_deposit_amount_cents'] ?? null)
            : $settings->securityDepositAmount();

        if ($receivableId !== null) {
            $amounts = $this->receivableAmounts($receivableId);
            $totalCents = $amounts['amount_due'];
            $receivedCents = $amounts['amount_received'];
        } else {
            $totalCents = $rentalCents !== null ? $rentalCents + ($securityAmountCents ?? 0) : null;
            $receivedCents = 0;
        }

        $depositCents = $payment['deposit_amount_cents'] ?? null;
        $fullyPaid = $totalCents !== null && $totalCents > 0 && $receivedCents >= $totalCents;

        // "Reçue" is DERIVED from the account, like `deposit_received`: the
        // security deposit is in hand once the AGREED total — rental plus
        // deposit — has come in, since it is due with the balance and
        // nothing splits the receipts. Against the agreed total, never the
        // receivable's live amount: an abandoned booking's receivable is at
        // 0 and a settled one's at the kept amount, and neither gives the
        // renter's deposit back. Only the outstanding state is derived —
        // once it was recorded held (abandonAmount(), settleAmount()) or a
        // manager moved it on, what was recorded stands.
        if ($depositStatus === SecurityDepositStatus::TO_RECEIVE
            && self::agreedTotalReached($rentalCents, $securityAmountCents, $receivedCents)
        ) {
            $depositStatus = SecurityDepositStatus::RECEIVED;
        }
        $depositHeld = $depositStatus->isHeld() || $depositStatus->isSettled();

        $balanceDueDate = $settings->balanceDueDate($booking->arrivalDate);

        return [
            'available' => $this->isAvailable(),
            'enabled' => $settings->enabled,
            'total_cents' => $totalCents,
            'rental_cents' => $rentalCents,
            'received_cents' => $receivedCents,
            'remaining_cents' => max(0, ($totalCents ?? 0) - $receivedCents),
            'overpaid_cents' => $totalCents !== null ? max(0, $receivedCents - $totalCents) : 0,
            'deposit_cents' => $depositCents,
            // The threshold, compared live. Never a stored "deposit paid"
            // flag: that would spend part of its life disagreeing with the
            // account.
            'deposit_received' => $depositCents !== null && $receivedCents >= $depositCents,
            'fully_paid' => $fullyPaid,
            'communication' => $payment['rental_communication'] ?? null,
            'deposit_due_date' => $payment['deposit_due_date'] ?? null,
            'balance_due_date' => $balanceDueDate,
            'security_deposit' => [
                'status' => $depositStatus,
                'amount_cents' => $securityAmountCents,
                // All or nothing, from the same threshold as the balance:
                // never an allocation of the receipts between two debts.
                'received_cents' => $depositHeld ? (int) ($securityAmountCents ?? 0) : 0,
                'due_date' => $securityAmountCents !== null ? $balanceDueDate : null,
                'returned_cents' => $payment['security_deposit_returned_cents'] ?? null,
                'withheld_cents' => $payment['security_deposit_withheld_cents'] ?? null,
                'returned_at' => $payment['security_deposit_returned_at'] ?? null,
                'note' => $payment['security_deposit_note'] ?? null,
            ],
        ];
    }

    /**
     * A SEPA QR for the amount that is actually due **at this stage**: the
     * deposit while it is outstanding, the remaining balance — security
     * deposit included — afterwards (§6.19).
     *
     * Null whenever anything needed is missing — Finance off, no account,
     * no IBAN, nothing left to pay. The caller renders nothing rather than
     * a broken image.
     */
    public function sepaQrPng(RentalBooking $booking, PaymentSettings $settings): ?string
    {
        if ($this->sepaQr === null || !$settings->canRaiseReceivables()) {
            return null;
        }

        $status = $this->statusFor($booking, $settings);
        $communication = $status['communication'];
        if ($communication === null) {
            return null;
        }

        $amount = $status['deposit_cents'] !== null && !$status['deposit_received']
            ? $status['deposit_cents'] - $status['received_cents']
            : $status['remaining_cents'];

        if ($amount <= 0) {
            return null;
        }

        $account = $this->accountById($settings->financeAccountId);
        if ($account === null || ($account['iban'] ?? null) === null) {
            return null;
        }

        return $this->sepaQr->generatePng(
            (string) ($account['holder_name'] ?? $account['name']),
            (string) $account['iban'],
            null,
            $amount,
            $communication
        );
    }

    // ── The security deposit's manual half (§6.20) ──────────────────────

    /**
     * Marks a held deposit as one to give back — typically once the
     * departure inventory is done.
     */
    public function markSecurityDepositToReturn(RentalBooking $booking, ?int $actorMemberId = null): void
    {
        $this->paymentRepository->setSecurityDepositStatus($booking->id, SecurityDepositStatus::TO_RETURN);
        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            SecurityDepositStatus::TO_RETURN->label(),
            'Caution',
            $actorMemberId
        );
    }

    /**
     * Records what was actually given back.
     *
     * **By hand, and that is the design** (§6.20): Finance reconciles money
     * coming in, not money going out, so no bank statement can confirm this.
     * The limitation is documented rather than papered over with a feature
     * that would only look like it worked.
     *
     * The resulting status is derived from the amounts rather than picked
     * from a dropdown — a manager who typed them has already said what
     * happened, and asking them to also choose the label is how the two end
     * up disagreeing.
     *
     * @throws RentalException
     */
    public function recordSecurityDepositReturn(
        RentalBooking $booking,
        int $returnedCents,
        ?string $note,
        \DateTimeImmutable $returnedAt,
        ?int $actorMemberId = null
    ): void {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id);
        $amount = $payment['security_deposit_amount_cents'] ?? null;

        if ($amount === null || $amount <= 0) {
            throw new RentalException("Cette réservation n'a pas de caution à restituer.");
        }

        if ($returnedCents < 0 || $returnedCents > $amount) {
            throw new RentalException(
                'Le montant restitué doit être compris entre 0 et ' . Support::euros($amount) . '.'
            );
        }

        $withheld = $amount - $returnedCents;
        $status = SecurityDepositStatus::afterReturn($amount, $returnedCents);

        if ($withheld > 0 && ($note === null || trim($note) === '')) {
            // Withholding somebody's money without a written reason is not
            // something to allow quietly: the renter is entitled to know
            // why, and six months later nobody remembers.
            throw new RentalException('Indiquez pourquoi une partie de la caution est retenue.');
        }

        $this->paymentRepository->recordSecurityDepositReturn(
            $booking->id,
            $status,
            $returnedCents,
            $withheld,
            $returnedAt->format('Y-m-d'),
            $note
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $status->label(),
            'Caution : ' . Support::euros($returnedCents) . ' restitués',
            $actorMemberId
        );

        // Amounts and ids only — the note is encrypted precisely because it
        // is about a renter's group, and must not be copied into the
        // journal in clear (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_security_deposit_returned',
            'info',
            'Caution traitée pour ' . $booking->reference,
            [
                'booking_id' => $booking->id,
                'returned_cents' => $returnedCents,
                'withheld_cents' => $withheld,
            ]
        );
    }

    /**
     * Drops every receivable this booking raised — called when a booking is
     * deleted, so Finance is not left expecting money for a rental that no
     * longer exists.
     */
    public function forgetBooking(int $bookingId): void
    {
        $this->receivables?->deleteReceivablesForSource(self::SOURCE_MODULE, $bookingId);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * @throws RentalException
     */
    private function moveAmount(int $receivableId, int $totalCents, bool $allowOverpayment): void
    {
        try {
            $this->receivables?->updateReceivableAmount($receivableId, $totalCents, $allowOverpayment);
        } catch (\Throwable $e) {
            // Finance's own refusals are written for the person in front of
            // this booking — « Le nouveau montant (…) est inférieur à ce qui
            // a déjà été reçu (…). Cela créerait un trop-perçu à rembourser »
            // — and that sentence is the only thing that explains the
            // refusal, so it must survive. Anything else Finance throws is
            // about its internals and has nothing to say here.
            throw new RentalException(
                UserFacingMessage::from(
                    $e,
                    "Le montant à percevoir n'a pas pu être mis à jour dans les Finances — "
                    . 'vérifiez le compte financier configuré pour les locations, puis réessayez.'
                ),
                0,
                $e
            );
        }
    }

    /**
     * Sets the receivable to $amountCents, accepting an overpayment, and
     * swallows a failure into the journal and the booking's history — the
     * shared body of `abandonAmount()` and `settleAmount()`.
     */
    private function bringTo(RentalBooking $booking, int $amountCents, string $summary, ?int $actorMemberId): void
    {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id);
        $receivableId = $payment['rental_receivable_id'] ?? null;
        if ($payment === null || $receivableId === null || $this->receivables === null) {
            return;
        }

        // A deposit fully paid is recorded as held BEFORE the amount moves:
        // afterwards the receivable no longer says what was asked, and what
        // Finance reports as received for a receivable at 0 is not
        // something « Reçue » may depend on.
        if ($payment['security_deposit_status'] === SecurityDepositStatus::TO_RECEIVE
            && self::agreedTotalReached(
                $booking->effectiveTotalCents(),
                $payment['security_deposit_amount_cents'],
                $this->receivableAmounts($receivableId)['amount_received']
            )
        ) {
            $this->paymentRepository->setSecurityDepositStatus($booking->id, SecurityDepositStatus::RECEIVED);
        }

        try {
            $this->moveAmount($receivableId, $amountCents, true);
        } catch (\Throwable $e) {
            // Ids and amounts only (SECURITY.md §5); the history entry is
            // what tells a manager on the booking itself.
            $this->journal->log(
                'rental',
                'rental_receivable_not_updated',
                'warning',
                'Créance non mise à jour pour ' . $booking->reference . ' : ' . $e->getMessage(),
                ['booking_id' => $booking->id, 'to_cents' => $amountCents]
            );
            $this->bookingAudit->record(
                $booking->id,
                BookingAudit::PRICE_CHANGED,
                null,
                null,
                'Créance non mise à jour dans les Finances : à corriger depuis Finances',
                $actorMemberId
            );

            return;
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::PRICE_CHANGED,
            null,
            Support::euros($amountCents),
            $summary,
            $actorMemberId
        );
    }

    /**
     * Whether the receipts reach what the booking agreed — the rental price
     * plus the security deposit snapshotted with the receivable. Only
     * meaningful with a deposit to hold.
     */
    private static function agreedTotalReached(?int $rentalCents, ?int $securityCents, int $receivedCents): bool
    {
        if ($rentalCents === null || $securityCents === null || $securityCents <= 0) {
            return false;
        }

        return $receivedCents >= $rentalCents + $securityCents;
    }

    /**
     * @return array{amount_due: int, amount_received: int}
     */
    private function receivableAmounts(int $receivableId): array
    {
        $status = $this->receivables?->getReceivableStatus($receivableId);

        return [
            'amount_due' => (int) ($status['amount_due'] ?? 0),
            'amount_received' => (int) ($status['amount_received'] ?? 0),
        ];
    }

    /**
     * @return array{id: int, name: string, iban: ?string, holder_name: ?string, section_id: ?int}|null
     */
    private function accountById(?int $accountId): ?array
    {
        if ($accountId === null) {
            return null;
        }

        foreach ($this->availableAccounts() as $account) {
            if ($account['id'] === $accountId) {
                return $account;
            }
        }

        return null;
    }
}
