<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * One person in a projected year, as another module sees them.
 *
 * A public contract's types live in `Api` (ARCHITECTURE.md §7.5): a
 * consumer that received `Repository\RegistrationRequest` would be reading
 * this module's internals through a hole in the interface, and could not
 * be compiled without it.
 *
 * **No personal data beyond what a projection is.** A name, an address, a
 * birth date and an e-mail are all absent, deliberately: this says who is
 * expected where, and everything that identifies the person stays behind
 * the module's own repositories, where the decryption also stays
 * (SECURITY.md §5). `gender` is the same three-way classification the
 * Prévisions page already shows publicly, and `yearInBranch` is a rank,
 * not an age.
 *
 * Exactly one of `memberId` and `registrationRequestId` is set. A person
 * already in Desk is a member; one whose family has been accepted but who
 * has not been encoded yet exists only as a request, and has no member id
 * to give.
 */
final class ProjectedPerson
{
    public function __construct(
        /** Set when this person already has a Desk identity. */
        public readonly ?int $memberId,
        /** Set instead when they are still only an accepted request. */
        public readonly ?int $registrationRequestId,
        /**
         * Where they are projected, or null when nobody has decided yet —
         * a real state, not missing data: they still count in the unit's
         * total and in the pyramid, and the Prévisions page shows them as
         * « non attribués ».
         */
        public readonly ?int $sectionId,
        /** Rank inside the branch, 1-based; null when it cannot be derived. */
        public readonly ?int $yearInBranch,
        /** 'male' | 'female' | 'other' — the same classification the page shows. */
        public readonly string $gender,
        /**
         * True only for a real Desk row in the target year. A chosen
         * destination is still staff's plan, not a fact — see
         * Service\ForecastService's docblock.
         */
        public readonly bool $certain,
        /** 'desk' | 'continuing' | 'passage' | 'registration'. */
        public readonly string $origin
    ) {
    }
}
