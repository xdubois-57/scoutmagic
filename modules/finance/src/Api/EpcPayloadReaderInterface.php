<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): read back
 * what one of this module's transfer QR codes says.
 *
 * The writing half already lives here — `SepaQrCodeInterface::generatePng()`
 * over `Service\EpcPayload`, the one place an EPC069-12 payload is built.
 * This is the reading half, and it belongs beside it for the same reason
 * the writing half is a single place: a second parser, in another module,
 * is one season away from disagreeing about which of the two remittance
 * fields carries a Belgian structured communication, and the symptom
 * would be a scan that quietly resolves to nothing.
 *
 * **Introduced for the news module's door screen.** The confirmation
 * e-mail of a paid event carries two codes — the ticket and the transfer
 * — and a visitor under the pressure of a queue holds out the wrong one.
 * The answer is not to remove a code but to make the confusion harmless:
 * the scanner recognises a transfer payload, pulls the communication out
 * of it, and finds the ticket anyway.
 *
 * **Reading, never paying.** Nothing here initiates or records a payment;
 * a caller gets back a string it has to look up for itself.
 */
interface EpcPayloadReaderInterface
{
    /**
     * The structured communication carried by a scanned EPC069-12
     * payload, or null when the text is not one at all.
     *
     * Null is the ordinary answer — most of what a door scanner reads is
     * a bare ticket reference — so a caller uses it to tell the two forms
     * apart, not as an error.
     */
    public function communicationFrom(string $scannedPayload): ?string;
}
