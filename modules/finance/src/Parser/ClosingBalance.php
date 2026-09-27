<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

/**
 * An account's balance as the bank itself states it at the end of a file,
 * and the date it holds on — what Service\ImportService records as the
 * account's balance checkpoint instead of asking for one by hand.
 */
final class ClosingBalance
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly float $amount
    ) {
    }
}
