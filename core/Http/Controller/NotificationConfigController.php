<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\SecretManager;
use Minishlink\WebPush\VAPID;
use Twig\Environment;

/**
 * Configuration > Notifications (Core\Notification, Lot 2) —
 * `/config/notifications`, role_min superadmin: VAPID generation/
 * rotation, cron-detection warning, "envoyer une notification test".
 * Global defaults (quiet hours, retention) are plain SettingService rows
 * and already appear on the generic Configuration > Paramètres page — no
 * need to duplicate them here.
 */
class NotificationConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private NotificationService $notificationService,
        private SettingService $settingService,
        private JournalService $journalService,
        private SecretManager $secretManager,
        private \PDO $pdo
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions');
        $deviceCount = (int) $stmt->fetchColumn();

        $secrets = $this->secretManager->readSecrets();
        $cronLastRun = (int) ($this->settingService->get('cron_last_run') ?: 0);

        return $this->render('config/notifications.html.twig', [
            'device_count' => $deviceCount,
            'vapid_configured' => !empty($secrets['vapid_public_key']),
            // No real cron ever ran, or hasn't in the last 10 minutes — the
            // window is generous (a real crontab is typically every
            // minute) purely to avoid false alarms right after a fresh
            // install where cron may not be wired up yet at all.
            'cron_detected' => $cronLastRun > 0 && (time() - $cronLastRun) < 600,
            'declared_types_count' => count($this->notificationService->getAllDeclaredTypes()),
        ]);
    }

    /**
     * POST /config/notifications/rotate-vapid — the confirmation ("this
     * disconnects N devices") is shown client-side from the same
     * device_count the page already rendered server-side, per module
     * spec ("state the exact number of devices a rotation will
     * disconnect... first").
     *
     * @param array<string, string> $params
     */
    public function rotateVapid(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->redirect('/config/notifications');
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions');
        $deviceCount = (int) $stmt->fetchColumn();

        $vapidKeys = VAPID::createVapidKeys();
        $secrets = $this->secretManager->readSecrets();
        $secrets['vapid_public_key'] = $vapidKeys['publicKey'];
        $secrets['vapid_private_key'] = $vapidKeys['privateKey'];
        $this->secretManager->writeSecrets($secrets);

        // Every existing subscription was encrypted against the old
        // application server key pair — the browser itself will silently
        // stop accepting pushes for them (the public key it originally
        // subscribed with no longer matches), so they're inert rows from
        // this point on. Deleting them lets each device's own toggle
        // correctly show "off" again on next load and re-subscribe.
        $this->pdo->exec('DELETE FROM push_subscriptions');

        $this->journalService->log(
            'core',
            'vapid_keys_rotated',
            'security',
            'Clés VAPID régénérées',
            ['disconnected_devices' => $deviceCount],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', "Clés VAPID régénérées. {$deviceCount} appareil(s) déconnecté(s) — chacun devra réactiver les notifications push.");

        return $this->redirect('/config/notifications');
    }

    /**
     * POST /config/notifications/test — "Envoyer une notification test",
     * straight to the acting superadmin's own devices via the Iteration-1
     * notify() path (no type/role resolution needed — the recipient IS
     * the one testing it).
     *
     * @param array<string, string> $params
     */
    public function sendTest(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->redirect('/config/notifications');
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId !== null) {
            $this->notificationService->notify(
                $userId,
                'Notification test',
                'Si tu vois ceci, les notifications push fonctionnent.',
                '/config/notifications'
            );
        }

        FlashMessage::set('success', 'Notification test envoyée.');

        return $this->redirect('/config/notifications');
    }
}
