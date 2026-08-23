<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Exception\UserFacingException;

/**
 * A refused news operation — a missing image, a closed form, a field that
 * failed validation. Marked {@see UserFacingException}: every message is a
 * French sentence naming the field or the article the author is looking at.
 */
class NewsException extends \RuntimeException implements UserFacingException
{
}
