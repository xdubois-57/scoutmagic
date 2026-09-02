<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Security\EncryptionService;

/**
 * The unguessable key to one ticket's QR image, for the reminder before
 * the event.
 *
 * **Why an image URL at all**, when the confirmation e-mail embeds the
 * same QR as a `data:` URI: a mail-merge body goes through
 * `Core\Security\HtmlSanitizer`, whose URL allowlist is `http`, `https`,
 * `mailto` and `tel` — `cid:` and `data:` are both refused, and widening
 * that list would reopen exactly the hole its own comment describes
 * closing. So the QR travels the way finance's payment reminder already
 * sends one (ARCHITECTURE.md §8.84,
 * `Modules\Finance\Service\ReceivableQrTokenService`): an absolute https
 * URL, served by a route a mail client can reach with no session.
 *
 * **Derived, never stored** — an HMAC of the canonical reference under
 * the installation's blind-index key, the same primitive that already
 * looks up an encrypted value without decrypting it. Nothing to persist,
 * nothing to purge, no expiry to get wrong, and the same ticket always
 * yields the same URL, so an archived copy of a sent mail shows the image
 * that went out.
 *
 * **What the token actually buys**, since the URL carries the reference
 * anyway: it stops the route being an oracle. Without it, anybody could
 * ask whether a reference exists; with it, a caller who does not already
 * hold the ticket's own URL learns nothing. The image itself encodes the
 * reference and nothing more — no name, no amount, no event — which is
 * why this is a far smaller exposure than the finance one it copies.
 *
 * **This is the only public route the whole ticketing feature has**, and
 * it is an image rather than a page. There is deliberately no
 * `/billets/{jeton}` page: the ticket lives in the buyer's e-mail and
 * the control lives behind a session (ARCHITECTURE.md §8.88).
 */
class TicketQrTokenService
{
    private const PURPOSE = 'news_ticket_qr';

    public function __construct(private EncryptionService $encryption)
    {
    }

    public function tokenFor(string $canonicalReference): string
    {
        return $this->encryption->blindIndex($canonicalReference, self::PURPOSE);
    }

    /**
     * Constant-time, so a wrong token cannot be narrowed down by how long
     * the refusal takes.
     */
    public function isValid(string $canonicalReference, string $token): bool
    {
        return hash_equals($this->tokenFor($canonicalReference), $token);
    }

    /**
     * The absolute URL of one ticket's QR image, or null when the base
     * URL is not configured — a relative `src` in a mail is a broken
     * image, and a broken image is worse than none.
     */
    public function urlFor(string $canonicalReference, string $baseUrl): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl . '/news/qr/' . rawurlencode($canonicalReference) . '/' . $this->tokenFor($canonicalReference);
    }
}
