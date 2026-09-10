<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

use Core\Exception\UserFacingException;

/**
 * A write refused before it started, because finishing it would have gone
 * past the disk budget.
 *
 * Marked {@see UserFacingException}: every message this class carries is
 * built by {@see DiskBudget::ensureRoom()} from two byte counts, says how
 * much is missing, and names nothing internal. Do not construct it from
 * another exception's message.
 */
class InsufficientDiskSpaceException extends \RuntimeException implements UserFacingException
{
}
