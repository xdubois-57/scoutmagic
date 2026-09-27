<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held;

/**
 * A document just put aside (HeldDocumentService::hold()), with the two
 * clear keys the viewer page needs. They exist only in this object and in
 * the page built from it: the database keeps their hashes.
 */
final class HeldDocument
{
    public function __construct(
        public readonly int $id,
        public readonly string $browserToken,
        public readonly string $appToken,
        public readonly string $name,
        public readonly string $mimeType,
        public readonly int $sizeBytes
    ) {
    }

    /** « Ouvrir dans le navigateur »: one use, no session. */
    public function browserPath(): string
    {
        return HeldDocumentService::BROWSER_ROUTE_PREFIX . $this->browserToken;
    }

    /** « Télécharger » and the image preview: this session only. */
    public function appPath(): string
    {
        return HeldDocumentService::APP_ROUTE_PREFIX . $this->appToken;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
