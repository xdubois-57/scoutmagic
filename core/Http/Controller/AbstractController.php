<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\CsrfGuard;
use Twig\Environment;

abstract class AbstractController
{
    /**
     * The ONE user-facing sentence for a stale/invalid CSRF token,
     * everywhere. Before it existed the same failure spoke in 100+
     * voices: « Jeton CSRF invalide. » (108 occurrences across 44
     * controllers), « Session expirée » in four different phrasings (19),
     * 13 plain-text « Jeton CSRF invalide. » bodies in the groups module
     * and 17 English "Forbidden" bodies in rental. A section chief does
     * not know what a CSRF token is, and none of those messages said what
     * to actually do — this one does. Use guardCsrf()/guardCsrfJson()
     * rather than repeating the constant in new code.
     */
    public const SESSION_EXPIRED_MESSAGE = 'Votre session a expiré. Rechargez la page et réessayez.';

    public function __construct(protected Environment $twig)
    {
    }

    /**
     * The single way a classic form POST fails on a stale CSRF token:
     * flash the one explanation a non-technical user can act on, then
     * send them back to the page the form lives on. Returns null when the
     * token is valid — the idiom at every call site is:
     *
     *     if (($guard = $this->guardCsrf($request, '/page')) !== null) {
     *         return $guard;
     *     }
     *
     * The token is looked for wherever this codebase has ever put it: the
     * form body's `_csrf_token` field (CsrfGuard::validateToken() call
     * sites), then the raw superglobals — body field or X-CSRF-Token
     * header (CsrfGuard::validateRequest() call sites). An endpoint whose
     * token lives elsewhere (a JSON payload, typically) passes it
     * explicitly as $token.
     */
    protected function guardCsrf(Request $request, string $redirectTo, ?string $token = null): ?Response
    {
        if ($this->isCsrfValid($request, $token)) {
            return null;
        }

        FlashMessage::set('error', self::SESSION_EXPIRED_MESSAGE);

        return $this->redirect($redirectTo);
    }

    /**
     * guardCsrf()'s twin for AJAX/JSON endpoints: same single message,
     * delivered as the `{success: false, error: …}` + 403 shape the
     * frontend toolbox already understands. Same null-means-valid idiom,
     * same optional $token for endpoints that carry the token in a JSON
     * payload rather than the form body or header.
     */
    protected function guardCsrfJson(Request $request, ?string $token = null): ?Response
    {
        if ($this->isCsrfValid($request, $token)) {
            return null;
        }

        return $this->json(['success' => false, 'error' => self::SESSION_EXPIRED_MESSAGE], 403);
    }

    /**
     * A request proves itself with a valid token from any of the places
     * the app delivers one: an explicitly extracted token (JSON payloads),
     * the parsed body's `_csrf_token` field, or the raw superglobals
     * (form field / X-CSRF-Token header) via CsrfGuard::validateRequest().
     * An invalid token never passes; accepting every legitimate transport
     * is what lets ~160 hand-rolled checks collapse into one.
     */
    private function isCsrfValid(Request $request, ?string $token): bool
    {
        if ($token !== null && CsrfGuard::validateToken($token)) {
            return true;
        }

        if (CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return true;
        }

        return CsrfGuard::validateRequest();
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = []): Response
    {
        $html = $this->twig->render($template, $context);
        return new Response($html);
    }

    /**
     * A template rendered to a string rather than to a Response — for a
     * controller assembling several fragments into one payload.
     *
     * The point of having it here is that such a controller renders the
     * SAME partials the full page does, instead of growing a second,
     * JavaScript-side implementation of whatever the page shows.
     *
     * @param array<string, mixed> $context
     */
    protected function renderToString(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return (new Response())->setStatusCode($statusCode)->setHeader('Location', $url);
    }

    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return (new Response(json_encode($data), $statusCode))
            ->setHeader('Content-Type', 'application/json');
    }
}
