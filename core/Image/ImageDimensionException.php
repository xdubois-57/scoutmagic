<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Image;

/**
 * Thrown when an image's pixel dimensions exceed the decode ceiling
 * (Core\Image\ImageDimensionGuard) — i.e. a decompression-bomb input that
 * would allocate an unsafe amount of memory if decoded.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message is a
 * French sentence written for the visitor.
 */
class ImageDimensionException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
