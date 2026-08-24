<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/** A file attached to a stay (schema.sql: camp_documents). */
class Document
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EMAIL = 'email';

    public function __construct(
        public readonly int $id,
        public readonly int $campId,
        public readonly string $title,
        public readonly int $fileId,
        public readonly int $sortOrder,
        public readonly string $source,
        public readonly ?string $sourceReference,
        public readonly string $createdAt
    ) {
    }

    /**
     * Whether deleting this document may also delete its file. An
     * e-mail's attachment belongs to the message it arrived in, which
     * still owns and serves it — removing the bytes here would blank the
     * message too.
     */
    public function ownsItsFile(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}
