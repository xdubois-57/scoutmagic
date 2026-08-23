<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Exception\UserFacingException;

/**
 * A rule this module enforces was broken. Its message is user-facing French
 * (a Controller renders it straight back into a flash message), so it must
 * never carry personal data, an internal id, or a technical detail
 * (SECURITY.md §5 — no personal data in error messages).
 */
class RentalException extends \RuntimeException implements UserFacingException
{
}
