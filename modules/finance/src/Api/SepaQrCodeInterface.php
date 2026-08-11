<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5).
 * Generates SEPA credit-transfer QR codes (EPC069-12 payload).
 */
interface SepaQrCodeInterface
{
    /**
     * @return string raw PNG bytes
     */
    public function generatePng(
        string $beneficiaryName,
        string $iban,
        ?string $bic,
        int $amountCents,
        string $communication
    ): string;
}
