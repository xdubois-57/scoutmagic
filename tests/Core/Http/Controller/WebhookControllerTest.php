<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\WebhookController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\GitHubWebhookService;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class WebhookControllerTest extends TestCase
{
    private \PDO $pdo;
    private WebhookController $controller;
    private GitHubWebhookService&\PHPUnit\Framework\MockObject\MockObject $webhookService;
    private SecretManager&\PHPUnit\Framework\MockObject\MockObject $secretManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->webhookService = $this->createMock(GitHubWebhookService::class);
        $this->secretManager = $this->createMock(SecretManager::class);
        $this->secretManager->method('readSecrets')->willReturn(['github_webhook_secret' => 'test-secret']);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);

        $this->controller = new WebhookController($twig, $this->webhookService, $this->secretManager, $journalService);
    }

    /**
     * @param array<string, string> $server
     */
    private function requestWithBody(string $rawBody, array $server): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/api/webhook/github', [], [], [], $server])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($rawBody);

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
