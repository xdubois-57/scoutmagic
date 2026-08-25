<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * One thing a member still owes, as the member page draws it.
 *
 * Presentation-ready on purpose, exactly like Core\Module\
 * FormationPathView: core owns the template but not the domain, so the
 * providing module hands over a label, an amount in cents and the
 * payment details rather than its own receivable vocabulary. Core never
 * learns what a campaign is, and the module can change how it words
 * "Cotisation 2025-2026" without touching a core template.
 *
 * `qrUrl` is an absolute URL to an image, never the image itself: the
 * same tokenised route a reminder mail points at
 * (Modules\Finance\Controller\ReceivableQrController), so the code a
 * parent scans on the page and the one they scan in their mailbox are
 * one code with one implementation.
 */
final class MemberPaymentView
{
    /**
     * @param string $label            what is being asked for, e.g. "Cotisation 2025-2026"
     * @param int $amountRemainingCents what is STILL due — never the original amount,
     *        which after a partial payment would ask for money already paid
     * @param int $amountDueCents      the full amount asked for
     * @param int $amountReceivedCents what has arrived so far, 0 when nothing has
     * @param string $communication    the structured communication, already formatted
     * @param string|null $beneficiary the account holder to type into a transfer
     * @param string|null $iban        the IBAN, already grouped in fours for reading
     * @param string|null $qrUrl       absolute URL of the payment QR, null when the
     *        account has no IBAN and there is therefore no code to draw
     */
    public function __construct(
        public readonly string $label,
        public readonly int $amountRemainingCents,
        public readonly int $amountDueCents,
        public readonly int $amountReceivedCents,
        public readonly string $communication,
        public readonly ?string $beneficiary,
        public readonly ?string $iban,
        public readonly ?string $qrUrl,
    ) {
    }

    /**
     * Whether part of it has already arrived — the page says "il reste X"
     * rather than "X à payer", because a parent who paid half and reads
     * the full amount concludes their transfer was lost.
     */
    public function isPartiallyPaid(): bool
    {
        return $this->amountReceivedCents > 0;
    }
}
