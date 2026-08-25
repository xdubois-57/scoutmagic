<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

use Core\Service\TextNormalizerService;

/**
 * The one key an invoice's name and a member's name are compared on.
 *
 * **Surname + first name + birth date, never surname + birth date.** Twins
 * exist, they are registered together, and they appear on the same
 * invoice.
 *
 * Written once and used from both sides — the document
 * (`InvoicePerson::matchKey()`) and the roster
 * (`Repository\InvoiceMemberMatchRepository`) — because two keys that agree
 * today and drift tomorrow produce an import where nobody matches and
 * nothing says why. Folding goes through
 * `Core\Service\TextNormalizerService::fold()` (§8.0): the document is
 * inconsistent about case and accents within itself.
 */
final class PersonMatchKey
{
    public static function for(string $lastName, string $firstName, string $isoBirthDate): string
    {
        return TextNormalizerService::fold($lastName)
            . '|' . TextNormalizerService::fold($firstName)
            . '|' . $isoBirthDate;
    }
}
