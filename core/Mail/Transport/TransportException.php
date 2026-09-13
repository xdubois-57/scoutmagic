<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Exception\UserFacingException;

/**
 * A refusal the Courrier sortant page has to explain to a super-admin.
 *
 * Every message this class is ever constructed with is a French sentence
 * written for the person reading the screen, naming a lane or a provider
 * and never a host, a column or a library's own words — which is the
 * claim `Core\Exception\UserFacingException` stands for. In particular
 * it is **never** built from another exception's message: a relay's
 * refusal reaches the journal through `MailService`, not a flash
 * message.
 */
final class TransportException extends \RuntimeException implements UserFacingException
{
}
