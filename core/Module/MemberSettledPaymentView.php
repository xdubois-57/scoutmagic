<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * One payment demand that is over, as the admin member page draws it.
 *
 * Deliberately a different DTO from Core\Module\MemberPaymentView rather
 * than the same one with its payment details left null. That view exists
 * to be ACTED on — it carries an IBAN, a communication and a QR code,
 * and everything in it answers "how do I pay this". None of that means
 * anything for a demand that is closed, and a settled row rendered
 * through it would offer a parent a code to scan for money already paid.
 *
 * What a closed row needs instead is what this one carries: what it was
 * for, what was asked, what arrived, how it ended and when.
 *
 * `status` is one of the three constants below rather than the finance
 * module's own vocabulary: core owns the template and therefore has to
 * pick a badge for each outcome, so the set of outcomes has to be
 * core's. The module maps its receivable states onto them.
 */
final class MemberSettledPaymentView
{
    /** The money arrived. */
    public const STATUS_PAID = 'paid';
    /** The unit gave up on it — abandoned, never collected. */
    public const STATUS_WAIVED = 'waived';
    /**
     * More money arrived than was asked for, and the surplus has been
     * paid back. Worded precisely because "remboursé" alone would say
     * the whole payment came back, which is a different fact and not one
     * this model records.
     */
    public const STATUS_OVERPAYMENT_REFUNDED = 'overpayment_refunded';

    /**
     * @param string $label            what it was for, e.g. "Cotisation 2024-2025"
     * @param int $amountDueCents      what was asked for
     * @param int $amountReceivedCents what actually arrived — can exceed the
     *        amount due, and on a refunded row that surplus is the point
     * @param self::STATUS_* $status   how it ended
     * @param \DateTimeImmutable|null $settledOn when it ended, null when the
     *        module cannot date the outcome (an old row, a waiver recorded
     *        before the column existed) — a missing date is shown as absent,
     *        never as today
     */
    public function __construct(
        public readonly string $label,
        public readonly int $amountDueCents,
        public readonly int $amountReceivedCents,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $settledOn,
    ) {
    }
}
