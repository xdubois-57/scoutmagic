<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Service;

use Core\Exception\UserFacingException;

/**
 * A refused document operation. Marked {@see UserFacingException}: every
 * message is a French sentence written for the chef d'unité editing the
 * list.
 */
class DocumentException extends \RuntimeException implements UserFacingException
{
}
