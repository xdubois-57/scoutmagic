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

    /**
     * Whether there is an image to publish at all — **the kept card
     * counts**, and counts first.
     *
     * The two refusal gates (`PublishingService::refusal()` and
     * `GroupPublishingService`'s match) predate the kept card and tested
     * `image` alone, while `cardBytes()` and
     * `GroupPublishingService::card()` serve the card without ever
     * touching `image`. So a FROZEN share whose live cover disappeared
     * afterwards — the album's cover photo deleted, its storage briefly
     * unreadable — was refused on every remaining destination with
     * « Cet album n'a pas encore de photo de couverture », although the
     * card it would have sent was sitting right there. And `frozen`
     * disables the composer's image buttons, so the chief had no way
     * out: the destination stayed refused for good.
     *
     * One method rather than the same condition twice, because that
     * duplication is exactly what let the two gates fall behind the
     * card. Found in the review of #850.
     */
    public function hasImageToPublish(): bool
    {
        return ($this->card !== null && $this->card !== '')
            || ($this->image !== null && $this->image !== '');
    }

    /**
     * Whether Facebook receives a **link** post rather than a photo post
     * — the whole of that decision, in one place (issue #706, IT-04).
     *
     * Three cases, and the roadmap names all three:
     *
     * - an **article** carries `link`, its own public address, whose
     *   Open Graph tags the news module already emits (IT-03);
     * - an **album** has no public address of its own and never will —
     *   it stays `identified` — so what Facebook is pointed at is the
     *   communication's public page
     *   ({@see \Modules\Social\Service\PublicPageService});
     * - a communication written from **nothing** has no page to offer and
     *   goes as a photo post, as it always did.
     *
     * One predicate rather than a condition repeated, because the
     * composer has to say which of the two will happen BEFORE anything is
     * published, and the publisher has to do what was said. Keying the
     * composer's sentence on `link` alone was right while only an article
     * went as a link; for an album `link` is still null at that moment,
     * since the public page is minted at the first share that needs it
     * and not when a draft is opened. Two rules would have drifted apart
     * on exactly that gap.
     *
     * **It asks about the CONTENT, never about the shape this object
     * happens to be in**, and that distinction is the fix for a bug this
     * predicate had on its first day. It carried
     * `kind === KIND_COMMUNICATION`, to keep the publisher from minting a
     * page against an album's id — a real concern, but the wrong place
     * for it. The composer opened from « Partager » holds the PREFILL
     * shape (`kind = KIND_ALBUM`, built by `ShareSourceResolver::album()`
     * before any row exists), so the sentence read « Voici exactement ce
     * qui sera publié » while `store()`, in the very same request,
     * created the row, re-resolved it as a communication and sent
     * Facebook a link. The one promise this predicate exists to keep,
     * broken on the first share of every album. Found in review on the
     * pull request for IT-04.
     *
     * **This is what the composer shows; the publisher adds what only it
     * can know** — a communication row to own the page, and a kept card
     * for the page to show
     * ({@see \Modules\Social\Service\PublishingService::sendsFacebookLink()}).
     * So the sentence is optimistic exactly once: a share not yet saved
     * promises a link because the browser normally posts its card with
     * « Publier ». A browser that cannot draw one gets a photo post
     * carrying the card the server composes, which beats a link to a page
     * with no image on it.
     */
    public function facebookReceivesLink(): bool
    {
        return $this->link !== null || $this->spokenKind() === self::KIND_ALBUM;
    }
}
