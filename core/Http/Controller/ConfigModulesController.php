<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Module\ModuleException;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Configuration > Modules — module registry (activation/deactivation,
 * drag-and-drop reordering). Split out of the former ConfigGeneralController
 * (which also carried badges and the configuration-mode toggle) so each
 * page has its own single-concern controller (AGENTS.md).
 */
class ConfigModulesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ModuleManager $moduleManager,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/modules — render the module registry page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $modules = $this->moduleManager->discoverModules();

        // partials/list_editor.html.twig's chrome (drag handle, data-id
        // attribute) addresses each item as item.id — module_id is a
        // string on ModuleInfo->manifest->id, one level deeper than that
        // partial looks, so each entry is wrapped here rather than
        // reworking the shared partial around a configurable id path.
        $moduleItems = array_map(fn($mod) => [
            'id' => $mod->manifest->id,
            'info' => $mod,
            // Whether this module's hard dependencies (manifest "requires")
            // are all present, valid and enabled — resolved here rather
            // than in Twig, which has no access to the discovered set.
            'requirements_met' => $this->moduleManager->areRequirementsSatisfied($mod->manifest->id, $modules),
        ], $modules);

        // Hard dependencies are declared as ids; the page shows names. Core
        // never knows any module's name, so the map is built from the
        // manifests themselves — an id with no module on disk keeps the id
        // as its own label (the template's |default).
        $moduleNames = [];
        foreach ($modules as $mod) {
            $moduleNames[$mod->manifest->id] = $mod->manifest->name;
        }

        return $this->render('config/modules.html.twig', [
            'modules' => $moduleItems,
            'module_names' => $moduleNames,
        ]);
    }

    /**
     * POST /config/modules/toggle — activate/deactivate a module (AJAX).
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

    /**
     * POST /config/modules/reorder — persist a new module display/menu
     * order from the drag-and-drop (or mobile arrow) list (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function reorderModules(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $ids = is_array($data['ids'] ?? null) ? array_map('strval', $data['ids']) : [];
        $this->moduleManager->reorder($ids);

        $this->journalService->log(
            'core',
            'modules_reordered',
            'info',
            'Ordre des modules modifié',
            ['module_ids' => $ids],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);
        return is_array($data) ? $data : null;
    }
}
