<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * "Configuration générale" used to also carry the module registry and
 * badges — both split out to their own single-concern controllers
 * (Core\Http\Controller\ConfigModulesController/ConfigBadgesController,
 * AGENTS.md). What's left is only the configuration-mode toggle, which is
 * why this page moved from the Configuration menu (superadmin) to
 * "Espace chefs d'U" (admin) — see Core\View\ConfigurationMode and
 * Core\Http\Controller\ConfigModeController for the actual activate/
 * deactivate routes and their own widened role_min.
 */
class ConfigGeneralController extends AbstractController
{
    public function __construct(
        protected Environment $twig
    ) {
    }

    /**
     * GET /config/general — render the configuration-mode toggle page.
     * URL kept unchanged even though the menu it's reachable from moved —
     * nothing forces this specific route to change.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('config/general.html.twig', []);
    }
}
