<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * An external album brought in from another site, as
 * {@see ExternalAlbumWriter::createImportedExternalAlbum()} creates it.
 *
 * Everything is decided by the caller: the title (the file's, or the
 * page's own when the file had none), the section, the scout year and the
 * author. The Open Graph fields are what the page said about itself when
 * the caller read it through `Core\Http\LinkPreviewFetcher`, or null when
 * it could not be read — the same three fields a chief's own external
 * album keeps (`og_title`, `og_description`, and a local copy of the
 * image).
 */
final class ImportedExternalAlbum
{
    /** The widths of the gallery_albums columns these land in (schema.sql). */
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_SUBTITLE_LENGTH = 255;
    public const MAX_URL_LENGTH = 500;

    /**
     * @param string $url the address as the old site gave it (kept as is; compared through the gallery's own normalisation)
     * @param string $albumDate `YYYY-MM-DD`
     * @param ?int $sectionId null for an album the whole unit sees
     * @param int $createdBy the user account of the administrator who imports
     * @param ?string $ogImageBytes the page's image, already downloaded by the SSRF-checked fetcher
     */
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $albumDate,
        public readonly ?int $sectionId,
        public readonly int $scoutYearId,
        public readonly int $createdBy,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?string $ogImageBytes = null
    ) {
    }
}
