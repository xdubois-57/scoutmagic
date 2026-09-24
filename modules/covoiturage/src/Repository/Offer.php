<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

/**
 * One car, one direction, in one carpool. It carries only the end of the
 * trip its driver chooses — the meeting point; the other end is the
 * carpool's place.
 *
 * The driver's name, phone and note arrive decrypted: OfferRepository is
 * the only place that decrypts them. Who may be SHOWN the phone is decided
 * elsewhere (Service\CarpoolBoard), never by a template.
 */
final class Offer
{
    public const OUTBOUND = 'outbound';
    public const RETURN = 'return';

    public function __construct(
        public readonly int $id,
        public readonly int $carpoolId,
        public readonly string $direction,
        /** `H:i`. */
        public readonly string $departureTime,
        public readonly string $endpoint,
        public readonly int $seats,
        public readonly int $driverAccountId,
        public readonly string $driverName,
        public readonly string $phone,
        public readonly ?string $note
    ) {
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::OUTBOUND;
    }
}
