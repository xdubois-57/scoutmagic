<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Attention\AttentionService;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AttentionController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The attention-points page, rendered.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AttentionControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private ScoutYearResolver $scoutYearResolver;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $this->twig = TestTwig::create();
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'a@test.com');
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearResolver = new ScoutYearResolver(
            $scoutYearService,
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
    }

    private function controller(AttentionService $service): AttentionController
    {
        return new AttentionController($this->twig, $service, $this->scoutYearResolver);
    }

    public function testItSaysUpFrontThatNothingIsAcknowledgedHere(): void
    {
        $response = $this->controller(new AttentionService())->index(new Request('GET', '/admin/points-attention', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("Rien ne s'acquitte ici", $response->getBody());
    }

    public function testItRendersEachPointWithItsSourceAndItsAction(): void
    {
        $service = new AttentionService([
            new StubAttentionProvider('Cotisations', [
                new AttentionPoint(
                    'Un foyer porte une catégorie tarifaire devenue fausse',
                    'Écart estimé de 87,75 €.',
                    'Ouvrir la justesse des tarifs',
                    '/admin/fees/tarifs'
                ),
            ]),
        ]);

        $body = $this->controller($service)->index(new Request('GET', '/admin/points-attention', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Cotisations', $body);
        $this->assertStringContainsString('catégorie tarifaire devenue fausse', $body);
        $this->assertStringContainsString('Ouvrir la justesse des tarifs', $body);
        $this->assertStringContainsString('/admin/fees/tarifs', $body);
    }

    /**
     * This page is `admin`; a Maintenance sub-page is `superadmin` since
     * issue #619. A chef d'unité keeps the fact and loses only the button,
     * which would have answered « accès refusé »; a super-administrator
     * keeps both. The floor is the target route's, read from the router —
     * fragment and query set aside.
     */
    public function testAnActionLeadingAboveTheReadersRoleIsDroppedButTheFactStays(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/config/maintenance/sauvegarde-automatique', AttentionController::class, 'index', 'superadmin');
        $router->addRoute('GET', '/admin/fees/tarifs', AttentionController::class, 'index', 'admin');
        $service = new AttentionService([
            new StubAttentionProvider('Cœur', [
                new AttentionPoint(
                    'La sauvegarde hors site a plus de trois jours',
                    'Parce que',
                    'Ouvrir la maintenance',
                    '/config/maintenance/sauvegarde-automatique#remote-backup'
                ),
                new AttentionPoint('Un foyer mal classé', 'Parce que', 'Ouvrir la justesse des tarifs', '/admin/fees/tarifs'),
            ]),
        ]);
        $controller = new AttentionController($this->twig, $service, $this->scoutYearResolver, $router);
        $request = new Request('GET', '/admin/points-attention', [], [], [], []);

        try {
            AuthSession::login(1, 'cu@test.com', 'admin');
            $body = $controller->index($request, [])->getBody();
            $this->assertStringContainsString('La sauvegarde hors site a plus de trois jours', $body);
            $this->assertStringNotContainsString('Ouvrir la maintenance', $body);
            $this->assertStringNotContainsString('/config/maintenance/sauvegarde-automatique', $body);
            $this->assertStringContainsString('/admin/fees/tarifs', $body, 'A link the reader can open stays.');

            AuthSession::login(1, 'root@test.com', 'superadmin');
            $body = $controller->index($request, [])->getBody();
            $this->assertStringContainsString('Ouvrir la maintenance', $body);
            $this->assertStringContainsString('/config/maintenance/sauvegarde-automatique#remote-backup', $body);
        } finally {
            AuthSession::logout();
        }
    }

    public function testADeadlineIsRenderedAsADelay(): void
    {
        $service = new AttentionService([
            new StubAttentionProvider('Encadrement', [
                new AttentionPoint('Pierre est intendant', 'Parce que', dueDate: (new \DateTimeImmutable())->modify('+5 days')),
            ]),
        ]);

        $body = $this->controller($service)->index(new Request('GET', '/admin/points-attention', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('dans 5 jours', $body);
    }

    public function testAModuleThatCouldNotContributeIsNamedOnThePage(): void
    {
        $service = new AttentionService([
            new StubAttentionProvider('Cœur', [new AttentionPoint('Un badge', 'Parce que')]),
            new BrokenAttentionProvider('Camps'),
        ]);

        $body = $this->controller($service)->index(new Request('GET', '/admin/points-attention', [], [], [], []), [])->getBody();

        $this->assertStringContainsString("n'a pas pu contribuer", $body);
        $this->assertStringContainsString('Camps', $body);
        // And the working half is still there.
        $this->assertStringContainsString('Un badge', $body);
    }

    public function testAnEmptyPageSaysSoRatherThanRenderingNothing(): void
    {
        $body = $this->controller(new AttentionService())->index(new Request('GET', '/admin/points-attention', [], [], [], []), [])->getBody();

        // Escaped on the way out, so the assertion picks the half of the
        // sentence that carries no apostrophe.
        $this->assertStringContainsString('Rien ne demande votre intervention', $body);
    }
}

final class StubAttentionProvider implements AttentionPointProvider
{
    /** @param AttentionPoint[] $points */
    public function __construct(private string $label, private array $points)
    {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function collect(int $scoutYearId): array
    {
        return $this->points;
    }
}

final class BrokenAttentionProvider implements AttentionPointProvider
{
    public function __construct(private string $label)
    {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function collect(int $scoutYearId): array
    {
        throw new \RuntimeException('boom');
    }
}
