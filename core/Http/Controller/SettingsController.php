<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

class SettingsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private JournalService $journal
    ) {
    }

    /**
     * GET /config/settings
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $groups = $this->settingService->getAllGrouped();

        $html = $this->twig->render('config/settings.html.twig', [
            'setting_groups' => $groups,
        ]);
        return new Response($html);
    }

    /**
     * POST /config/settings/update — update a single setting (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrfToken = $data['_csrf_token'] ?? '';
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $key = $data['key'] ?? '';
        $value = $data['value'] ?? '';
        $moduleId = $data['module_id'] ?? null;
        if ($moduleId === '') {
            $moduleId = null;
        }

        if ($key === '') {
            return $this->json(['success' => false, 'error' => 'Clé manquante.'], 400);
        }

        // Get old value for journal context
        $oldValue = $this->settingService->get($key, $moduleId);

        try {
            $this->settingService->set($key, $value, $moduleId);
        } catch (SettingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }

        $this->journal->log(
            'core',
            'setting_changed',
            'info',
            "Setting '{$key}' modified",
            ['key' => $key, 'module_id' => $moduleId, 'old_value' => $oldValue, 'new_value' => $value, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }
}
