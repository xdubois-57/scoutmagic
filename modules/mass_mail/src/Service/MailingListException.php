<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Exception\UserFacingException;

/**
 * A refused mailing-list operation. Marked {@see UserFacingException}: every
 * message is a French sentence written for the chief managing the lists.
 */
class MailingListException extends \RuntimeException implements UserFacingException
{
}
