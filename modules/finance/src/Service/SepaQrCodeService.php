<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Modules\Finance\Api\SepaQrCodeInterface;

/**
 * SEPA Credit Transfer QR code (EPC069-12 "GiroCode" payload), scanned
 * directly by any SEPA banking app. The Belgian structured communication
 * (+++NNN/NNNN/NNNNN+++) isn't an ISO 11649 "RF" creditor reference, so it
 * is carried in the unstructured remittance information field (line 11)
 * rather than the structured one (line 10, left blank) — the payload
 * remains fully EPC069-12 valid, and Belgian banking apps display the
 * communication either way.
 */
class SepaQrCodeService implements SepaQrCodeInterface
{
    public function generatePng(
        string $beneficiaryName,
        string $iban,
        ?string $bic,
        int $amountCents,
        string $communication
    ): string {
        $payload = $this->buildEpcPayload($beneficiaryName, $iban, $bic, $amountCents, $communication);

        $result = (new Builder(
            writer: new PngWriter(),
            data: $payload,
            size: 400,
            margin: 10
        ))->build();

        return $result->getString();
    }

    private function buildEpcPayload(string $beneficiaryName, string $iban, ?string $bic, int $amountCents, string $communication): string
    {
        $amount = number_format($amountCents / 100, 2, '.', '');

        $lines = [
            'BCD',
            '002',
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
}
