<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\InsecureBrowserAccess;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * POST /api/connexion-non-securisee — the fallback of the browser signal
 * (#751).
 *
 * Every same-origin request from api.js already carries
 * {@see InsecureBrowserAccess::HEADER}, recorded by public/index.php once
 * the response is sent. This route exists for the one case that leaves
 * no trace: a page loaded outside a secure context that makes no request
 * of its own. api.js sends it with `navigator.sendBeacon()`, which cannot
 * set a header — hence a route — and only from an insecure page, so a
 * site served over HTTPS never sees a single call.
 *
 * Authenticated (`identified`) and CSRF-checked like any other write: the
 * observation counts only from a session, and a cross-site page cannot
 * make one up for somebody else's.
 */
final class SecureContextController extends AbstractController
{
    public function __construct(Environment $twig, private readonly InsecureBrowserAccess $access)
    {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'error' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        // The beacon is the « not secure » statement itself (it cannot
        // carry a header); observe() applies the same rules as for every
        // other request. The route is `identified`, hence authenticated.
        $this->access->observe(
            [InsecureBrowserAccess::SERVER_KEY => '0'],
            AuthSession::isAuthenticated(),
            time()
        );

        return new Response('', 204);
    }
}
