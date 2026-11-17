<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Exception\UserFacingException;

/**
 * A box could not be reserved to its module because another box already
 * is (D5). The message is the sentence the operator reads; nothing was
 * written. Both throw sites build a French sentence naming only the
 * module and the box that already holds it, so the message may be shown
 * as is.
 */
final class MailboxReservationRefusedException extends \RuntimeException implements UserFacingException
{
}
