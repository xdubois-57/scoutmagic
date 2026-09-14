<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

/**
 * The message left, and the history could not be told (roadmap IT-04).
 *
 * **The one outcome that is neither a success nor a failure**, and the
 * reason it needs a name of its own. `record()` runs after `send()` — it
 * has to, because a row for a message that never left would wait for ever
 * for a verdict nobody can give. So there is a window, narrow but real,
 * where the relay has accepted the probe and the database refuses the
 * insert.
 *
 * Reporting that as « la sonde n'a pas pu partir » would be false twice
 * over: the message is on its way to somebody's mailbox, and the operator,
 * told it failed, presses the button again and sends a duplicate — which
 * is exactly the reading a second probe makes harder, since two messages
 * by two roads to one address is the comparison this page exists for.
 *
 * So the sentence says what happened, hands over the code the message
 * carries — the only thing that still lets somebody find it — and says
 * not to retry. The code travels in the exception rather than the
 * database precisely because the database is what just failed.
 */
final class MailProbeNotRecordedException extends MailProbeException
{
    public function __construct(
        /**
         * `$probeCode` and not `$code`: `Exception` already has a `$code`
         * — an int — and shadowing it is a fatal error, not a style
         * question.
         */
        public readonly string $probeCode,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            sprintf(
                'La sonde est bien partie, sous le code %s, mais elle n’a pas pu être inscrite dans '
                    . 'l’historique. Cherchez ce code dans la boîte de destination — indésirables compris — '
                    . 'et ne relancez pas : vous enverriez un second message.',
                $probeCode
            ),
            0,
            $previous
        );
    }
}
