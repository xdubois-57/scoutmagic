<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\News\Service\TicketQrTokenService;
use Modules\News\Service\TicketService;
use Twig\Environment;

/**
 * The PNG of one ticket's QR, served to a mail client.
 *
 * **The only public route the whole ticketing feature has, and it is an
 * image rather than a page.** There is deliberately no `/billets/{jeton}`
 * page: the ticket lives in the buyer's e-mail and the control lives
 * behind a session (ARCHITECTURE.md §8.88). This exists for one reason —
 * the reminder before the event is a mail-merge, its body goes through
 * `Core\Security\HtmlSanitizer`, and that sanitizer refuses `data:` URLs,
 * so the QR the confirmation e-mail embeds inline cannot travel the same
 * way here. Finance's payment reminder solved exactly this (§8.84) and
 * this is the same mechanism applied unchanged.
 *
 * **`role_min: public`, and the token is the whole authorization.** A
 * mail client has no session: an image in an e-mail is fetched by a
 * program that has never logged in and never will. The token is an HMAC
 * of the reference under the installation's own key
 * (Service\TicketQrTokenService), compared in constant time.
 *
 * **What a valid link exposes is the reference, and only the reference**
 * — which the same mail prints in text right underneath, and which the
 * image encodes and nothing else: no name, no amount, no event, no other
 * ticket. That is a far smaller exposure than the finance image it
 * copies, and it is why this route needs no session to be defensible.
 *
 * The image is generated on the fly and never persisted: a QR is entirely
 * determined by the reference, like a photo's thumbnail is by the photo.
 */
class TicketQrController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TicketService $tickets,
        private TicketQrTokenService $tokens
    ) {
    }

    /**
     * GET /news/qr/{reference}/{token}
     *
     * @param array<string, string> $params
     */
    public function png(Request $request, array $params): Response
    {
        $canonical = TicketService::canonicalize((string) ($params['reference'] ?? ''));
        if ($canonical === null || !$this->tokens->isValid($canonical, (string) ($params['token'] ?? ''))) {
            // The same answer as a reference that does not exist: a wrong
            // token must not tell a prober which references are real.
            return new Response('Not Found', 404);
        }

        if ($this->tickets->findByReference($canonical) === null) {
            return new Response('Not Found', 404);
        }

        $png = TicketService::qrPng($canonical);

        return (new Response($png))
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Content-Length', (string) strlen($png))
            // Never a shared cache: the image is one family's ticket. It
            // never changes, but it is theirs.
            ->setHeader('Cache-Control', 'private, max-age=3600');
    }
}
