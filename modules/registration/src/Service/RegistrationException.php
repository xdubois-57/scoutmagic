<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Exception\UserFacingException;

/**
 * A refused registration-request operation. Marked {@see UserFacingException}:
 * every message is a French sentence written for the chief handling the
 * request. Service\RequestEmailService's mail failure is the one that had to
 * be rewritten first — it used to append Core\Mail\MailException's raw SMTP
 * English to this class's message.
 */
class RegistrationException extends \RuntimeException implements UserFacingException
{
}
