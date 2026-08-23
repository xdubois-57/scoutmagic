<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * A Desk CSV import that could not be read — an unreadable or empty file,
 * or missing column headers, each stated in French for the person who
 * chose the file.
 *
 * Marked {@see \Core\Exception\UserFacingException}.
 */
class ImportException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
