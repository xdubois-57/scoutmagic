<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Photo\UnitLogoService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\UserAccountRepository;
use Twig\Environment;

class SettingsController extends AbstractController
{
    // Managed exclusively by their own dedicated UI (Core\Http\Controller\
    // MaintenanceController's "Mises à jour automatiques" section — semver
    // explainer, webhook status, danger-zone confirm-keyword flow, none of
    // which fit this page's plain editable-row rendering) — never shown
    // here, editable or not.
    // The statistics/support keys are excluded for the same reason: they are
    // managed from the dedicated Support page (Core\Http\Controller\
    // SupportController), which pairs the switch with the explanation of what
    // actually leaves the site — and several of them (installation id,
    // installation date, last-send bookkeeping) are read-only facts nobody
    // should be invited to hand-edit.
    /** @var string[] */
    private const EXCLUDED_FROM_GENERIC_PAGE = [
        'auto_update_enabled', 'auto_update_level', 'auto_update_day', 'auto_update_time',
        'dev_update_branch',
        'statistics_enabled', 'statistics_destination', 'statistics_installation_id',
        'support_email', 'installed_at',
        'statistics_last_success_at', 'statistics_last_failure_at', 'statistics_last_failure_reason',
        'support_package_file_id', 'support_package_generated_at',
    ];

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private JournalService $journal,
        private UnitLogoService $unitLogoService,
        private NotificationService $notificationService,
        private UserAccountRepository $userAccountRepository
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

        // The maskable icon and icon-180 are baked onto pwa_background_color
        // at derivation time (Core\Photo\UnitLogoProcessor::flattenOpaque())
        // — without this, changing the color here would update the theme
        // and the manifest's own background_color immediately but leave
        // those two icons showing whatever color was in effect at the last
        // upload. A no-op when no custom logo was ever uploaded (nothing
        // to re-derive from).
        if ($moduleId === null && $key === 'pwa_background_color') {
            $this->unitLogoService->rederiveFromSource();
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

    /**
     * POST /config/settings/logo-delete — remove an uploaded unit logo
     * override, reverting the favicon/PWA icons/footer logo to the
     * shipped defaults (AJAX, JSON). Not part of the generic key/value
     * update() above — a logo has no single settings-table row of its own
     * (see Core\Photo\UnitLogoService's own docblock, "is_file() is the
     * state").
     *
     * @param array<string, string> $params
     */
    public function deleteLogo(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrfToken = $data['_csrf_token'] ?? '';
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $this->unitLogoService->deleteUploadedLogo();

        $this->journal->log(
            'core',
            'unit_logo_deleted',
            'info',
            'Logo de l\'unité supprimé — retour aux icônes par défaut',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/settings/logo-notify-ios — sends the "core.
     * unit_logo_updated_ios" notification (Core\Notification\
     * NotificationRegistry) to every user account. Deliberately
     * superadmin-triggered and manual, never automatic on every upload:
     * a logo retouched three times in a row must not spam the whole unit
     * three times — the button only appears once a custom logo already
     * exists (setup/index.html.twig), and it's up to the admin to decide
     * when the change is "done" and worth notifying about. Uses
     * dispatch() (not the simpler notify()) so the send respects each
     * recipient's own channel preferences/quiet hours like any other
     * declared notification type, rather than bypassing them for a
     * one-off broadcast.
     *
     * @param array<string, string> $params
     */
    public function notifyIosLogoUpdate(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrfToken = $data['_csrf_token'] ?? '';
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        if (!$this->unitLogoService->hasCustomLogo()) {
            return $this->json(['success' => false, 'error' => 'Aucun logo personnalisé actif.'], 400);
        }

        $actorId = AuthSession::getUserAccountId();
        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            $this->userAccountRepository->findAllIds()
        );

        $this->notificationService->dispatch(
            'core.unit_logo_updated_ios',
            $recipients,
            [
                'title' => 'Nouveau logo disponible',
                'body' => 'Le logo de l\'unité a changé. Si tu as installé l\'application sur iPhone/iPad, '
                    . 'supprime-la puis réinstalle-la pour voir le nouveau logo — sur Android, la mise à jour '
                    . 'se fait automatiquement.',
            ],
            $actorId
        );

        $this->journal->log(
            'core',
            'unit_logo_ios_notify_sent',
            'info',
            'Notification de réinstallation iOS envoyée à tous les comptes',
            [],
            $actorId
        );

        return $this->json(['success' => true]);
    }
}
