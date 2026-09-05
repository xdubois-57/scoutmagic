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

    /**
     * What errors/403.html.twig says when a refusal carries no sentence
     * of its own. Kept here rather than only in the template so the JSON
     * shape of the same refusal says exactly the same thing.
     */
    public const FORBIDDEN_MESSAGE = "Vous n'avez pas les permissions nécessaires pour accéder à cette page.";

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

    /**
     * The site's own 404 page, for a route that exists but whose subject
     * does not — a camp id nobody has, a document that was deleted while
     * the page was open.
     *
     * **The same page the router serves for an unknown path** (`errors/
     * 404.html.twig`), deliberately: a visitor who mistypes an id must not
     * land on a bare "Not Found" with no navigation, no site name and no
     * way back, which is what three controllers were handing them. It is
     * also what makes "no such camp" and "not your camp" indistinguishable
     * from outside, which is the point — a different-looking refusal maps
     * out which ids exist.
     *
     * A JSON endpoint answers with `json([...], 404)` instead; this one
     * renders HTML.
     */
    protected function notFound(): Response
    {
        return $this->render('errors/404.html.twig')->setStatusCode(404);
    }

    /**
     * The site's own 403 page, carrying the ONE French sentence that says
     * why — the twin of notFound() above, and the answer to a refusal a
     * visitor can actually read.
     *
     * A plain `new Response('…', 403)` renders that sentence as the whole
     * document: no stylesheet, no theme, no navigation. In the installed
     * PWA, where there is no browser chrome either, that is a bare black
     * sentence on a white page — in the middle of a dark-mode session,
     * with no way back other than the phone's gesture. The message is
     * worth keeping; the plain-text body never was.
     *
     * Pass $request whenever the route is also reachable by fetch(): the
     * refusal then comes back as `{error: …}` + 403, which every AJAX
     * caller in this codebase already knows how to show inline. Without
     * it — or from a plain form POST — the HTML page is rendered instead.
     */
    protected function forbidden(string $message = '', ?Request $request = null): Response
    {
        if ($request !== null && $this->expectsJson($request)) {
            return $this->json(['error' => $message !== '' ? $message : self::FORBIDDEN_MESSAGE], 403);
        }

        return $this->render('errors/403.html.twig', ['message' => $message])->setStatusCode(403);
    }

    /**
     * Does this caller want its refusal as JSON rather than as a page?
     *
     * Both spellings of the header this codebase actually sends are
     * accepted (`XMLHttpRequest` and `fetch` — see public/assets/js/), as
     * is an explicit `Accept: application/json`, so a refusal never
     * depends on which of the two idioms a given script happened to use.
     */
    private function expectsJson(Request $request): bool
    {
        $requestedWith = (string) $request->getServer('HTTP_X_REQUESTED_WITH', '');
        if ($requestedWith === 'XMLHttpRequest' || $requestedWith === 'fetch') {
            return true;
        }

        // Media types are case-insensitive (RFC 9110 8.3.1), and the header
        // is the caller's, not ours: `Application/JSON` has to count.
        $accept = strtolower((string) $request->getServer('HTTP_ACCEPT', ''));

        return str_contains($accept, 'application/json');
    }

    /**
     * A label map as `<select>` options.
     *
     * Built in PHP rather than in Twig because turning a hash into an
     * ordered list of option rows is data preparation, and a template
     * doing it ends up depending on which filters this Twig version
     * happens to ship. `partials/form_field.html.twig` takes exactly this
     * shape.
     *
     * @param array<string, string> $labels value => label, in the order
     *        they should appear
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    protected function options(array $labels, string $selected): array
    {
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label, 'selected' => (string) $value === $selected];
        }

        return $options;
    }
}
