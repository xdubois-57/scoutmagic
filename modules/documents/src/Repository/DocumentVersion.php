<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Repository;

/**
 * A version a document used to have (schema.sql, document_versions).
 * Its file is readable by the Staff d'U only, whatever the document's
 * visibility.
 */
final class DocumentVersion
{
    public function __construct(
        public readonly int $id,
        public readonly int $documentId,
        public readonly int $versionNumber,
        public readonly int $fileId,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
        public readonly ?int $uploadedBy,
        public readonly string $archivedAt
    ) {
    }
}
