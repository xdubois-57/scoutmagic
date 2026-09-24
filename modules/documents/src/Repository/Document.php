<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Repository;

use Modules\Documents\Service\DocumentVisibility;

/**
 * One shared document, with what its screens show about the current
 * version's file (type, size) read in the same query.
 */
final class Document
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description,
        public readonly DocumentVisibility $visibility,
        public readonly int $fileId,
        public readonly int $sortOrder,
        public readonly string $updatedAt,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $originalName,
        /** The slug carries the random segment (created unlisted). */
        public readonly bool $slugIsRandom = false
    ) {
    }

    /** The address that is shared: it survives a new version. */
    public function path(): string
    {
        return '/documents/' . $this->slug;
    }

    /**
     * What kind of file this is, in the words of somebody who is about to
     * open it — never a MIME type.
     */
    public function kindLabel(): string
    {
        return match (true) {
            $this->mimeType === 'application/pdf' => 'PDF',
            str_starts_with($this->mimeType, 'image/') => 'Image',
            in_array($this->mimeType, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/csv',
            ], true) => 'Tableur',
            in_array($this->mimeType, [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
            ], true) => 'Présentation',
            $this->mimeType === 'text/plain' => 'Texte',
            default => 'Document',
        };
    }
}
