<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

/**
 * A probe that could not be sent, or a verdict that could not be
 * recorded (roadmap IT-04).
 *
 * **Its message is French and goes on a screen**, unlike
 * `MailException`'s, which carries whatever the relay said — English,
 * technical, and quite capable of quoting the recipient's address back.
 * The cause is chained for the journal; what the operator reads is the
 * sentence written here.
 */
final class MailProbeException extends \RuntimeException
{
}
