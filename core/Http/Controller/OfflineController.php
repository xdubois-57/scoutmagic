<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Offline\OfflineManifestService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * Offline mode pre-download support. `GET /api/offline/photo-manifest`
 * (staff photos only) is superseded by the wider `/api/offline/manifest`
 * below — see Core\Offline\OfflineManifestService's own docblock.
 */
class OfflineController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private OfflineManifestService $offlineManifestService
    ) {
    }

    /**
     * GET /api/offline/manifest — every whitelisted page URL and every
     * image URL those pages render, filtered to what the caller's role
     * (and, for `/members/{id}`, linked members) actually entitles them
     * to. `role_min: public` on the route itself: the content returned is
     * already self-limiting (a guest gets only public pages/images, and
     * Core\Member\MemberService::getLinkedMembers() needs an email it
     * never has), so there is no reason to float this above the floor its
     * own content already enforces — same reasoning as `/calendar` itself
     * being `role_min: public`. The pre-download script that actually
     * calls this only ever runs for a signed-in, standalone-mode session
     * regardless (public/assets/js/offline-prefetch.js).
     *
     * @param array<string, string> $params
     */
    public function manifest(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $email = AuthSession::getEmail();

        return $this->json($this->offlineManifestService->buildManifest($role, $email));
    }
}
