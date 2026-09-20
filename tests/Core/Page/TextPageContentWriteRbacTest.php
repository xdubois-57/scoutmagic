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
 * floor (ARCHITECTURE.md §8.115). This asks the real controller.
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
        $this->pages = new TextPageService($repository);
        $this->editable = new EditableContentService(new EditableContentRepository($this->pdo));

        $this->controller = new EditableContentController(
            new Environment(new ArrayLoader([])),
            $this->editable,
            [new TextPageContentAuthorizer($repository)]
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
     * The refusal says the same thing whatever the reason and never
     * names what the key points at — an answer that distinguished « no
     * such page » from « not your page » would map out which ids exist.
     */
    public function testTheRefusalDoesNotSayWhetherThePageExists(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $existing = $this->writeAs('admin', $page->contentKey());
        $missing = $this->writeAs('admin', 'page_content_999999');

        $this->assertSame(403, $existing->getStatusCode());
        $this->assertSame(403, $missing->getStatusCode());
        $this->assertSame($existing->getBody(), $missing->getBody());
    }
}
