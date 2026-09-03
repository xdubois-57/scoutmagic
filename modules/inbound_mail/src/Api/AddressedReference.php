<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * The business object a message was **addressed to**, read off a signed
 * reply address the site itself put in the `Reply-To` of what it sent
 * (`locations+rental.LOC-2027-0042.9f3a…@unite.be`, §8.58).
 *
 * As certain as the reference in the subject: the address was minted by
 * this site, carries a signature nobody else can compute, and a renter
 * who simply pressed « Répondre » sent it back untouched. A consumer
 * still checks that the object exists before associating — the address
 * outlives the booking it names.
 */
final class AddressedReference
{
    public function __construct(
        public readonly string $consumerId,
        public readonly string $businessReference
    ) {
    }
}
