<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held;

/**
 * A held document's decrypted content, as one of its two routes serves it.
 */
final class OpenedDocument
{
    public function __construct(
        public readonly string $content,
        public readonly string $name,
        public readonly string $mimeType
    ) {
    }
}
