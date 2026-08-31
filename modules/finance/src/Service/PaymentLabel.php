<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * One label to cut out: who owes, how much is STILL owed, and how to pay
 * it.
 *
 * **The beneficiary is deliberately absent.** It is identical on all
 * twenty-seven labels of a sheet, it is what the IBAN already identifies
 * to the bank, and every millimetre it would take is a millimetre the
 * name and the amount do not have.
 *
 * The name's font size travels with the label rather than being decided
 * in the template: dompdf measures no text at runtime, so the descent
 * (Service\PaymentLabelService::nameFontSizePt()) happens in PHP where a
 * test can pin it, and the view only renders what it was handed.
 */
final class PaymentLabel
{
    public function __construct(
        public readonly int $receivableId,
        public readonly string $memberName,
        /** What is STILL DUE, never the receivable's initial amount. */
        public readonly int $amountCents,
        public readonly string $communication,
        /** Point size the name is printed at, from the descent ladder. */
        public readonly float $nameFontSizePt,
        /** 1 normally; 2 for a name that does not fit on one line at the 6 pt floor. */
        public readonly int $nameLines,
        /** `data:image/png;base64,…` — dompdf runs with remote loading off. */
        public readonly ?string $qrDataUri = null
    ) {
    }

    public function withQrDataUri(string $qrDataUri): self
    {
        return new self(
            $this->receivableId,
            $this->memberName,
            $this->amountCents,
            $this->communication,
            $this->nameFontSizePt,
            $this->nameLines,
            $qrDataUri
        );
    }
}
