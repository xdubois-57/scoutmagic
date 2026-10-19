<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * Where the reenrollment campaign of one target scout year stands —
 * Api\ScoutYearRegistrationReviewProvider::reenrollmentCampaign().
 *
 * Three states and nothing in between, decided by the campaign's own
 * clock and switch (Service\ReenrollmentCampaignService), never by a count
 * of answers:
 *
 * - `not_started`: its opening has not happened — neither the opening
 *   date nor an opening by hand that wrote to the families;
 * - `open`: the campaign switch is on, whatever the dates say (the switch
 *   always wins: it is how a unit opens early or lets one late family
 *   back in);
 * - `finished`: it has started and is no longer open — closed by its date
 *   or by hand.
 *
 * The dates are the campaign's own, `Y-m-d`: `$opensOn` its opening day,
 * `$closesOn` its closing day. Both are null only for a campaign opened by
 * the switch while no dates are configured.
 */
final class ReenrollmentCampaignState
{
    public const NOT_STARTED = 'not_started';
    public const OPEN = 'open';
    public const FINISHED = 'finished';

    public function __construct(
        /** One of the three constants above. */
        public readonly string $state,
        /** The scout year the campaign asks the families about, e.g. "2027-2028". */
        public readonly string $targetYearLabel,
        public readonly ?string $opensOn,
        public readonly ?string $closesOn
    ) {
    }

    public function isFinished(): bool
    {
        return $this->state === self::FINISHED;
    }
}
