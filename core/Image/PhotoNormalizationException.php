<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Image;

use Core\File\UploadException;

/**
 * A photo `PhotoNormalizer` refused to store: a format this server cannot
 * read (HEIC without an Imagick that reads it), bytes that do not decode,
 * an image too large to decode safely, an upload that never arrived.
 *
 * An `UploadException` on purpose: every upload surface already catches
 * that class and shows its message, so a refused photo reads like any
 * other refused file instead of becoming a 500.
 *
 * Marked user-facing through its parent: every message it is constructed
 * with is a French sentence written for the person who took the photo, and
 * names no file path, no library and no MIME type.
 */
class PhotoNormalizationException extends UploadException
{
}
