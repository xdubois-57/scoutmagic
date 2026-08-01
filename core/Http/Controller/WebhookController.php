<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Journal\JournalService;
use Core\Maintenance\GitHubWebhookService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\SecretManager;
use Twig\Environment;

/**
 * POST /api/webhook/github — the only public (role_min: public), CSRF-free
 * route in the whole codebase: GitHub is a machine caller with no session,
 * so it can't carry a CSRF token, and CSRF protection isn't the right tool
 * here anyway — the HMAC-SHA256 signature (verified against the secret
 * generated on the Maintenance page, Http\Controller\MaintenanceController::
 * generateWebhookSecret()) is what actually authenticates the request.
 *
 * Always responds 200 (even for an ignored/unhandled event) except for an
 * invalid/missing signature (403) — GitHub retries on any non-2xx status,
 * and there is nothing to retry for an event this site simply doesn't act
 * on.
 */
class WebhookController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GitHubWebhookService $webhookService,
        private SecretManager $secretManager,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function github(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $signature = (string) $request->getServer('HTTP_X_HUB_SIGNATURE_256', '');
        $event = (string) $request->getServer('HTTP_X_GITHUB_EVENT', '');

        $secret = $this->webhookSecret();

        if (!$this->webhookService->verifySignature($rawBody, $signature, $secret)) {
            $this->journalService->log(
                'core',
                'github_webhook_signature_invalid',
                'security',
                'Signature de webhook GitHub invalide',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
                null
            );

            return $this->json(['status' => 'forbidden'], 403);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->json(['status' => 'ignored', 'reason' => 'invalid_payload']);
        }

        $result = match ($event) {
            'release' => $this->webhookService->handleReleaseEvent($payload),
            'push' => $this->webhookService->handlePushEvent($payload),
            'ping' => ['status' => 'ok'],
            default => ['status' => 'ignored', 'reason' => 'unhandled_event'],
        };

        return $this->json($result);
    }

    private function webhookSecret(): string
    {
        try {
            $secrets = $this->secretManager->readSecrets();
        } catch (\Throwable) {
            return '';
        }

        return (string) ($secrets['github_webhook_secret'] ?? '');
    }
}
