<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Repository\DecisionEmail;

/**
 * One line of the « Historique » of a fiche's « E-mail de décision » block
 * (Service\DecisionEmailPanel): something that happened to one decision
 * e-mail, at one moment. `email` is the row it comes from — its decision,
 * attempt number, resend flag, due date and failure sentence, the last one
 * written for a chief at the moment of the failure (UserFacingMessage),
 * never the transport's own text.
 */
final class DecisionEmailEvent
{
    /** A send was planned, due at `email->dueAt`. */
    public const PLANNED = 'planned';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    /** Claimed and not reported back yet. */
    public const SENDING = 'sending';
    /** Claimed so long ago that it will never report back: whether it left is unknown. */
    public const INTERRUPTED = 'interrupted';

    public function __construct(
        public readonly string $kind,
        public readonly \DateTimeImmutable $at,
        public readonly DecisionEmail $email
    ) {
    }
}
