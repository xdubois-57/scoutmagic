<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\FunctionsController;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class FunctionsControllerTest extends TestCase
{
    private FunctionsController $controller;
    private FunctionRepository $functionRepo;
    private JournalRepository $journalRepo;
    private \PDO $pdo;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->functionRepo = new FunctionRepository($this->pdo);
        $this->journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($this->journalRepo);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'admin@test.com');
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $this->twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $this->controller = new FunctionsController($this->twig, $this->functionRepo, $journalService);
    }

    public function testIndexRendersEmptyState(): void
    {
        $request = new Request('GET', '/config/functions', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Fonctions', $body);
        $this->assertStringContainsString('Aucune fonction importée', $body);
    }

    public function testIndexRendersUnconfirmedFunctionsAtTop(): void
    {
        $this->functionRepo->create('Scout', 'Scout', 'identified', false);
        $this->functionRepo->create('Animé', 'Animé', 'identified', false);
        $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);

        $request = new Request('GET', '/config/functions', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('2 fonction(s) à confirmer', $body);
        $this->assertStringContainsString('Non confirmée', $body);
        $this->assertStringContainsString('Scout', $body);
        $this->assertStringContainsString('Animé', $body);
        $this->assertStringNotContainsString('Aucune fonction importée', $body);
    }

    public function testIndexRendersConfirmedFunctionsGroupedByRole(): void
    {
        $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);
        $this->functionRepo->create('Intendant', 'Intendant', 'intendant', true);
        $this->functionRepo->create('Animé', 'Animé', 'identified', true);

        $request = new Request('GET', '/config/functions', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Chef', $body);
        $this->assertStringContainsString('Intendant', $body);
        $this->assertStringContainsString('Animé', $body);
    }

    public function testUpdateChangesRoleAndSetsConfirmed(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $id = $this->functionRepo->create('Scout', 'Scout', 'identified', false);

        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'chief',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $updated = $this->functionRepo->findById($id);
        $this->assertSame('chief', $updated['role']);
        $this->assertTrue($updated['confirmed']);
    }

    public function testUpdateWithInvalidRoleReturnsError(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $id = $this->functionRepo->create('Scout', 'Scout', 'identified', false);

        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'superadmin',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('Rôle invalide.', $decoded['error']);
    }

    public function testUpdateWithNonExistentFunctionReturnsError(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'function_id' => 9999,
            'role' => 'chief',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('Fonction introuvable.', $decoded['error']);
    }

    public function testUpdateWithInvalidCsrfReturnsError(): void
    {
        $id = $this->functionRepo->create('Scout', 'Scout', 'identified', false);

        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'chief',
            '_csrf_token' => 'invalid-token',
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('Jeton CSRF invalide.', $decoded['error']);
    }

    public function testUpdateLogsJournalEntryOnRoleChange(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $id = $this->functionRepo->create('Scout', 'Scout', 'identified', false);

        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'chief',
            '_csrf_token' => $token,
        ]);
        $this->controller->update($request, []);

        // Check journal entry
        $stmt = $this->pdo->prepare("SELECT * FROM event_log WHERE event_type = 'function_role_changed'");
        $stmt->execute();
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries);
        $this->assertSame('core', $entries[0]['category']);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertStringContainsString('Scout', $entries[0]['description']);
    }

    public function testUpdateNoJournalEntryForNoOp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $id = $this->functionRepo->create('Scout', 'Scout', 'chief', true);

        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'chief',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        // No journal entry for no-op
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM event_log WHERE event_type = 'function_role_changed'");
        $stmt->execute();
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testUpdateConfirmingSameRoleLogsJournalEntry(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        // Unconfirmed function with identified role
        $id = $this->functionRepo->create('Scout', 'Scout', 'identified', false);

        // Confirm with same role
        $request = $this->createJsonRequest([
            'function_id' => $id,
            'role' => 'identified',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->update($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        // Should be confirmed now
        $updated = $this->functionRepo->findById($id);
        $this->assertTrue($updated['confirmed']);

        // Journal entry should exist (confirmation is logged)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM event_log WHERE event_type = 'function_role_changed'");
        $stmt->execute();
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/functions/update', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
