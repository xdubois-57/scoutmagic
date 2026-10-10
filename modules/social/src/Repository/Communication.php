<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

/**
 * What the composer publishes: a title written on the card, the text of
 * the post, and one image.
 *
 * The image is either its own — a gallery photo or an uploaded file, never
 * both — or it comes from the SOURCE this communication shares, in which
 * case neither of its own columns is set and
 * {@see \Modules\Social\Service\ShareSourceResolver} reads the image from
 * that source at every use.
 *
 * `sourceKind` and `sourceId` are what « Partager » on an album or an
 * article remembered (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01),
 * and both are null for a communication written from nothing — which has
 * no link to offer either.
 */
final class Communication
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $galleryMediaId,
        public readonly ?int $fileId,
        public readonly ?int $createdBy,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?string $sourceKind = null,
        public readonly ?int $sourceId = null,
        /**
         * How blurred this card's photo leaves — the composer's slider,
         * frozen with the rest once one destination has been tried
         * (issue #706, IT-02).
         *
         * **Null is « never chosen »**, not zero: every row written
         * before the slider existed carries null, and the site's own
         * starting position applies to them. Zero is a real answer —
         * « Net » — and telling the two apart is the whole reason this is
         * nullable.
         */
        public readonly ?float $blurRatio = null,
        /**
         * The card the browser drew and posted at « Publier », stored as
         * it arrived (issue #706, IT-02).
         *
         * Null for every share made before the browser drew anything —
         * and for those the server still composes a card, so that a retry
         * of an old failed share remains possible. `CardRenderer` has
         * left the path a NEW share takes; it has not left the
         * repository.
         */
        public readonly ?int $cardFileId = null,
    ) {
    }

    /**
     * Whether this communication carries an image OF ITS OWN. A
     * source-backed one answers false and still has an image — the
     * source's — which is why the resolver, not this method, decides what
     * gets published.
     */
    public function hasImage(): bool
    {
        return $this->galleryMediaId !== null || $this->fileId !== null;
    }

    /** What this communication shares, when it was opened from somewhere. */
    public function hasSource(): bool
    {
        return $this->sourceKind !== null && $this->sourceId !== null;
    }
}
