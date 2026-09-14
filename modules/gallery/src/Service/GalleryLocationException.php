<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Exception\UserFacingException;

/**
 * A refusal from {@see GalleryLocationService} whose message is written
 * for the administrator reading it.
 *
 * `UserFacingException` is the contract and it is a narrow one: every
 * message this class ever carries is a full French sentence, written by
 * hand, naming nothing internal. None is built from another exception's
 * `getMessage()`.
 */
class GalleryLocationException extends \RuntimeException implements UserFacingException
{
}
