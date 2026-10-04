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
        /**
         * The content's own page on the site, absolute — the clickable link
         * a discussion group receives. Null when there is none (a free
         * communication).
         */
        public readonly ?string $pageUrl = null,
        /**
         * What the content ORIGINALLY is — `KIND_ALBUM` or
         * `KIND_ARTICLE` — when `kind` above says `KIND_COMMUNICATION`
         * because that is where the publications are recorded. Null when
         * the two would say the same thing.
         *
         * A saved source-backed share always carries
         * `kind = KIND_COMMUNICATION`, so anything deciding by `kind`
         * alone cannot tell an album's share from a free one. That is how
         * `PublishingService::refusal()` came to answer « Choisissez
         * d'abord une image » for an album without a cover — advice with
         * no button to obey it, the composer hiding both for a
         * source-backed share (raised in review on the pull request for
         * IT-01).
         */
        public readonly ?string $originKind = null,
        /**
         * How blurred this card's photo leaves — the composer's slider
         * (issue #706, IT-02). Carried here rather than passed through
         * every method because everything that draws this card already
         * has the source: the publication, the fallback preview, and the
         * discussion groups that receive the card too from IT-02.
         *
         * **Null is « never chosen »**, and the site's own starting
         * position applies. Zero is a chief who moved the slider to
         * « Net » on purpose, and the two must not be confused — which is
         * why the floor that made zero impossible had to go first.
         */
        public readonly ?float $blurRatio = null,
        /**
         * The card the browser drew and posted at « Publier », as stored
         * (issue #706, IT-02) — the bytes, like `image` above.
         *
         * **When this is set, nothing composes anything.** It is what
         * Instagram receives, what a discussion group receives, what a
         * retry resends and, from IT-04, what the public page shows. That
         * is how « what you saw is what left » holds across destinations
         * published minutes apart.
         *
         * Null for every share made before the browser drew one, and for
         * those the server composes a card as it always did: a retry of
         * an old failed share has to remain possible.
         */
        public readonly ?string $card = null,
    ) {
    }

    /**
     * The kind to speak about: what it originally is when that differs
     * from where its publications are recorded.
     */
    public function spokenKind(): string
    {
        return $this->originKind ?? $this->kind;
    }
}
