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
use Core\Notification\NotificationService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Core routes (not module-specific) for a device's Web Push subscription —
 * see Core\Notification\NotificationService and public/assets/js/
 * push-notifications.js (the "Mon compte" notification toggle).
 */
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private NotificationService $notificationService,
        private JournalService $journalService
    ) {
    }

    /**
     * POST /api/push-subscription — registers this device.
     *
     * @param array<string, string> $params
     */
    public function subscribe(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $authKey = (string) ($data['auth_key'] ?? '');
        $p256dhKey = (string) ($data['p256dh_key'] ?? '');

        if ($endpoint === '' || $authKey === '' || $p256dhKey === '') {
            return $this->json(['success' => false, 'error' => 'Données de souscription incomplètes.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 401);
        }

        $this->notificationService->subscribe($userId, $endpoint, $authKey, $p256dhKey, $this->deviceLabelFromUserAgent($request));

        $this->journalService->log(
            'core',
            'push_subscription_created',
            'info',
            'Souscription aux notifications push activée',
            [],
            $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * DELETE /api/push-subscription — removes this device.
     *
     * @param array<string, string> $params
     */
    public function unsubscribe(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        if ($endpoint === '') {
            return $this->json(['success' => false, 'error' => 'Endpoint manquant.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 401);
        }

        $this->notificationService->unsubscribe($userId, $endpoint);

        $this->journalService->log(
            'core',
            'push_subscription_deleted',
            'info',
            'Souscription aux notifications push désactivée',
            [],
            $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * Best-effort "browser sur OS" label from User-Agent (e.g. "Chrome sur
     * Android") — display-only, so a member can tell their devices apart
     * in a future "Mes appareils" list (push_subscriptions.device_label).
     * Never parsed for anything security- or logic-relevant.
     */
    private function deviceLabelFromUserAgent(Request $request): ?string
    {
        $userAgent = (string) $request->getServer('HTTP_USER_AGENT', '');
        if ($userAgent === '') {
            return null;
        }

        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'CriOS/'), str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        if ($browser === null && $os === null) {
            return null;
        }

        return trim(($browser ?? 'Navigateur') . ($os !== null ? " sur {$os}" : ''));
    }
}
