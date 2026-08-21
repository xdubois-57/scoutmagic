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
use Modules\Rental\Service\RentalAuthorizationService;
use Twig\Environment;

/**
 * The managers' own space. Its routes are `role_min: identified` — as low as
 * a logged-in visitor gets — because a manager is explicitly not required to
 * be a chief (roadmap §6.3). The route guard therefore grants almost
 * nothing, and **Service\RentalAuthorizationService is the actual
 * protection**, re-checked server-side on every action.
 *
 * "Mes locations" (§6.5) is the daily entry point: every asset the visitor
 * manages, all assets at once, rather than making them navigate the public
 * site to reach their own work.
 */
class RentalManagementController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private RentalAuthorizationService $authorizationService,
        private ScoutYearService $scoutYearService
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /mes-locations
     *
     * @param array<string, string> $params
     */
    public function myRentals(Request $request, array $params): Response
    {
        $email = AuthSession::getEmail();
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $assets = $this->authorizationService->listManageableAssets($email, $scoutYearId);

        // Someone who manages nothing gets a plain 403 rather than an empty
        // page: the menu entry is not shown to them at all, so reaching this
        // URL means the id came from somewhere other than the interface.
        if ($assets === []) {
            return new Response('Forbidden', 403);
        }

        return $this->render('@rental/management/my_rentals.html.twig', [
            'assets' => $assets,
            'is_unit_staff' => $this->authorizationService->isUnitStaff($email, $scoutYearId),
        ]);
    }
}
