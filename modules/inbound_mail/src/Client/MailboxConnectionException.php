<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Core\Exception\UserFacingException;

/**
 * A mailbox could not be reached or read.
 *
 * The message is written for a superadmin looking at the configuration
 * page, and is built by `Service\MailboxErrorFormatter` — never by
 * interpolating a library's own exception text, which routinely contains
 * the account name and, on some servers, the credential that was rejected.
 */
class MailboxConnectionException extends \RuntimeException implements UserFacingException
{
}
