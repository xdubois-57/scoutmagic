<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * The site refused to send at all, on purpose: the mail capture is armed
 * but no longer acting (Core\Security\SandboxLossState, issue #1469).
 *
 * A `MailException`, so every caller that already copes with a failed
 * send copes with this one — nobody was written to, which is exactly what
 * a failure means to them. Its own class for one reader:
 * `Transport\MailTransportChain` rethrows it at once instead of stepping
 * to the next relay, because no relay refused anything. Recording it as a
 * relay failure would open every provider's circuit breaker on an
 * installation whose relays are working perfectly.
 */
final class MailSendingSuspendedException extends MailException
{
    public static function captureLost(): self
    {
        return new self(
            'L\'envoi des e-mails est suspendu : la capture des e-mails est armée mais n\'agit plus. '
            . 'Un super-administrateur doit décider depuis Configuration › Capture suspendue.'
        );
    }
}
