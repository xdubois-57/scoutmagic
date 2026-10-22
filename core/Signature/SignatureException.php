<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Exception\UserFacingException;

/**
 * A signature that could not be read, kept or used (issue #926).
 *
 * Marked {@see UserFacingException}: every message this class is built with
 * is a French sentence written for the person signing — what was wrong with
 * the image they drew or imported, and what to do next. Never built from
 * another exception's message: GD's and the filesystem's own words stay in
 * `$previous`.
 */
final class SignatureException extends \RuntimeException implements UserFacingException
{
}
