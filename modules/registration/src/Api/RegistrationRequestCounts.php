<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The registration requests of one target scout year still waiting on the
 * staff — Api\ScoutYearRegistrationReviewProvider::requestCounts().
 *
 * `accepted` is the `accepted` status only: a request already `encoded` is
 * in Desk, its section is Desk's, and it waits on nobody. The veto on the
 * public switch counts something else (every non-final request, every year
 * — Api\ScoutYearTransitionVetoProvider) and is not derived from these.
 */
final class RegistrationRequestCounts
{
    public function __construct(
        /** Still waiting for a decision. */
        public readonly int $pending,
        /** Accepted, not yet found in a Desk import nor linked by hand. */
        public readonly int $accepted,
        /** Of those accepted, how many have no « section prévue » chosen. */
        public readonly int $acceptedWithoutIntendedSection
    ) {
    }
}
