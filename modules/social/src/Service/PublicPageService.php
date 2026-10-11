<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\CommunicationRepository;

/**
 * The public page of a communication (issue #706, IT-04): where it lives,
 * when it comes into being, and which communication an address names.
 *
 * **Why the site needs one at all.** Facebook does not publish a photo
 * and a caption for an album: it publishes a LINK, and builds its own
 * preview from the Open Graph tags of the page that link points at. The
 * album's own page is `identified` and stays that way, so it is not that
 * page — a crawler signs in nowhere, and the unit's photos are not public
 * because one of them was shared. This page is what Facebook may read: the
 * card, the title and the text that were published, and nothing else.
 *
 * **It is not the hour-long card route.** `Card\CardService` serves
 * Instagram a composition for sixty minutes at `/partage/carte/{token}`,
 * `no-store`, and lets it expire — Meta fetches the media once, at the
 * moment of publishing. Facebook caches a preview instead and comes back
 * to re-read the page days later, so this page and its image have to
 * still be there. The two routes look alike and answer opposite needs;
 * {@see \Modules\Social\Controller\PublicPageController} says the same
 * next to the headers.
 *
 * **The token is the authorisation**, 32 bytes from `random_bytes()`
 * (SECURITY.md § 6). Never an identifier a passer-by could increment:
 * `/partage/1`, `/partage/2` would be a way to read every communication
 * the unit ever shared, which is the opposite of sharing one.
 */
final class PublicPageService
{
    /** Where a public page lives. The token follows, and nothing else. */
    public const ROUTE_PREFIX = '/partage/';

    /**
     * What the page's own image answers to, under the same token.
     *
     * The literal `.jpg` is not decoration: `Core\Http\Router` quotes the
     * literal parts of a declared path and stops at the first route that
     * answers, so `/partage/{token}/carte.jpg` cannot collide with
     * `/partage/carte/{token}` — which `/partage/{token}/image` could, on
     * the one address `/partage/carte/image`. It also gives Facebook a
     * URL that names what it is fetching.
     */
    public const IMAGE_SUFFIX = '/carte.jpg';

    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly SettingService $settings,
        private readonly JournalService $journal,
    ) {
    }

    /**
     * Whether a public page can exist at all: the site has to know its
     * own address, because what goes to Facebook is an absolute URL and
     * nothing can make one up from a request.
     */
    public function isAvailable(): bool
    {
        return $this->baseUrl() !== '';
    }

    /**
     * The page's address, if this communication already has one. **Reads,
     * never mints**, so every screen that merely shows a communication
     * can ask.
     */
    public function urlFor(Communication $communication): ?string
    {
        $base = $this->baseUrl();

        return $communication->publicToken === null || $base === ''
            ? null
            : $base . self::ROUTE_PREFIX . $communication->publicToken;
    }

    /**
     * The page's address, bringing it into being if this is the first
     * share that needs one.
     *
     * **Called from the publishing path and nowhere else.** The roadmap
     * says the page is created at the first share that needs it, not
     * before, and « not before » includes every time the composer is
     * merely opened or previewed: a page that exists is a page that stays
     * (nothing in this module removes one), so minting one on a page view
     * would leave a permanent public address behind a draft nobody ever
     * published.
     *
     * **And after the destination was claimed**, which is what keeps a
     * page from outliving an attempt that never happened: `publish()`
     * refuses a destination — no usable connection, a caption over
     * Instagram's limit, an album with no cover — before `claim()`
     * records anything, and `send()` only runs once a row exists. The
     * page therefore comes into being exactly where the freeze does, on
     * a recorded attempt, succeeded or failed; a retry of a failed one
     * finds the page already there and publishes the same address.
     *
     * Null when the site does not know its own address, and then nothing
     * is minted either: a token whose URL cannot be built authorises
     * nothing and would only be in the way the day `base_url` is filled
     * in.
     */
    public function ensureUrlFor(int $communicationId, \DateTimeImmutable $now): ?string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return null;
        }

        $communication = $this->communications->find($communicationId);
        if ($communication === null) {
            return null;
        }

        if ($communication->publicToken !== null) {
            return $base . self::ROUTE_PREFIX . $communication->publicToken;
        }

        $token = bin2hex(random_bytes(32));
        $this->communications->assignPublicToken($communicationId, $token, $now);

        // Read back rather than trusting the token just generated: the
        // UPDATE only writes `WHERE public_token IS NULL`, so a second
        // request publishing the same communication at the same instant
        // finds the page the first one minted instead of handing out an
        // address that was never stored. Whichever token the row carries
        // is the right answer; the one in hand may not be.
        $stored = $this->communications->find($communicationId)->publicToken ?? $token;

        $this->journal->log(
            'social',
            'public_page_created',
            'security',
            'Page publique créée pour une communication partagée',
            ['communication_id' => $communicationId]
        );

        return $base . self::ROUTE_PREFIX . $stored;
    }

    /** The address of the page's own image, for the `og:image` tag. */
    public function imageUrlFor(Communication $communication): ?string
    {
        $url = $this->urlFor($communication);

        return $url === null ? null : $url . self::IMAGE_SUFFIX;
    }

    /**
     * The communication an address names, or null — unknown, malformed
     * and never minted all answer the same, so nothing can be learned
     * from which it was.
     *
     * The shape is checked before the database is asked, like the card
     * route's: a token is 64 hexadecimal characters or it is not a token.
     */
    public function findByToken(string $token): ?Communication
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->communications->findByPublicToken($token);
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->settings->get('base_url') ?: ''), '/');
    }
}
