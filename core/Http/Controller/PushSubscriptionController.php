<?php

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

        $this->notificationService->subscribe($userId, $endpoint, $authKey, $p256dhKey);

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
}
