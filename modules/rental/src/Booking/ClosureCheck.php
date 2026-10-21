<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Whether a confirmed booking may be closed, as
 * `Service\RentalClosureService::check()` reads it (#980, CHANTIER-locations-incidents.md §2.9).
 *
 * Two answers, kept apart because they are not the same question:
 * `$refusal` is why « Clôturer la location » is refused — what is still
 * outstanding and what to do about it — and `$unrecoveredCents` is the
 * amount « Clôturer avec un solde non recouvré » would write off, set only
 * when that action is offered. A booking whose closure is refused because
 * money is still due can carry both.
 */
final class ClosureCheck
{
    public function __construct(
        public readonly ?string $refusal = null,
        public readonly ?int $unrecoveredCents = null
    ) {
    }

    public function isClosable(): bool
    {
        return $this->refusal === null;
    }

    public function offersUnrecovered(): bool
    {
        return $this->unrecoveredCents !== null && $this->unrecoveredCents > 0;
    }
}
