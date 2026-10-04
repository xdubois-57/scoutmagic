<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\InsecureBrowserAccess;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * The two writes of the secure-context state (#751).
 *
 * `POST /api/connexion-non-securisee` (`report`) — the fallback of the
 * browser signal. Every same-origin request from api.js already carries
 * {@see InsecureBrowserAccess::HEADER}, recorded by public/index.php once
 * the response is sent. This route exists for the one case that leaves
 * no trace: a page loaded outside a secure context that makes no request
 * of its own. api.js sends it with `navigator.sendBeacon()`, which cannot
 * set a header — hence a route — and only from an insecure page, so a
 * site served over HTTPS never sees a single call.
 *
 * **Public and without a CSRF token, deliberately** (SECURITY.md § 4).
 * The page that sends it was loaded over `http://`, where the `Secure`
 * session cookie does not travel: there is no session, so there is no
 * token to check. What replaces it is the beacon's `Origin`, which must
 * name this site ({@see InsecureBrowserAccess::isSameOrigin()}), so a page
 * of another site cannot make visitors' browsers raise the alert.
 *
 * `POST /config/maintenance/connexion-securisee/ignorer` (`dismiss`) —
 * « Ignorer » on Santé de l'hébergement, superadmin and CSRF-checked like
 * every other form: the way out of a false statement, since the signal
 * cannot be proven.
 */
final class SecureContextController extends AbstractController
{
    /** Santé de l'hébergement, where « Ignorer » is offered. */
    private const HEALTH_PAGE = '/config/maintenance';

    public function __construct(
        Environment $twig,
        private readonly InsecureBrowserAccess $access,
        private readonly ?JournalService $journal = null
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $origin = $request->getServer('HTTP_ORIGIN');
        $host = $request->getServer('HTTP_HOST');
        if (!InsecureBrowserAccess::isSameOrigin(
            is_string($origin) ? $origin : null,
            is_string($host) ? $host : null
        )) {
            // JSON, like a CSRF refusal: nothing was written, and the
            // authorization matrix reads the shape, not the status.
            return $this->json(['success' => false], 403);
        }

        // The beacon is the « not secure » statement itself (it cannot
        // carry a header); observe() applies the same rules as for every
        // other request.
        $this->access->observe([InsecureBrowserAccess::SERVER_KEY => '0'], time());

        return new Response('', 204);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismiss(Request $request, array $params): Response
    {
        $back = self::HEALTH_PAGE . '#host-check-secure_connection';
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $this->access->dismiss();
        $this->journal?->log(
            'core',
            'insecure_access_dismissed',
            'security',
            'Signalement d\'accès non sécurisé ignoré',
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set(
            'success',
            'Le signalement est effacé. Il reviendra si un navigateur charge encore le site sans connexion sécurisée.'
        );

        return $this->redirect($back);
    }
}
