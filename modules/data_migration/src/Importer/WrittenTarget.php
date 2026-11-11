<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * What {@see Importer::write()} created: a type name of the import's
 * choosing (`news_article`, `member_photo`) and its id, kept in the
 * correspondence table (D12).
 */
final class WrittenTarget
{
    public function __construct(
        public readonly string $type,
        public readonly string $id
    ) {
        if ($type === '' || strlen($type) > 50 || $id === '' || strlen($id) > 64) {
            throw new \InvalidArgumentException('A target is a type of 1 to 50 bytes and an id of 1 to 64.');
        }
    }
}
