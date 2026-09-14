<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback;

/**
 * What is known about one address's return path — the three states the
 * roadmap names, plus the two that say « on ne peut pas savoir »
 * (roadmap IT-03).
 *
 * **« Jamais vérifié » is a state, not a silence.** That is the whole
 * point, and the precedent is `Modules\SupportDashboard\Service\
 * MailProbeService`: a screen that shows nothing when nothing was ever
 * tried reads exactly like a screen that shows nothing because everything
 * is fine.
 */
enum ReturnState: string
{
    /** The message came back, into a box somebody reads. */
    case VERIFIED = 'verified';

    /** It was sent, the delay has run out, and nothing arrived. */
    case NEVER_ARRIVED = 'never_arrived';

    /** Sent, and the site is still entitled to wait. */
    case WAITING = 'waiting';

    /**
     * Nobody has ever tried — including after a change of address, which
     * lands here rather than keeping the old address's answer.
     */
    case NEVER_VERIFIED = 'never_verified';

    /**
     * The check cannot be run at all: the `inbound_mail` module is
     * disabled, or no mailbox is open to this verification. An honest
     * answer, and deliberately not an error (D2).
     */
    case IMPOSSIBLE = 'impossible';

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'Vérifié',
            self::NEVER_ARRIVED => 'Jamais arrivé',
            self::WAITING => 'En attente',
            self::NEVER_VERIFIED => 'Jamais vérifié',
            self::IMPOSSIBLE => 'Vérification impossible',
        };
    }

    /** The Bootstrap contextual suffix a badge uses — design.md §7. */
    public function badge(): string
    {
        return match ($this) {
            self::VERIFIED => 'success',
            self::NEVER_ARRIVED => 'danger',
            self::WAITING => 'info',
            self::NEVER_VERIFIED, self::IMPOSSIBLE => 'secondary',
        };
    }
}
