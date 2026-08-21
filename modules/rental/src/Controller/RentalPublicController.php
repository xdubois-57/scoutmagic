<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Twig\Environment;

/**
 * The public face of the module: the "Locations" index and one page per
 * asset. `role_min: public`, so everything here is readable by an anonymous
 * visitor and the confidentiality rules are absolute rather than
 * configurable.
 *
 * **Never rendered here** (roadmap §6.6): a manager's name, phone or email;
 * the asset's emergency phone; anything at all about a renter or a booking;
 * a price actually paid; a document; an access code; an internal comment.
 * The emergency phone and the flagged managers' details are reachable only
 * from a renter's own tracking page, which is a different controller behind
 * a capability token.
 *
 * The "Gérer ce bien" button is rendered conditionally, and that condition
 * is **presentation only** — the managed space re-checks authority
 * server-side on every request (ARCHITECTURE.md §12).
 */
class RentalPublicController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private RentalAssetRepository $assetRepository,
        private RentalAuthorizationService $authorizationService,
        private ScoutYearService $scoutYearService
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /locations — every public asset, pinned to the menu or not.
     *
     * This page exists as soon as one public asset does. That is not a
     * detail: an asset that is public but not pinned has no menu entry of
     * its own, so without this index it would be reachable only by someone
     * who already knew its URL (roadmap §6.2).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@rental/public/index.html.twig', [
            'assets' => $this->assetRepository->findAllPublic(),
        ]);
    }

    /**
     * GET /locations/{slug}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $asset = $this->assetRepository->findBySlug((string) ($params['slug'] ?? ''));

        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $email = AuthSession::getEmail();
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $canManage = $this->authorizationService->canManageAsset($email, $scoutYearId, $asset);

        // A non-public or archived asset stays reachable by its own
        // managers — that is how they get to the managed space for an asset
        // they have deliberately taken off the public site — and is a plain
        // 404 for everyone else. Not a 403: telling an anonymous visitor
        // "this exists but you may not see it" is itself a disclosure.
        if (!$asset->isPubliclyVisible() && !$canManage) {
            return new Response('Not Found', 404);
        }

        return $this->render('@rental/public/show.html.twig', [
            'asset' => $asset,
            'can_manage' => $canManage,
            'breadcrumb_current' => $asset->name,
        ]);
    }
}
