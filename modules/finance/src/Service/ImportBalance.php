<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * The balance an import records as an account's checkpoint: stated by the
 * file on its own date, or typed by hand ($date null — it then holds on the
 * day of the statement's last line).
 *
 * @internal ImportService's own value
 */
final class ImportBalance
{
    public function __construct(
        public readonly float $amount,
        public readonly ?\DateTimeImmutable $date
    ) {
    }
}
