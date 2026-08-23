<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

/**
 * A support package that could not be generated, stated in French for the
 * admin who asked for it.
 *
 * Marked {@see \Core\Exception\UserFacingException}.
 */
class SupportPackageException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
