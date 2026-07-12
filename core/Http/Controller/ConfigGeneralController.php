<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Module\ModuleException;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

class ConfigGeneralController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ModuleManager $moduleManager
    ) {
    }

    /**
     * GET /config/general — render the configuration page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $modules = $this->moduleManager->discoverModules();

        return $this->render('config/general.html.twig', [
            'modules' => $modules,
        ]);
    }

    /**
     * POST /config/general/module-toggle — activate/deactivate a module (AJAX).
     *
     * @param array<string, string> $params
     */
    public function toggleModule(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $moduleId = (string) ($data['module_id'] ?? '');
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($moduleId === '') {
            return $this->json(['success' => false, 'error' => 'Identifiant de module manquant.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        try {
            if ($enabled) {
                $this->moduleManager->activate($moduleId, $userId);
            } else {
                $this->moduleManager->deactivate($moduleId, $userId);
            }
        } catch (ModuleException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }
}
