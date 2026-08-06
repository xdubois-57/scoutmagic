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
    // Managed exclusively by their own dedicated UI (Core\Http\Controller\
    // MaintenanceController's "Mises à jour automatiques" section — semver
    // explainer, webhook status, danger-zone confirm-keyword flow, none of
    // which fit this page's plain editable-row rendering) — never shown
    // here, editable or not.
    /** @var string[] */
    private const EXCLUDED_FROM_GENERIC_PAGE = [
        'auto_update_enabled', 'auto_update_level', 'auto_update_day', 'auto_update_time',
        'dev_update_branch',
    ];

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
        if (isset($groups['core'])) {
            $groups['core']['settings'] = array_values(array_filter(
                $groups['core']['settings'],
                fn(array $setting) => !in_array($setting['setting_key'], self::EXCLUDED_FROM_GENERIC_PAGE, true)
            ));
        }

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
            "Paramètre « {$key} » modifié",
            ['key' => $key, 'module_id' => $moduleId, 'old_value' => $oldValue, 'new_value' => $value, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }
}
