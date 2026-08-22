<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Payment;

/**
 * One asset's payment configuration (§6.19, §6.20).
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
        public readonly ?int $securityDepositDueDays = null
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
    public function withFinanceAccount(?int $financeAccountId): self
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
            securityDepositDueDays: $this->securityDepositDueDays
        );
    }

    /**
     * Whether a receivable can actually be raised: switched on **and**
     * pointed at an account.
     *
     * Both halves matter. "Payments on" with no account is a
     * half-configured asset, and raising a receivable against no account is
     * not something to attempt and report as a failure later — it is
     * something to notice while configuring.
     */
    public function canRaiseReceivables(): bool
    {
        return $this->enabled && $this->financeAccountId !== null;
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
}
