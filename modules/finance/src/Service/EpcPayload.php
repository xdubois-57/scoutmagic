<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * The EPC069-12 ("GiroCode") SEPA credit-transfer payload, and the ONLY
 * place in the codebase that builds one.
 *
 * It used to be a private method of Service\SepaQrCodeService, which was
 * fine while exactly one caller existed. It stopped being fine the day a
 * second surface wanted the same payment request at a different
 * resolution (the payment labels of a campaign, Service\
 * PaymentLabelService): a second EPC builder is one season away from
 * disagreeing with the first about a field nobody re-reads — the service
 * tag, the version line, which of the two remittance fields carries a
 * Belgian structured communication — and the symptom is a QR a bank
 * refuses with no clue why.
 *
 * The Belgian structured communication (+++NNN/NNNN/NNNNN+++) isn't an
 * ISO 11649 "RF" creditor reference, so it is carried in the unstructured
 * remittance information field (line 11) rather than the structured one
 * (line 10, left blank) — the payload stays fully EPC069-12 valid, and
 * Belgian banking apps display the communication either way.
 */
final class EpcPayload
{
    /** Version 002 — the one that does not require a BIC. */
    public const VERSION = '002';

    /**
     * The eleven lines of an EPC069-12 payload, in their fixed order.
     *
     * Field lengths are the specification's own: 11 for the BIC, 70 for
     * the beneficiary's name, 140 for the unstructured remittance
     * information. Truncating here rather than at the call sites is what
     * keeps a long unit name from producing a payload a scanner refuses
     * on one screen and accepts on another.
     *
     * The three cuts are `substr()`, i.e. BYTES — carried over verbatim
     * from where this lived before, and left alone on purpose: switching
     * to `mb_substr()` would change the bytes of every QR already in
     * circulation. It only bites a beneficiary name or a communication
     * that is BOTH over the limit AND accented exactly at it, which no
     * unit name in this codebase's reach comes near; whoever changes it
     * should say so in a release note rather than slipping it in.
     */
    public static function build(
        string $beneficiaryName,
        string $iban,
        ?string $bic,
        int $amountCents,
        string $communication
    ): string {
        $amount = number_format($amountCents / 100, 2, '.', '');

        $lines = [
            'BCD',
            self::VERSION,
            '1',
            'SCT',
            $bic !== null ? substr($bic, 0, 11) : '',
            substr($beneficiaryName, 0, 70),
            str_replace(' ', '', $iban),
            'EUR' . $amount,
            '',
            '',
            substr($communication, 0, 140),
        ];

        return implode("\n", $lines);
    }

    /**
     * The reverse of build(), for the ONE field a reader ever wants back:
     * the communication.
     *
     * Lives here rather than in a second file for exactly the reason the
     * class docblock gives for build() — the decision that a Belgian
     * structured communication travels in the unstructured remittance
     * field (line 11) is written once, and reading it back reads the same
     * line. A parser elsewhere would drift from the builder the day
     * somebody moved it.
     *
     * Deliberately tolerant about the envelope and strict about the head:
     * CRLF is accepted (a QR decoder may hand either back), trailing
     * blank lines are ignored, but the first line must be the service tag
     * `BCD`. Null means « this is not a transfer payload », which is the
     * ordinary answer for a bare ticket reference and not an error.
     */
    public static function communicationFrom(string $payload): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($payload));
        if ($lines === false || count($lines) < 11 || trim($lines[0]) !== 'BCD') {
            return null;
        }

        $communication = trim($lines[10]);

        return $communication !== '' ? $communication : null;
    }
}
