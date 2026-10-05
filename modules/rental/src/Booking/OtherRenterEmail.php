<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * One of a booking's « Autres adresses du locataire » (#720, step 5): an
 * address its correspondence comes from or goes to besides the renter's
 * own — the group's treasurer, a partner, a work address.
 */
final class OtherRenterEmail
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        /**
         * The message whose filing taught it; null when a manager typed it
         * in. Shown as « ajoutée automatiquement ».
         */
        public readonly ?int $learnedFromMessageId
    ) {
    }

    public function wasLearned(): bool
    {
        return $this->learnedFromMessageId !== null;
    }
}
