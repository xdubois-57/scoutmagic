<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\WebhookController;
use Core\Http\Request;
use Tests\RequestWithInput;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\GitHubWebhookService;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class WebhookControllerTest extends TestCase
{
    private \PDO $pdo;
    private WebhookController $controller;
    private GitHubWebhookService&\PHPUnit\Framework\MockObject\MockObject $webhookService;
    private SecretManager&\PHPUnit\Framework\MockObject\Stub $secretManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->webhookService = $this->createMock(GitHubWebhookService::class);
        $this->secretManager = $this->createStub(SecretManager::class);
        $this->secretManager->method('readSecrets')->willReturn(['github_webhook_secret' => 'test-secret']);

        $twig = TestTwig::create();

        $this->controller = new WebhookController($twig, $this->webhookService, $this->secretManager, $journalService);
    }

    /**
     * @param array<string, string> $server
     */
    private function requestWithBody(string $rawBody, array $server): Request
    {
        $request = new RequestWithInput('POST', '/api/webhook/github', [], [], [], $server, $rawBody);

        return $request;
    }

    public function testRejectsAMissingSignatureWith403(): void
    {
        $this->webhookService->expects($this->never())->method('handleReleaseEvent');

        $request = $this->requestWithBody('{"action":"published"}', ['HTTP_X_GITHUB_EVENT' => 'release']);
        $response = $this->controller->github($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('forbidden', $decoded['status']);
    }

    public function testRejectsAnInvalidSignatureWith403AndJournalsIt(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(false);
        $this->webhookService->expects($this->never())->method('handleReleaseEvent');

        $request = $this->requestWithBody('{"action":"published"}', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong',
            'HTTP_X_GITHUB_EVENT' => 'release',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(403, $response->getStatusCode());

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'github_webhook_signature_invalid'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    /**
     * A `secrets.enc` the master key no longer opens leaves the site with
     * no secret to check against: every delivery is refused and journaled
     * as an invalid signature, even one signed with the secret the site
     * used to hold, and no release is queued. The real service and the
     * real secrets file here: refusing is the subject.
     */
    public function testAnUnreadableSecretsFileRefusesEveryDelivery(): void
    {
        $base = sys_get_temp_dir() . '/webhook_ctrl_' . uniqid();
        mkdir($base, 0700, true);
        $secrets = new SecretManager($base . '/master.key', $base . '/secrets.enc');
        $secrets->generateMasterKey();
        $secrets->writeSecrets(['github_webhook_secret' => 'test-secret']);
        file_put_contents($base . '/secrets.enc', 'plus un fichier de secrets');
        $journal = new JournalService(new JournalRepository($this->pdo));
        $scheduler = new \Core\Scheduler\SchedulerRepository($this->pdo);
        $controller = new WebhookController(
            TestTwig::create(),
            new GitHubWebhookService(
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                new \Core\Scheduler\SchedulerService($scheduler),
                new \Core\Maintenance\UpdateHistoryRepository($this->pdo),
                $journal,
                $base
            ),
            $secrets,
            $journal
        );
        $body = '{"action":"published","release":{"tag_name":"v9.9.9"}}';

        try {
            $response = $controller->github($this->requestWithBody($body, [
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'test-secret'),
                'HTTP_X_GITHUB_EVENT' => 'release',
            ]), []);
        } finally {
            @unlink($base . '/master.key');
            @unlink($base . '/secrets.enc');
            @rmdir($base);
        }

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['status' => 'forbidden'], json_decode($response->getBody(), true));
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'github_webhook_signature_invalid'"
        )->fetchColumn();
        $this->assertSame(1, $count);
        $queued = (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn();
        $this->assertSame(0, $queued, 'a delivery nobody could verify queued an update');
    }

    public function testDispatchesAValidReleaseEventToTheService(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(true);
        $this->webhookService->expects($this->once())->method('handleReleaseEvent')
            ->with(['action' => 'published'])
            ->willReturn(['status' => 'ok']);

        $request = $this->requestWithBody('{"action":"published"}', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=valid',
            'HTTP_X_GITHUB_EVENT' => 'release',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok'], json_decode($response->getBody(), true));
    }

    public function testDispatchesAValidPushEventToTheService(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(true);
        $this->webhookService->expects($this->once())->method('handlePushEvent')
            ->willReturn(['status' => 'ok']);

        $request = $this->requestWithBody('{"ref":"refs/heads/main"}', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=valid',
            'HTTP_X_GITHUB_EVENT' => 'push',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRespondsOkToAPingEventWithoutCallingTheService(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(true);
        $this->webhookService->expects($this->never())->method('handleReleaseEvent');
        $this->webhookService->expects($this->never())->method('handlePushEvent');

        $request = $this->requestWithBody('{}', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=valid',
            'HTTP_X_GITHUB_EVENT' => 'ping',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok'], json_decode($response->getBody(), true));
    }

    public function testRespondsIgnoredForAnUnhandledEventType(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(true);

        $request = $this->requestWithBody('{}', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=valid',
            'HTTP_X_GITHUB_EVENT' => 'issues',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('ignored', $decoded['status']);
        $this->assertSame('unhandled_event', $decoded['reason']);
    }

    public function testRespondsIgnoredForAnInvalidJsonBody(): void
    {
        $this->webhookService->method('verifySignature')->willReturn(true);

        $request = $this->requestWithBody('not json', [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=valid',
            'HTTP_X_GITHUB_EVENT' => 'release',
        ]);
        $response = $this->controller->github($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('ignored', $decoded['status']);
        $this->assertSame('invalid_payload', $decoded['reason']);
    }
}
