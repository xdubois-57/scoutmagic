<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Http\Controller\TextPageController;
use Core\Http\Request;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The rendered page (issue #368) — against the real template.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageControllerTest extends TestCase
{
    private \PDO $pdo;
    private TextPageService $service;
    private TextPageController $controller;
    private EditableContentService $editable;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new TextPageService(new TextPageRepository($this->pdo));
        $this->editable = new EditableContentService(new EditableContentRepository($this->pdo));

        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates', true);
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@example.com');
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('current_path', '/pages/notre-asbl');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 1);
        $twig->addGlobal('_editable_content_service', $this->editable);

        $this->controller = new TextPageController($twig, $this->service);
    }

    private function show(string $path): \Core\Http\Response
    {
        return $this->controller->show(new Request('GET', $path, [], [], [], []), []);
    }

    public function testThePageRendersItsTitleAndItsText(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->editable->set($page->contentKey(), '<p>Nous sommes une ASBL.</p>', 'rich_text', 1);

        $response = $this->show($page->path());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Notre ASBL', $response->getBody());
        $this->assertStringContainsString('Nous sommes une ASBL.', $response->getBody());
    }

    /**
     * A page created a second ago has no text yet, and the chantier is
     * explicit that it must say so rather than look broken.
     */
    public function testAPageWithNoTextYetSaysSoRatherThanRenderingNothing(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertStringContainsString(
            "Cette page n'a pas encore de contenu.",
            $this->show($page->path())->getBody()
        );
    }

    /**
     * 404 and not 403 — a hidden page does not exist rather than being
     * forbidden, so nothing confirms there is something behind it.
     */
    public function testAHiddenPageAnswersNotFound(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->service->setActive($page->id, false);

        $response = $this->show($page->path());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('Notre ASBL', $response->getBody());
    }

    public function testAnUnknownSlugAnswersNotFound(): void
    {
        $this->assertSame(404, $this->show('/pages/rien-du-tout')->getStatusCode());
    }

    /**
     * The slug is read off the path the router matched, because the
     * routes these pages register are concrete — one per page, each with
     * its own `role_min` — and therefore carry no placeholder to fill
     * `$params` with.
     */
    public function testTheSlugIsReadFromThePathWhenTheRouteCarriesNoPlaceholder(): void
    {
        $page = $this->service->create('Bulle', 'La Bulle Safe', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame('/pages/la-bulle-safe', $page->path());
        $this->assertSame(200, $this->show($page->path())->getStatusCode());
    }

    public function testAPathOutsideThePrefixResolvesNothing(): void
    {
        $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame(404, $this->show('/notre-asbl')->getStatusCode());
    }

    /**
     * The controller contains no role check, and this test is what keeps
     * it that way.
     *
     * Its protection is the route's own `role_min`, enforced by the RBAC
     * guard before this class is instantiated (SECURITY.md §3,
     * ARCHITECTURE.md §2). A check added here would either restate what
     * the router proved or, worse, become the thing somebody trusts
     * instead of it — and a check that reads the session inside a
     * controller is exactly what ARCHITECTURE.md §2 forbids as a primary
     * protection.
     */
    public function testTheControllerAsksNothingAboutTheVisitor(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/core/Http/Controller/TextPageController.php');
        $this->assertIsString($source);

        // Only the executable half — the docblocks explain at length why
        // none of this is here.
        $code = implode("\n", array_filter(
            explode("\n", $source),
            static function (string $line): bool {
                $trimmed = ltrim($line);
                return $trimmed !== ''
                    && !str_starts_with($trimmed, '*')
                    && !str_starts_with($trimmed, '/*')
                    && !str_starts_with($trimmed, '//');
            }
        ));

        foreach (['AuthSession', 'RbacGuard', 'Role::', 'hasAccess', 'getRole', 'forbidden'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "TextPageController must not decide access: the route's role_min already did ({$forbidden})."
            );
        }
    }
}
