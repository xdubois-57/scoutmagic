<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\LinkLabelResolver;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * `POST /api/lien/libelle` — the « baguette magique » of the link dialog
 * every rich-text editor shares (public/assets/js/rich-text-link.js,
 * issue #840): given the address being linked, proposes the text to show.
 *
 * role_min identified: the editors live behind a sign-in, and the
 * per-account budget needs an account to count against. CSRF-checked like
 * every POST here (SECURITY.md §4). The answer is a label or null, and
 * nothing in it says why there is none — an unreadable page, a refused
 * address and a page without a title are the same « rien trouvé »
 * (SECURITY.md §17: no outward difference an SSRF probe could read).
 */
class LinkLabelController extends AbstractController
{
    public function __construct(Environment $twig, private readonly LinkLabelResolver $resolver)
    {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function suggest(Request $request, array $params): Response
    {
        $body = json_decode((string) $request->getRawBody(), true);
        $body = is_array($body) ? $body : [];

        if (($guard = $this->guardCsrfJson(
            $request,
            isset($body['_csrf_token']) && is_string($body['_csrf_token']) ? $body['_csrf_token'] : null
        )) !== null) {
            return $guard;
        }

        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $this->json(['success' => false, 'error' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
        if (!LinkLabelResolver::isFetchable($url)) {
            // Nothing to read, and nothing fetched — so nothing counted.
            return $this->json(['success' => true, 'label' => null]);
        }

        if (!$this->resolver->allow($userAccountId)) {
            return $this->json([
                'success' => false,
                'error' => 'Trop de demandes de titre en peu de temps. Réessayez dans quelques minutes, '
                    . 'ou tapez le texte du lien vous-même.',
            ], 429);
        }

        return $this->json(['success' => true, 'label' => $this->resolver->labelFor($url)]);
    }
}
