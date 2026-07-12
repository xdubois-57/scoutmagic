<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\ScheduledActionsController;
use Core\Http\Request;
use Core\Scheduler\SchedulerRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class ScheduledActionsControllerTest extends TestCase
{
    private ScheduledActionsController $controller;
    private SchedulerRepository $schedulerRepo;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->schedulerRepo = new SchedulerRepository($pdo);

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

        $this->controller = new ScheduledActionsController($twig, $this->schedulerRepo);
    }

    public function testIndexRendersEmptyState(): void
    {
        $request = new Request('GET', '/config/scheduled', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Actions planifiées', $response->getBody());
        $this->assertStringContainsString('Aucune action planifiée', $response->getBody());
    }

    public function testIndexRendersWithActions(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->schedulerRepo->create('core', 'send_email', $runAt, null, 'ref-1');
        $this->schedulerRepo->create('calendar', 'sync_events', $runAt, null, null);

        $request = new Request('GET', '/config/scheduled', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('2 actions', $response->getBody());
        $this->assertStringContainsString('send_email', $response->getBody());
        $this->assertStringContainsString('sync_events', $response->getBody());
    }
}
