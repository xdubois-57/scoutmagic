<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Modules\Finance\Api\SepaQrCodeInterface;

/**
 * SEPA Credit Transfer QR code (EPC069-12 "GiroCode" payload), scanned
 * directly by any SEPA banking app. The payload itself is built in one
 * place only — Service\EpcPayload — so the screen QR, the mail QR and the
 * printed label QR can never encode three slightly different payment
 * requests.
 *
 * **Two renderings, one payload.** `generatePng()` is the screen and mail
 * one: it is looked at on a display that lights its own pixels, or in a
 * mail body at whatever size the client picks. `generatePrintPng()` is
 * the paper one, and paper is a harsher medium — see its docblock.
 */
class SepaQrCodeService implements SepaQrCodeInterface
{
    /** Screen and e-mail: 400 px is already more than any client renders. */
    private const SCREEN_SIZE_PX = 400;

    /**
     * Paper: generated far larger than it prints, so dompdf scales DOWN
     * rather than interpolating up. At error correction M an EPC payload
     * for an ordinary unit name lands in QR version 5 — 37 modules — and
     * at the 18 mm a payment label gives it, one module is 0.48 mm. A
     * code generated at that size and stretched is a blur of
     * half-modules a phone refuses; generated at 600 px and scaled down,
     * a module still lands on a whole number of printer dots.
     *
     * A long beneficiary name lengthens the payload and costs modules —
     * 45 of them, 0.39 mm each, for a fifty-character name. That is the
     * real floor of this layout, and it is why the beneficiary is the one
     * field the label does not repeat in text.
     */
    private const PRINT_SIZE_PX = 600;

    /**
     * **The quiet zone lives in the layout, not in the PNG.** The 18 mm a
     * label gives the code is 18 mm of MODULES; asking the writer for the
     * four-module border on top of that would shrink every module by a
     * fifth, on a code that is already at the edge of what a phone reads.
     * Service\PaymentLabelService is what guarantees the white border
     * instead — no label content comes within 2 mm of the code on any
     * side, which is more than four modules of white at any payload
     * length. Changing the label geometry means re-checking that.
     */
    private const PRINT_MARGIN_PX = 0;

    /**
     * @return string raw PNG bytes
     */
    public function generatePng(
        string $beneficiaryName,
        string $iban,
        ?string $bic,
        int $amountCents,
        string $communication
    ): string {
        return $this->render(
            EpcPayload::build($beneficiaryName, $iban, $bic, $amountCents, $communication),
            self::SCREEN_SIZE_PX,
            10,
            ErrorCorrectionLevel::Low
        );
    }

    /**
     * The same payment request, rendered for print.
     *
     * **Deliberately not on `Api\SepaQrCodeInterface`.** What other
     * modules consume is "give me the QR of this payment"; the resolution
     * and the error-correction level a sheet of labels needs are this
     * module's own printing concern, and widening the public contract for
     * them would oblige every consumer and every test double to carry a
     * method none of them wants.
     *
     * **Error correction M, never L.** M is the level the EPC
     * specification recommends, and on paper it is a requirement rather
     * than a luxury: a label is cut out by hand, carried in a coat
     * pocket, folded, stained and scanned crooked under a kitchen light.
     * Dropping to L would buy a handful of modules — a marginally coarser
     * grid — at the price of the redundancy that is the whole reason a
     * smudged code still scans.
     *
     * @return string raw PNG bytes
     */
    public function generatePrintPng(
        string $beneficiaryName,
        string $iban,
        ?string $bic,
        int $amountCents,
        string $communication
    ): string {
        return $this->render(
            EpcPayload::build($beneficiaryName, $iban, $bic, $amountCents, $communication),
            self::PRINT_SIZE_PX,
            self::PRINT_MARGIN_PX,
            ErrorCorrectionLevel::Medium
        );
    }

    private function render(string $payload, int $size, int $margin, ErrorCorrectionLevel $level): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: $payload,
            errorCorrectionLevel: $level,
            size: $size,
            margin: $margin
        ))->build();

        return $result->getString();
    }
}
