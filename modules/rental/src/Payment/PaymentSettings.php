<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Payment;

use Core\Service\DateInput;

/**
 * One asset's payment configuration (§6.19, §6.20).
 *
 * The rental and its security deposit are ONE receivable with one
 * structured communication (issue #927): the deposit is a threshold on it,
 * the balance and the security deposit fall due together, before arrival.
 *
 * Pure data. Whether Finance is even installed is not its business — a
 * configuration can be filled in on an installation that has not enabled
 * Finance yet, and must survive that unchanged rather than being refused or
 * wiped.
 */
final class PaymentSettings
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?int $financeAccountId = null,
        public readonly DepositMode $depositMode = DepositMode::NONE,
        public readonly ?int $depositAmountCents = null,
        public readonly ?int $depositPercentage = null,
        public readonly ?int $depositDueDays = null,
        public readonly ?int $balanceDueDays = null,
        public readonly bool $securityDepositEnabled = false,
        public readonly ?int $securityDepositAmountCents = null,
        /**
         * The Finance category the rental's payments — the security
         * deposit included, since it is part of the same receivable — are
         * booked under (issue #838). Pinned by unit staff with the account,
         * the same half of the row.
         */
        public readonly ?int $financeCategoryId = null
    ) {
    }

    /**
     * The same configuration, pointed at another account (or at none).
     *
     * Exists because two screens write this row and own disjoint halves of
     * it: unit staff pin the account, the asset's managers set the deposit,
     * the balance and the security deposit. Each reads the other's half back
     * and carries it across through this method, so neither can blank work
     * it never displayed — see Service\RentalPaymentService::
     * saveFinanceAccount() and ::saveManagedSettings().
     */
    public function withFinanceAccount(?int $financeAccountId, ?int $financeCategoryId): self
    {
        return new self(
            enabled: $this->enabled,
            financeAccountId: $financeAccountId,
            depositMode: $this->depositMode,
            depositAmountCents: $this->depositAmountCents,
            depositPercentage: $this->depositPercentage,
            depositDueDays: $this->depositDueDays,
            balanceDueDays: $this->balanceDueDays,
            securityDepositEnabled: $this->securityDepositEnabled,
            securityDepositAmountCents: $this->securityDepositAmountCents,
            financeCategoryId: $financeCategoryId
        );
    }

    /**
     * Whether a receivable can actually be raised: switched on **and**
     * pointed at an account and a category (issue #838).
     *
     * Both halves matter. "Payments on" with no account is a
     * half-configured asset, and raising a receivable against no account is
     * not something to attempt and report as a failure later — it is
     * something to notice while configuring.
     */
    public function canRaiseReceivables(): bool
    {
        return $this->enabled && $this->financeAccountId !== null && $this->financeCategoryId !== null;
    }

    /** The deposit threshold for a rental totalling $totalCents, or null. */
    public function depositFor(int $totalCents): ?int
    {
        return $this->depositMode->amountFor(
            $totalCents,
            $this->depositAmountCents,
            $this->depositPercentage
        );
    }

    /**
     * The security deposit for this asset, or null.
     *
     * Independent of the rental total on purpose: a security deposit covers
     * what a group might break, which has nothing to do with what they are
     * paying to be there.
     */
    public function securityDepositAmount(): ?int
    {
        if (!$this->securityDepositEnabled) {
            return null;
        }

        return $this->securityDepositAmountCents !== null && $this->securityDepositAmountCents > 0
            ? $this->securityDepositAmountCents
            : null;
    }

    /**
     * $days after $from, or null when no deadline is configured.
     *
     * Days rather than a date, because an asset's rule has to outlive any
     * single booking.
     */
    public static function dueDate(?int $days, \DateTimeImmutable $from): ?string
    {
        return $days === null ? null : $from->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
    }

    /**
     * When the deposit falls due for a contract first sent at $sentAt:
     * `depositDueDays` after that first send, null without a deadline.
     *
     * The first send, never a later one: a replacement contract does not
     * move a deadline the renter was already given (issue #927).
     */
    public function depositDueDate(\DateTimeImmutable $sentAt): ?string
    {
        return self::dueDate($this->depositDueDays, $sentAt);
    }

    /**
     * When the balance — and the security deposit with it, being part of
     * the same receivable — falls due: `balanceDueDays` BEFORE the arrival
     * day, null without a deadline or without a readable arrival.
     *
     * Before the arrival rather than after any send or decision: what the
     * unit needs is the money in hand when the group turns up, whatever
     * happened to the file before (issue #927).
     */
    public function balanceDueDate(string $arrivalDate): ?string
    {
        if ($this->balanceDueDays === null) {
            return null;
        }

        $arrival = DateInput::iso($arrivalDate);

        return $arrival?->modify('-' . max(0, $this->balanceDueDays) . ' days')->format('Y-m-d');
    }
}
