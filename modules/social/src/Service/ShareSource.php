<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

/**
 * Something the site can publish, whatever module it came from: an album,
 * an article, and from IT-04 a free communication.
 *
 * - `image` is the background of the card; `imageFromGallery` says whether
 *   it must be blurred — and if it came from the gallery, it is.
 * - `link` is set when Facebook should receive a link post rather than an
 *   image post (an article: its Open Graph preview is the richer post).
 * - `address` is what the card prints at its foot, without a scheme.
 * - `blockedReason` is set when the source cannot leave the site at all —
 *   an article whose visibility keeps it among the staff.
 */
final class ShareSource
{
    public const KIND_ALBUM = 'album';
    public const KIND_ARTICLE = 'article';
    public const KIND_COMMUNICATION = 'communication';

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $image,
        public readonly bool $imageFromGallery,
        public readonly ?string $link,
        public readonly string $address,
        public readonly string $defaultCaption,
        public readonly string $backPath,
        public readonly ?string $blockedReason = null,
    ) {
    }
}
