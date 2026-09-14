<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Exception\UserFacingException;

/**
 * A probe that could not be sent, or a verdict that could not be
 * recorded (roadmap IT-04).
 *
 * **Its message is French and goes on a screen**, unlike
 * `MailException`'s, which carries whatever the relay said — English,
 * technical, and quite capable of quoting the recipient's address back.
 * The cause is chained for the journal; what the operator reads is the
 * sentence written here.
 *
 * `Core\Exception\UserFacingException` is what makes that a contract
 * rather than a claim in a comment: it is the marker the codebase uses to
 * say « this message may be shown as written », and a class whose
 * docblock promised it without implementing it was promising nothing.
 *
 * Not `final`, because {@see MailProbeNotRecordedException} narrows it:
 * the two need one `catch` in the controller and two different sentences
 * on the screen.
 */
class MailProbeException extends \RuntimeException implements UserFacingException
{
}
