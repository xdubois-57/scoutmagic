<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Repository\RentalBookingEventRepository;
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
 * **One receivable for the whole rental** (§6.19). The deposit is a
 * *threshold* on it — "deposit received" means `amount_received >=
 * deposit_amount` — and the deposit and the balance share one structured
 * communication. Two receivables would mean two communications, and a
 * renter paying the balance with the deposit's reference would settle the
 * wrong one.
 *
 * **The security deposit is a different thing entirely** (§6.20): its own
 * receivable, its own communication, and never rental revenue. A unit
 * holding 500 € of somebody else's money has not earned 500 €. Its return
 * is tracked by hand, because Finance reconciles incoming transfers and not
 * outgoing ones — a documented limitation rather than an invented feature.
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
        private RentalBookingEventRepository $eventRepository,
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
        if ($settings->enabled && $settings->financeAccountId === null) {
            // Half-configured is worse than off: an asset that says
            // "payments on" but can raise nothing looks like it is working.
            throw new RentalException(
                'Choisissez le compte sur lequel les paiements de ce bien sont attendus.'
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

    // ── Raising the receivables ─────────────────────────────────────────

    /**
     * Raises (or updates) the rental receivable for a booking, and the
     * security-deposit one if the asset asks for it.
     *
     * Called when a booking is confirmed. **Idempotent**: called again on a
     * booking that already has a receivable, it updates the amount in place
     * rather than minting a second communication — which would orphan every
     * transfer the renter already made.
     *
     * Silently does nothing when Finance is unavailable or the asset has
     * payments off. That is the nullable dependency working, not a failure:
     * there is no receivable to raise, and saying so on screen is the
     * configuration page's job, not this one's.
     *
     * @return array{rental: ?int, security_deposit: ?int} The receivable ids in play.
     * @throws RentalException
     */
    public function ensureReceivables(
        RentalBooking $booking,
        PaymentSettings $settings,
        \DateTimeImmutable $now,
        ?int $actorMemberId = null
    ): array {
        if (!$this->isAvailable() || !$settings->canRaiseReceivables()) {
            return ['rental' => null, 'security_deposit' => null];
        }

        $total = $booking->effectiveTotalCents();
        if ($total === null) {
            throw new RentalException(
                "Cette réservation n'a pas encore de prix : impossible de demander un paiement."
            );
        }

        $existing = $this->paymentRepository->loadBookingPayment($booking->id) ?? [];

        return [
            'rental' => $this->ensureRentalReceivable($booking, $settings, $existing, $total, $now, $actorMemberId),
            'security_deposit' => $this->ensureSecurityDepositReceivable($booking, $settings, $existing, $now),
        ];
    }

    /**
     * Pushes a new total onto an existing rental receivable (§6.12).
     *
     * Refuses to drop below what has already come in unless the caller says
     * it means to create an overpayment — the guard lives in Finance, and
     * this only translates its refusal into the module's own exception so a
     * controller catches one type.
     *
     * @throws RentalException
     */
    public function updateRentalAmount(
        RentalBooking $booking,
        PaymentSettings $settings,
        int $totalCents,
        bool $allowOverpayment = false,
        ?int $actorMemberId = null
    ): void {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id);
        $receivableId = $payment['rental_receivable_id'] ?? null;

        if ($this->receivables === null || $receivableId === null) {
            return;
        }

        try {
            $this->receivables->updateReceivableAmount($receivableId, $totalCents, $allowOverpayment);
        } catch (\Throwable $e) {
            throw new RentalException($e->getMessage(), 0, $e);
        }

        $this->paymentRepository->linkRentalReceivable(
            $booking->id,
            $receivableId,
            (string) ($payment['rental_communication'] ?? ''),
            $settings->depositFor($totalCents),
            $payment['deposit_due_date'] ?? null,
            $payment['balance_due_date'] ?? null
        );

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::PRICE_CHANGED,
            null,
            self::euros($totalCents),
            'Créance mise à jour',
            $actorMemberId
        );
    }

    // ── Reading where the money is ──────────────────────────────────────

    /**
     * What is owed, what has arrived, and which stage that puts the booking
     * at (§6.19).
     *
     * The deposit is a **threshold**, so "acompte reçu" is a comparison
     * rather than a stored flag — it can never disagree with the bank
     * statement, and no scheduled task has to keep it in step.
     *
     * @return array{
     *   available: bool, enabled: bool,
     *   total_cents: ?int, received_cents: int, remaining_cents: int,
     *   deposit_cents: ?int, deposit_received: bool, fully_paid: bool,
     *   communication: ?string, deposit_due_date: ?string, balance_due_date: ?string,
     *   security_deposit: array{
     *     status: SecurityDepositStatus, amount_cents: ?int, received_cents: int,
     *     communication: ?string, due_date: ?string,
     *     returned_cents: ?int, withheld_cents: ?int, returned_at: ?string, note: ?string
     *   }
     * }
     */
    public function statusFor(RentalBooking $booking, PaymentSettings $settings): array
    {
        $payment = $this->paymentRepository->loadBookingPayment($booking->id) ?? [];
        $depositStatus = $payment['security_deposit_status'] ?? SecurityDepositStatus::NONE;

        $totalCents = $payment['rental_receivable_id'] !== null
            ? $this->receivableAmounts($payment['rental_receivable_id'])['amount_due']
            : $booking->effectiveTotalCents();
        $receivedCents = $payment['rental_receivable_id'] !== null
            ? $this->receivableAmounts($payment['rental_receivable_id'])['amount_received']
            : 0;

        $depositCents = $payment['deposit_amount_cents'] ?? null;

        return [
            'available' => $this->isAvailable(),
            'enabled' => $settings->enabled,
            'total_cents' => $totalCents,
            'received_cents' => $receivedCents,
            'remaining_cents' => max(0, ($totalCents ?? 0) - $receivedCents),
            'deposit_cents' => $depositCents,
            // The threshold, compared live. Never a stored "deposit paid"
            // flag: that would spend part of its life disagreeing with the
            // account.
            'deposit_received' => $depositCents !== null && $receivedCents >= $depositCents,
            'fully_paid' => $totalCents !== null && $totalCents > 0 && $receivedCents >= $totalCents,
            'communication' => $payment['rental_communication'] ?? null,
            'deposit_due_date' => $payment['deposit_due_date'] ?? null,
            'balance_due_date' => $payment['balance_due_date'] ?? null,
            'security_deposit' => [
                'status' => $depositStatus,
                'amount_cents' => $payment['security_deposit_amount_cents'] ?? null,
                'received_cents' => $payment['security_deposit_receivable_id'] !== null
                    ? $this->receivableAmounts($payment['security_deposit_receivable_id'])['amount_received']
                    : 0,
                'communication' => $payment['security_deposit_communication'] ?? null,
                'due_date' => $payment['security_deposit_due_date'] ?? null,
                'returned_cents' => $payment['security_deposit_returned_cents'] ?? null,
                'withheld_cents' => $payment['security_deposit_withheld_cents'] ?? null,
                'returned_at' => $payment['security_deposit_returned_at'] ?? null,
                'note' => $payment['security_deposit_note'] ?? null,
            ],
        ];
    }

    /**
     * A SEPA QR for the amount that is actually due **at this stage**: the
     * deposit while it is outstanding, the remaining balance afterwards
     * (§6.19).
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

    /**
     * The security deposit's own QR — a different communication and a
     * different amount, because it is a different debt (§6.20).
     */
    public function securityDepositQrPng(RentalBooking $booking, PaymentSettings $settings): ?string
    {
        if ($this->sepaQr === null || !$settings->canRaiseReceivables()) {
            return null;
        }

        $status = $this->statusFor($booking, $settings)['security_deposit'];
        $amount = ($status['amount_cents'] ?? 0) - $status['received_cents'];

        if ($status['communication'] === null || $amount <= 0) {
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
            $status['communication']
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
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
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
                'Le montant restitué doit être compris entre 0 et ' . self::euros($amount) . '.'
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

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
            null,
            $status->label(),
            'Caution : ' . self::euros($returnedCents) . ' restitués',
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
     * @param array<string, mixed> $existing
     */
    private function ensureRentalReceivable(
        RentalBooking $booking,
        PaymentSettings $settings,
        array $existing,
        int $totalCents,
        \DateTimeImmutable $now,
        ?int $actorMemberId
    ): ?int {
        $depositCents = $settings->depositFor($totalCents);

        $receivableId = $existing['rental_receivable_id'] ?? null;
        if (is_int($receivableId)) {
            // Already raised: move the amount in place. Minting a second
            // communication here would orphan the transfers already made.
            $this->updateRentalAmount($booking, $settings, $totalCents, false, $actorMemberId);

            return $receivableId;
        }

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
                $booking->reference . ' — ' . $booking->renterName
            );
        } catch (\Throwable $e) {
            throw new RentalException($e->getMessage(), 0, $e);
        }

        if ($receivableId === null) {
            return null;
        }

        $this->paymentRepository->linkRentalReceivable(
            $booking->id,
            $receivableId,
            $communication,
            $depositCents,
            PaymentSettings::dueDate($settings->depositDueDays, $now),
            PaymentSettings::dueDate($settings->balanceDueDays, $now)
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
     * @param array<string, mixed> $existing
     */
    private function ensureSecurityDepositReceivable(
        RentalBooking $booking,
        PaymentSettings $settings,
        array $existing,
        \DateTimeImmutable $now
    ): ?int {
        $amount = $settings->securityDepositAmount();
        if ($amount === null) {
            return null;
        }

        $existingId = $existing['security_deposit_receivable_id'] ?? null;
        if (is_int($existingId)) {
            return $existingId;
        }

        // Its OWN communication, deliberately different from the rental's:
        // a renter transferring the security deposit must not accidentally
        // settle part of the rental with it, and the two must be separable
        // on the account (§6.20).
        $communication = $this->communications?->generate();
        if ($communication === null) {
            return null;
        }

        try {
            $receivableId = $this->receivables?->createReceivable(
                self::SOURCE_MODULE,
                $booking->id,
                (int) $settings->financeAccountId,
                $amount,
                $communication,
                'Caution ' . $booking->reference . ' — ' . $booking->renterName
            );
        } catch (\Throwable $e) {
            throw new RentalException($e->getMessage(), 0, $e);
        }

        if ($receivableId === null) {
            return null;
        }

        $this->paymentRepository->linkSecurityDepositReceivable(
            $booking->id,
            $receivableId,
            $communication,
            $amount,
            PaymentSettings::dueDate($settings->securityDepositDueDays, $now)
        );

        $this->journal->log(
            'rental',
            'rental_security_deposit_receivable_created',
            'info',
            'Créance de caution créée pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'amount_cents' => $amount]
        );

        return $receivableId;
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

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
