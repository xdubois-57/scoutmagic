<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Card;

/**
 * The card the browser drew, checked before anything is done with it
 * (issue #706, IT-02).
 *
 * From IT-02 the browser composes what is published and posts it with the
 * form, so the server stops composing and starts CHECKING. This is that
 * check, and all of it: the format, the card's exact dimensions, and a
 * weight.
 *
 * **It is not a security boundary, and saying so matters more than
 * pretending otherwise.** A chief can already upload any image they like
 * — `CommunicationController::upload()` has always accepted a JPEG, a PNG
 * or a WebP of their choosing — so accepting an image they drew instead
 * grants no new power. What this check is for is the integrity of what
 * the site publishes and keeps: an Instagram container refused for its
 * proportions, a « card » that is really a 4000 px photo, a reply that is
 * not an image at all. Each of those is a failed publication explained by
 * nothing, and each is cheap to refuse here.
 *
 * **The dimensions are exact, not a minimum.** The card IS a square of
 * {@see CardRenderer::SIZE}: Instagram crops its grid to squares and
 * Facebook shows a square whole, and every card already published is one.
 * Accepting a different size would mean the published cards stop being
 * one format, which is the thing that lets the composer show « exactly
 * what will be published ».
 *
 * **The weight is a ceiling, not a target.** WhatsApp and some feed
 * readers skip a preview image that is too heavy, and a canvas export at
 * quality 0.88 lands far below this — a megabyte for a square photo is
 * already generous. A card past it is a sign that something else arrived.
 */
final class ReceivedCard
{
    /** Generous for a 1080² JPEG at quality 0.88, which lands nearer 200 kB. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * The only format accepted, and deliberately one: the card is drawn
     * by `canvas.toBlob(…, 'image/jpeg', 0.88)` and nothing else needs to
     * arrive here. A PNG of the same card would be several times the
     * weight for no gain on a photograph.
     */
    public const MIME = 'image/jpeg';

    /**
     * Checks the bytes, and says in French what is wrong — the sentence
     * reaches a chief who is about to publish.
     *
     * @throws CardException when the bytes are not a card this site can publish
     */
    public static function assertUsable(string $bytes): void
    {
        if ($bytes === '') {
            throw new CardException('L\'image à publier n\'est pas arrivée. Rechargez la page et réessayez.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $max = round(self::MAX_BYTES / 1024 / 1024, 1);
            throw new CardException(
                'L\'image à publier dépasse ' . $max . ' Mo. Rechargez la page et réessayez.'
            );
        }

        // Read from the bytes, never from anything the request declared:
        // a `Content-Type` is a claim, and this is the one place that
        // decides what the site is about to publish and keep.
        $size = @getimagesizefromstring($bytes);
        // `mime` is always set when this answers an array, so the check
        // is on the array itself and then on the type — no fallback,
        // which would be a branch no input can reach.
        if (!is_array($size) || $size['mime'] !== self::MIME) {
            throw new CardException('L\'image à publier doit être une image JPEG. Rechargez la page et réessayez.');
        }

        if ((int) $size[0] !== CardRenderer::SIZE || (int) $size[1] !== CardRenderer::SIZE) {
            throw new CardException(
                'L\'image à publier doit faire ' . CardRenderer::SIZE . ' pixels de côté. '
                . 'Rechargez la page et réessayez.'
            );
        }
    }

    /**
     * Whether these bytes are a card, without the sentence — for a caller
     * that only has to decide, not explain.
     */
    public static function isUsable(string $bytes): bool
    {
        try {
            self::assertUsable($bytes);

            return true;
        } catch (CardException) {
            return false;
        }
    }
}
