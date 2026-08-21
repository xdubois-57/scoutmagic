<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;

/**
 * The payment columns of `rental_assets` and `rental_bookings` (§6.19,
 * §6.20).
 *
 * Its own repository rather than more methods on the asset and booking ones:
 * the payment configuration is a coherent block that is read together, saved
 * together, and — this is the point — read by code that must keep working
 * when Finance is disabled. Keeping it separate makes "does this path touch
 * Finance?" answerable by looking at one class.
 *
 * **No foreign key ever points at a Finance table.** The receivable ids
 * stored here are reached only through Finance's public API
 * (ARCHITECTURE.md §7.5); a constraint would make `rental` unusable without
 * `finance`, which is precisely what the nullable dependency exists to
 * avoid.
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalPaymentRepository
{
    private const CTX_NOTE = 'rental_bookings.security_deposit_note';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    // ── Asset configuration ─────────────────────────────────────────────

    public function loadSettings(int $assetId): PaymentSettings
    {
        $stmt = $this->pdo->prepare(
            'SELECT payments_enabled, finance_account_id, deposit_mode, deposit_amount_cents,
                    deposit_percentage, deposit_due_days, balance_due_days,
                    security_deposit_mode, security_deposit_amount_cents, security_deposit_due_days
             FROM rental_assets WHERE id = ?'
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return new PaymentSettings();
        }

        return new PaymentSettings(
            enabled: (bool) $row['payments_enabled'],
            financeAccountId: $row['finance_account_id'] !== null ? (int) $row['finance_account_id'] : null,
            depositMode: DepositMode::tryFrom((string) $row['deposit_mode']) ?? DepositMode::NONE,
            depositAmountCents: $row['deposit_amount_cents'] !== null ? (int) $row['deposit_amount_cents'] : null,
            depositPercentage: $row['deposit_percentage'] !== null ? (int) $row['deposit_percentage'] : null,
            depositDueDays: $row['deposit_due_days'] !== null ? (int) $row['deposit_due_days'] : null,
            balanceDueDays: $row['balance_due_days'] !== null ? (int) $row['balance_due_days'] : null,
            securityDepositEnabled: (string) $row['security_deposit_mode'] === 'fixed',
            securityDepositAmountCents: $row['security_deposit_amount_cents'] !== null
                ? (int) $row['security_deposit_amount_cents']
                : null,
            securityDepositDueDays: $row['security_deposit_due_days'] !== null
                ? (int) $row['security_deposit_due_days']
                : null
        );
    }

    public function saveSettings(int $assetId, PaymentSettings $settings): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets SET
                payments_enabled = ?, finance_account_id = ?, deposit_mode = ?,
                deposit_amount_cents = ?, deposit_percentage = ?, deposit_due_days = ?,
                balance_due_days = ?, security_deposit_mode = ?,
                security_deposit_amount_cents = ?, security_deposit_due_days = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $settings->enabled ? 1 : 0,
            $settings->financeAccountId,
            $settings->depositMode->value,
            $settings->depositAmountCents,
            $settings->depositPercentage,
            $settings->depositDueDays,
            $settings->balanceDueDays,
            $settings->securityDepositEnabled ? 'fixed' : 'none',
            $settings->securityDepositAmountCents,
            $settings->securityDepositDueDays,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
        ]);
    }

    // ── The rental receivable ───────────────────────────────────────────

    public function linkRentalReceivable(
        int $bookingId,
        int $receivableId,
        string $communication,
        ?int $depositAmountCents,
        ?string $depositDueDate,
        ?string $balanceDueDate
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET
                rental_receivable_id = ?, rental_communication = ?, deposit_amount_cents = ?,
                deposit_due_date = ?, balance_due_date = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $receivableId,
            $communication,
            $depositAmountCents,
            $depositDueDate,
            $balanceDueDate,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
        ]);
    }

    // ── The security deposit ────────────────────────────────────────────

    public function linkSecurityDepositReceivable(
        int $bookingId,
        int $receivableId,
        string $communication,
        int $amountCents,
        ?string $dueDate
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET
                security_deposit_receivable_id = ?, security_deposit_communication = ?,
                security_deposit_amount_cents = ?, security_deposit_due_date = ?,
                security_deposit_status = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $receivableId,
            $communication,
            $amountCents,
            $dueDate,
            SecurityDepositStatus::TO_RECEIVE->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
        ]);
    }

    public function setSecurityDepositStatus(int $bookingId, SecurityDepositStatus $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET security_deposit_status = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $status->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
        ]);
    }

    /**
     * Records a return, by hand — Finance reconciles incoming transfers,
     * not outgoing ones, so nothing can detect this from a bank statement
     * (§6.20).
     */
    public function recordSecurityDepositReturn(
        int $bookingId,
        SecurityDepositStatus $status,
        int $returnedCents,
        int $withheldCents,
        string $returnedAt,
        ?string $note
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET
                security_deposit_status = ?, security_deposit_returned_cents = ?,
                security_deposit_withheld_cents = ?, security_deposit_returned_at = ?,
                security_deposit_note_encrypted = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status->value,
            $returnedCents,
            $withheldCents,
            $returnedAt,
            $note !== null && trim($note) !== ''
                ? $this->encryption->encrypt(trim($note), self::CTX_NOTE)
                : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
        ]);
    }

    /**
     * Everything payment-related about one booking.
     *
     * Returned as a plain array rather than folded into `RentalBooking`:
     * these columns are read only by the payment screens, and hydrating
     * them on every booking load — including on the availability path,
     * which reads bookings by the hundred — would cost the whole module for
     * one screen's benefit.
     *
     * @return array{
     *   rental_receivable_id: ?int, rental_communication: ?string,
     *   deposit_amount_cents: ?int, deposit_due_date: ?string, balance_due_date: ?string,
     *   security_deposit_receivable_id: ?int, security_deposit_communication: ?string,
     *   security_deposit_amount_cents: ?int, security_deposit_due_date: ?string,
     *   security_deposit_status: SecurityDepositStatus,
     *   security_deposit_returned_cents: ?int, security_deposit_withheld_cents: ?int,
     *   security_deposit_returned_at: ?string, security_deposit_note: ?string
     * }|null
     */
    public function loadBookingPayment(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rental_receivable_id, rental_communication, deposit_amount_cents,
                    deposit_due_date, balance_due_date,
                    security_deposit_receivable_id, security_deposit_communication,
                    security_deposit_amount_cents, security_deposit_due_date,
                    security_deposit_status, security_deposit_returned_cents,
                    security_deposit_withheld_cents, security_deposit_returned_at,
                    security_deposit_note_encrypted
             FROM rental_bookings WHERE id = ?'
        );
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'rental_receivable_id' => $row['rental_receivable_id'] !== null ? (int) $row['rental_receivable_id'] : null,
            'rental_communication' => $row['rental_communication'] !== null ? (string) $row['rental_communication'] : null,
            'deposit_amount_cents' => $row['deposit_amount_cents'] !== null ? (int) $row['deposit_amount_cents'] : null,
            'deposit_due_date' => $row['deposit_due_date'] !== null ? (string) $row['deposit_due_date'] : null,
            'balance_due_date' => $row['balance_due_date'] !== null ? (string) $row['balance_due_date'] : null,
            'security_deposit_receivable_id' => $row['security_deposit_receivable_id'] !== null
                ? (int) $row['security_deposit_receivable_id']
                : null,
            'security_deposit_communication' => $row['security_deposit_communication'] !== null
                ? (string) $row['security_deposit_communication']
                : null,
            'security_deposit_amount_cents' => $row['security_deposit_amount_cents'] !== null
                ? (int) $row['security_deposit_amount_cents']
                : null,
            'security_deposit_due_date' => $row['security_deposit_due_date'] !== null
                ? (string) $row['security_deposit_due_date']
                : null,
            'security_deposit_status' => SecurityDepositStatus::tryFrom((string) $row['security_deposit_status'])
                ?? SecurityDepositStatus::NONE,
            'security_deposit_returned_cents' => $row['security_deposit_returned_cents'] !== null
                ? (int) $row['security_deposit_returned_cents']
                : null,
            'security_deposit_withheld_cents' => $row['security_deposit_withheld_cents'] !== null
                ? (int) $row['security_deposit_withheld_cents']
                : null,
            'security_deposit_returned_at' => $row['security_deposit_returned_at'] !== null
                ? (string) $row['security_deposit_returned_at']
                : null,
            'security_deposit_note' => $row['security_deposit_note_encrypted'] !== null
                && (string) $row['security_deposit_note_encrypted'] !== ''
                ? $this->encryption->decrypt((string) $row['security_deposit_note_encrypted'], self::CTX_NOTE)
                : null,
        ];
    }
}
