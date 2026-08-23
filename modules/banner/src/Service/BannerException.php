<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Banner\Service;

use Core\Exception\UserFacingException;

/**
 * A refused banner operation. Marked {@see UserFacingException}: every
 * message is a French sentence written for the administrator editing the
 * banner.
 */
class BannerException extends \RuntimeException implements UserFacingException
{
}
