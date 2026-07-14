<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\JournalController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class JournalControllerTest extends TestCase
{
    private JournalController $controller;
    private JournalService $journalService;
    private JournalRepository $journalRepo;
    private UserAccountRepository $userRepo;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->journalRepo = new JournalRepository($pdo);
        $this->journalService = new JournalService($this->journalRepo);
        $this->userRepo = new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $this->controller = new JournalController($twig, $this->journalRepo, $this->userRepo);
    }

    public function testIndexRendersEmptyJournal(): void
    {
        $request = new Request('GET', '/admin/journal', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("Journal d'événements", $response->getBody());
        $this->assertStringContainsString('0 entrée', $response->getBody());
    }

    public function testIndexRendersWithEntries(): void
    {
        $this->journalService->log('core', 'test_event', 'info', 'Test entry');
        $this->journalService->log('core', 'test_event2', 'security', 'Security test');

        $request = new Request('GET', '/admin/journal', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('2 entrées', $response->getBody());
        $this->assertStringContainsString('Test entry', $response->getBody());
        $this->assertStringContainsString('Security test', $response->getBody());
    }

    public function testIndexFiltersCategory(): void
    {
        $this->journalService->log('core', 'a', 'info', 'Core event');
        $this->journalService->log('import', 'b', 'info', 'Import event');

        $request = new Request('GET', '/admin/journal', ['category' => 'core'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('1 entrée', $response->getBody());
        $this->assertStringContainsString('Core event', $response->getBody());
    }

    public function testIndexFiltersLevel(): void
    {
        $this->journalService->log('core', 'a', 'info', 'Info event');
        $this->journalService->log('core', 'b', 'security', 'Security event');

        $request = new Request('GET', '/admin/journal', ['level' => 'security'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('1 entrée', $response->getBody());
        $this->assertStringContainsString('Security event', $response->getBody());
    }

    public function testIndexShowsResolvedEmailAndIp(): void
    {
        $actor = $this->userRepo->create('actor@test.be');
        $this->journalRepo->insert('core', 'login_success', 'security', 'Connexion', null, $actor->id, '203.0.113.9');

        $request = new Request('GET', '/admin/journal', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('actor@test.be', $body);
        $this->assertStringContainsString('203.0.113.9', $body);
    }

    public function testIndexFiltersByEmail(): void
    {
        $actor = $this->userRepo->create('actor@test.be');
        $other = $this->userRepo->create('other@test.be');
        $this->journalRepo->insert('core', 'a', 'info', 'Action actor', null, $actor->id, '10.0.0.1');
        $this->journalRepo->insert('core', 'b', 'info', 'Action other', null, $other->id, '10.0.0.2');

        $request = new Request('GET', '/admin/journal', ['email' => 'actor@test.be'], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('Action actor', $body);
        $this->assertStringNotContainsString('Action other', $body);
    }

    public function testIndexFiltersByIp(): void
    {
        $this->journalRepo->insert('core', 'a', 'info', 'From ten', null, null, '10.0.0.1');
        $this->journalRepo->insert('core', 'b', 'info', 'From other', null, null, '198.51.100.4');

        $request = new Request('GET', '/admin/journal', ['ip' => '198.51.100.4'], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('From other', $body);
        $this->assertStringNotContainsString('From ten', $body);
    }
}
