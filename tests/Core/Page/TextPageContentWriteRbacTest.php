<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Http\Controller\EditableContentController;
use Core\Http\Request;
use Core\Page\TextPageContentAuthorizer;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The escalation closed at the endpoint, not only in the authorizer.
 *
 * `POST /api/editable-content` is `role_min: admin`. A free-text page
 * filed in the Configuration menu is read at `superadmin`, so its body
 * is the first content on this site whose read floor exceeds its write
 * floor (ARCHITECTURE.md §8.116). This asks the real controller.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageContentWriteRbacTest extends TestCase
{
    private \PDO $pdo;
    private TextPageService $pages;
    private EditableContentService $editable;
    private EditableContentController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $repository = new TextPageRepository($this->pdo);
        $this->pages = new TextPageService($repository, new EditableContentRepository($this->pdo));
        $this->editable = new EditableContentService(
            new EditableContentRepository($this->pdo),
            [new TextPageContentAuthorizer($repository)]
        );

        $this->controller = new EditableContentController(
            new Environment(new ArrayLoader([])),
            $this->editable
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::logout();
    }

    private function writeAs(string $role, string $key): \Core\Http\Response
    {
        AuthSession::logout();
        AuthSession::login(7, $role . '@test.be', $role);
        ConfigurationMode::activate($role);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/api/editable-content', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode([
            'key' => $key,
            'value' => '<p>Texte injecté.</p>',
            'type' => 'rich_text',
            '_csrf_token' => $token,
        ]));

        return $this->controller->update($request, []);
    }

    /**
     * The defect, asked of the endpoint an attacker would actually call.
     */
    public function testAnAdminIsRefusedTheBodyOfASuperadminOnlyPage(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $response = $this->writeAs('admin', $page->contentKey());

        $this->assertSame(403, $response->getStatusCode());
        // The row exists from the moment the page does, owned and holding
        // NULL; what matters is that the refused write left it that way.
        $this->assertNull(
            $this->editable->get($page->contentKey()),
            'nothing may have been written'
        );
    }

    public function testASuperadminWritesTheSamePage(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $response = $this->writeAs('superadmin', $page->contentKey());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Texte injecté.', (string) $this->editable->get($page->contentKey()));
    }

    /**
     * A page whose section an admin may read stays writable by an admin —
     * the guard narrows where it must and nowhere else.
     */
    public function testAnAdminStillWritesAPageTheirRoleMayRead(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame(200, $this->writeAs('admin', $page->contentKey())->getStatusCode());
    }

    /**
     * The keys that existed before free-text pages are untouched: this
     * endpoint's own floor is still the whole answer for them.
     */
    public function testAnOrdinaryEditableKeyIsUnaffected(): void
    {
        $this->assertSame(200, $this->writeAs('admin', 'home.intro')->getStatusCode());
    }

    /**
     * **The backstop, asked of the chokepoint every door funnels
     * through.**
     *
     * The per-door checks are the good error messages; this is the
     * guarantee. `POST /upload` with `context=editable_image` writes
     * `editable_contents` under a client-chosen key through
     * `PhotoIngestionService`, never touching the editable-content
     * controller — a second door that had never needed guarding until
     * this iteration created the first key whose read floor exceeds
     * `admin`. A third one added later must fail closed rather than
     * quietly reopening the gap.
     */
    public function testTheServiceItselfRefusesWhateverDoorTheWriteCameThrough(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        AuthSession::logout();
        AuthSession::login(7, 'admin@test.be', 'admin');

        $this->expectException(\Core\View\EditableContentForbiddenException::class);
        $this->editable->set($page->contentKey(), '5', 'image', 7);
    }

    /**
     * And the same call from a superadmin goes through — the backstop
     * narrows where it must and nowhere else.
     */
    public function testTheServiceLetsTheRightRoleThrough(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        AuthSession::logout();
        AuthSession::login(7, 'superadmin@test.be', 'superadmin');

        $this->assertSame('5', $this->editable->set($page->contentKey(), '5', 'image', 7));
    }

    /**
     * The refusal names nothing about the page.
     *
     * Not its title, not its address, not whether it is active — only
     * the site's standard forbidden sentence. An error that described
     * what it was protecting would hand an admin the inventory of the
     * pages they may not read.
     *
     * What the status code does distinguish is narrower than it looks:
     * a key owned by a page whose role the caller lacks answers 403,
     * and a key owned by nothing answers 200 — the caller learns that
     * SOME page owns that row, which they could infer from the feature
     * existing at all, and nothing about which.
     */
    public function testTheRefusalNamesNothingAboutThePage(): void
    {
        $page = $this->pages->create('Bulle Safe', 'La Bulle Safe', MenuBuilder::MENU_CONFIGURATION, 'site');

        $body = $this->writeAs('admin', $page->contentKey())->getBody();

        foreach (['Bulle Safe', 'La Bulle Safe', 'bulle-safe', 'configuration', 'site'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }
}
