<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

/**
 * A box could not be reserved to its module because another box already
 * is (D5). The message is the sentence the operator reads; nothing was
 * written.
 */
final class MailboxReservationRefused extends \RuntimeException
{
}
