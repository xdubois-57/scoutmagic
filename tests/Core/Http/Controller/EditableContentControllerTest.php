<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\EditableContentController;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class EditableContentControllerTest extends TestCase
{
    private \PDO $pdo;
    private EditableContentController $controller;
    private EditableContentService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )");

        $this->service = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->controller = new EditableContentController(new Environment(new ArrayLoader([])), $this->service);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
        ConfigurationMode::deactivate();
    }

    protected function tearDown(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/x', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testUpdateFieldSucceedsWithoutConfigurationModeActive(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateField(
            $this->jsonRequest(['key' => 'banner_content_1', 'value' => '<p>Hi</p>', 'type' => 'rich_text', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertStringContainsString('Hi', $this->service->get('banner_content_1'));
    }

    public function testUpdateFieldValidatesCsrf(): void
    {
        $response = $this->controller->updateField(
            $this->jsonRequest(['key' => 'banner_content_1', 'value' => '<p>Hi</p>', '_csrf_token' => 'bad']),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateFieldRejectsMissingKey(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateField(
            $this->jsonRequest(['value' => '<p>Hi</p>', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateFieldRejectsInvalidType(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateField(
            $this->jsonRequest(['key' => 'x', 'value' => 'y', 'type' => 'bogus', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateRejectsWhenConfigurationModeInactive(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->update(
            $this->jsonRequest(['key' => 'home.intro', 'value' => '<p>Hi</p>', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateSucceedsWhenConfigurationModeActive(): void
    {
        ConfigurationMode::activate('superadmin');
        $token = $this->csrfToken();

        $response = $this->controller->update(
            $this->jsonRequest(['key' => 'home.intro', 'value' => '<p>Hi</p>', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
    }
}
