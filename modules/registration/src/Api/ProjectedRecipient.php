<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * One reachable address for a projected year — what `mass_mail` needs to
 * write to the families of a year that does not exist in Desk yet.
 *
 * The address IS the point here, so unlike `ProjectedPerson` this one
 * carries personal data; a consumer asking for it is asking to write to
 * these people. It carries nothing else: no name, no section, no rank.
 * A consumer that wants to know where somebody is projected asks
 * `projectedPopulation()` and joins on the id, which keeps the address out
 * of every call that does not need it.
 */
final class ProjectedRecipient
{
    public function __construct(
        /** Set when the person already has a Desk identity. */
        public readonly ?int $memberId,
        /** Set instead when they are still only an accepted request. */
        public readonly ?int $registrationRequestId,
        public readonly string $email
    ) {
    }
}
