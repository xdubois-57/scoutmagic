<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Http\Controller\TextPageConfigController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Configuration › Site › Pages de texte — the screen of IT-02
 * (ARCHITECTURE.md §8.116).
 *
 * What is worth testing here is what the screen ADDS, not what
 * `TextPageService` already guarantees and already has its own tests for.
 * Three things: the section + column pair refused server-side whatever
 * the browser did, « Créer et ouvrir » really switching the session into
 * configuration mode, and deleting a page carrying its text away.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private TextPageService $pages;
    private EditableContentRepository $content;
    private TextPageConfigController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->content = new EditableContentRepository($this->pdo);
        $this->pages = new TextPageService(new TextPageRepository($this->pdo), $this->content);

        $this->controller = new TextPageConfigController(
            new Environment(new ArrayLoader(['config/text_pages/index.html.twig' => '', 'config/text_pages/form.html.twig' => ''])),
            $this->pages,
            new JournalService(new JournalRepository($this->pdo))
        );

        // The journal's rows point at the account that acted, and
        // `PRAGMA foreign_keys = ON` above makes that a real constraint —
        // so the account this session logs in as has to exist.
        $this->pdo->exec(
            "INSERT INTO user_accounts (id, email_encrypted, email_blind_index, is_super_admin) "
            . "VALUES (7, 'x', 'superadmin@test.be', 1)"
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        AuthSession::logout();
        AuthSession::login(7, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::logout();
    }

    private function token(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields, string $action, array $params = []): \Core\Http\Response
    {
        $body = $fields + ['_csrf_token' => $this->token()];
        $request = new Request('POST', $path, [], $body, [], []);

        return $this->controller->{$action}($request, $params);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $path, array $payload, string $action): \Core\Http\Response
    {
        $token = $this->token();
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($payload + ['_csrf_token' => $token]));

        return $this->controller->{$action}($request, []);
    }

    /**
     * **The trap the chantier names.** `MenuBuilder::addPage()` throws on
     * a column its menu does not declare, and that call happens while
     * building the navigation of EVERY page of the site. So a hand-made
     * request carrying a column that does not belong to its section must
     * be refused HERE — hiding the picker in the browser is only the
     * convenience.
     */
    public function testAColumnThatDoesNotBelongToItsSectionIsRefused(): void
    {
        $response = $this->post('/config/pages-de-texte', [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_CONFIGURATION,
            'menu_group' => 'pages',   // « Pages » belongs to Espace membres, not to Configuration
        ], 'create');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->pages->listAll(), 'nothing may have been written');
    }

    /** A section with columns cannot be left without one either. */
    public function testASectionWithColumnsRefusesAPageWithNoColumn(): void
    {
        $this->post('/config/pages-de-texte', [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_CONFIGURATION,
            'menu_group' => '',
        ], 'create');

        $this->assertSame([], $this->pages->listAll());
    }

    /**
     * « Notre unité » declares no columns at all, and an empty field must
     * reach the service as null rather than as an empty string — which it
     * would refuse for the wrong reason.
     */
    public function testASectionWithoutColumnsAcceptsAnEmptyColumnField(): void
    {
        $this->post('/config/pages-de-texte', [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'create');

        $pages = $this->pages->listAll();
        $this->assertCount(1, $pages);
        $this->assertNull($pages[0]->menuGroup);
    }

    /**
     * **« Créer et ouvrir » is one button because it is one intention.**
     * A page was just created empty; the only sensible next thing is to
     * go and write it, and that needs configuration mode on.
     */
    public function testCreatingAPageSwitchesTheSessionIntoConfigurationModeAndOpensIt(): void
    {
        $this->assertFalse(ConfigurationMode::isActive(), 'the premise of this test');

        $response = $this->post('/config/pages-de-texte', [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'create');

        $this->assertTrue(ConfigurationMode::isActive());
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/pages/notre-asbl', $response->getHeaders()['Location'] ?? null);
    }

    /**
     * Deleting a page takes its text with it — by `ON DELETE CASCADE`
     * rather than by a second statement the screen issues, so the
     * guarantee does not depend on this controller remembering.
     */
    public function testDeletingAPageTakesItsTextWithIt(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->assertNotNull($this->content->findByKey($page->contentKey()), 'the premise of this test');

        $this->postJson('/config/pages-de-texte/suppression', ['id' => $page->id], 'delete');

        $this->assertNull($this->pages->findById($page->id));
        $this->assertNull($this->content->findByKey($page->contentKey()));
    }

    /**
     * Activation lives on the list's row, and both directions are
     * journaled with the identifier and nothing else.
     */
    public function testHidingAndShowingAPageIsJournaled(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->postJson('/config/pages-de-texte/activation', ['id' => $page->id, 'active' => false], 'toggleActive');
        $this->assertFalse($this->pages->findById($page->id)?->isActive);

        $this->postJson('/config/pages-de-texte/activation', ['id' => $page->id, 'active' => true], 'toggleActive');
        $this->assertTrue($this->pages->findById($page->id)?->isActive);

        $types = $this->journalTypes();
        $this->assertContains('text_page_deactivated', $types);
        $this->assertContains('text_page_activated', $types);
    }

    public function testCreationAndDeletionAreJournaledWithTheIdentifierAndNothingElse(): void
    {
        $this->post('/config/pages-de-texte', [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'create');

        $page = $this->pages->listAll()[0];
        $this->postJson('/config/pages-de-texte/suppression', ['id' => $page->id], 'delete');

        $this->assertContains('text_page_created', $this->journalTypes());
        $this->assertContains('text_page_deleted', $this->journalTypes());

        foreach ($this->journalRows() as $row) {
            if (!str_starts_with((string) $row['event_type'], 'text_page_')) {
                continue;
            }
            // The title and the address are what a reader would recognise
            // a page by, and neither belongs in an entry that only has to
            // say which row changed.
            $this->assertStringNotContainsString('Notre ASBL', (string) $row['description']);
            $this->assertStringNotContainsString('notre-asbl', (string) ($row['context'] ?? ''));
        }
    }

    /** A request with no valid token writes nothing. */
    public function testACreationWithoutAValidTokenWritesNothing(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $request = new Request('POST', '/config/pages-de-texte', [], [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            '_csrf_token' => 'not the session token',
        ], [], []);

        $this->controller->create($request, []);

        $this->assertSame([], $this->pages->listAll());
    }

    /**
     * **The controller asks nothing about the visitor.** Every route is
     * declared `superadmin` and the RBAC guard runs before any of these
     * methods (SECURITY.md §3); a re-check here would be a second answer
     * to a question already answered, and the one that drifts.
     */
    public function testTheControllerAsksNothingAboutTheVisitorsRole(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/Http/Controller/TextPageConfigController.php'
        );
        $body = substr($source, (int) strpos($source, 'class TextPageConfigController'));

        foreach (['hasAccess', 'RbacGuard', 'Role::'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "TextPageConfigController must not decide access itself — the router already did ({$forbidden})."
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function journalRows(): array
    {
        $stmt = $this->pdo->query('SELECT event_type, description, context FROM event_log');

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    private function journalTypes(): array
    {
        return array_map(fn(array $row): string => (string) $row['event_type'], $this->journalRows());
    }
}
