<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * A refused upload, stated in French for the visitor who made it — a
 * missing file, a type that is not accepted, a file over the size limit,
 * an image too large to decode safely.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message this
 * class is constructed with is written for the person uploading.
 */
class UploadException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
