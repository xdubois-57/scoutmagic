<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * One line another module adds to an album's management page to explain why it is offering
 * NO action — a button that disappears without a word is not acceptable
 * (docs/chantiers/CHANTIER-medias-sociaux.md).
 *
 * The link is optional and is the provider's own business: it is rendered
 * only when the provider supplies one, which is how this page avoids
 * offering a chief a link to a configuration screen they may not open.
 */
final class AlbumNote
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $linkLabel = null,
        public readonly ?string $linkUrl = null,
    ) {
    }
}
