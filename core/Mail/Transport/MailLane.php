<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Mail\MailPurpose;

/**
 * One of the three ways out of this site (ARCHITECTURE.md §8.106).
 *
 * A lane is a chain of providers, tried in order, and the three exist
 * because they have genuinely different failure costs. Authentication
 * carries a token that lives fifteen minutes, so a message delivered
 * tomorrow is not the message; transactional mail can wait a little;
 * a mailing can wait a lot and already knows how to re-schedule itself
 * in batches.
 *
 * A lane is derived from `MailPurpose` and from nothing else — never
 * from the recipient, never from the calling class, never from a
 * per-feature flag. `MailPurpose` is what a sender states, and a second
 * way of deciding a lane would be a second answer to the same question.
 */
enum MailLane: string
{
    case Authentication = 'authentication';
    case Transactional = 'transactional';
    case Bulk = 'bulk';

    public static function fromPurpose(MailPurpose $purpose): self
    {
        return match ($purpose) {
            MailPurpose::MagicLink => self::Authentication,
            MailPurpose::Bulk => self::Bulk,
            MailPurpose::Ordinary => self::Transactional,
        };
    }

    /**
     * The French name the configuration screen gives this lane.
     */
    public function label(): string
    {
        return match ($this) {
            self::Authentication => 'Authentification',
            self::Transactional => 'Transactionnel',
            self::Bulk => 'Masse',
        };
    }

    /**
     * What travels on it, said in the words a volunteer would use.
     */
    public function detail(): string
    {
        return match ($this) {
            self::Authentication => 'Liens de connexion, confirmations d’adresse',
            self::Transactional => 'Notifications, alertes, accusés de réception',
            self::Bulk => 'Publipostage uniquement',
        };
    }

    /**
     * Whether a provider's cadence applies on this lane (D6).
     *
     * Only the mailing lane paces itself. An authentication or a
     * transactional message leaves immediately, under the daily quota
     * alone.
     */
    public function honoursCadence(): bool
    {
        return $this === self::Bulk;
    }

    /**
     * The three lanes, in the order the screen draws them.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Authentication, self::Transactional, self::Bulk];
    }
}
