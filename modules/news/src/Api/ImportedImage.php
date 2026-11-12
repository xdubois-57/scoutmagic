<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * An image to store with an imported article: a file the caller wrote to a
 * temporary path, and deletes itself afterwards. The writer copies it
 * through the upload handler, which decides from its bytes what it is.
 */
final class ImportedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $name
    ) {
    }
}
