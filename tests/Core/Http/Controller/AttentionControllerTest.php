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
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'a@test.com');
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        foreach (['csrf_field', 'editable', 'editable_image'] as $name) {
            $this->twig->addFunction(new \Twig\TwigFunction($name, fn() => '', ['is_safe' => ['html']]));
        }
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));

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
