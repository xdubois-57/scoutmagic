<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/** One note of the preview: information or a warning, a French sentence. */
final class PreviewNote
{
    public const INFO = 'info';
    public const WARNING = 'warning';

    private function __construct(
        public readonly string $level,
        public readonly string $text
    ) {
    }

    public static function info(string $text): self
    {
        return new self(self::INFO, $text);
    }

    public static function warning(string $text): self
    {
        return new self(self::WARNING, $text);
    }
}
