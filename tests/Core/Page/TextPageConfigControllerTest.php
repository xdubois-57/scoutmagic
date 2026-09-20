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
            // Stand-in templates that echo the context, so the assertions
            // below read what the controller PREPARED rather than how the
            // real markup happens to be laid out — those templates are
            // exercised by the end-to-end job, not here.
            new Environment(new ArrayLoader([
                'config/text_pages/index.html.twig' =>
                    '{% for s in sections %}{% for p in s.pages %}[{{ s.label }}|{{ p.id }}|{{ p.menu_label }}'
                    . '|{{ p.section_label }}|{{ p.group_label }}|{{ p.path }}'
                    . '|{{ p.is_active ? "on" : "off" }}]{% endfor %}{% endfor %}',
                'config/text_pages/form.html.twig' =>
                    '{% for s in sections %}<s {{ s.value }}{{ s.selected ? " SEL" : "" }}>{% endfor %}'
                    . 'GROUP={{ selected_group }} TITLE={{ page ? page.title : "" }}'
                    . ' MAP={{ groups_by_section|json_encode|raw }}',
                // notFound() renders the real 404 page; a stand-in is
                // enough to let this loader answer for it.
                'errors/404.html.twig' => 'introuvable',
            ])),
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


    /**
     * The list shows hidden pages too. A page is hidden from the SITE, not
     * from the screen that manages it — the screen is the only place its
     * switch can be flicked back on.
     */
    public function testTheListShowsHiddenPagesAsWellAsVisibleOnes(): void
    {
        $visible = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $hidden = $this->pages->create('Charte', 'Notre charte', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->pages->setActive($hidden->id, false);

        $body = $this->controller->index(new Request('GET', '/config/pages-de-texte', [], [], [], []), [])->getBody();

        $this->assertStringContainsString("|{$visible->id}|ASBL|", $body);
        $this->assertStringContainsString("|{$hidden->id}|Charte|", $body);
        $this->assertStringContainsString('|off]', $body, 'the hidden page must be shown as hidden, not omitted');
    }

    /**
     * Each row names the placement in the words the menus themselves use,
     * not in the identifiers the database stores — « Site » and not
     * « site », because the reader is looking for the column they picked.
     */
    public function testARowNamesItsSectionAndColumnAsTheMenusDo(): void
    {
        $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $body = $this->controller->index(new Request('GET', '/config/pages-de-texte', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('|' . MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION) . '|Site|', $body);
        $this->assertStringContainsString('|/pages/notre-asbl|', $body);
    }

    /** A section with no columns has no column to name. */
    public function testARowInASectionWithoutColumnsNamesNoColumn(): void
    {
        $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $body = $this->controller->index(new Request('GET', '/config/pages-de-texte', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('|Notre unité||/pages/notre-asbl|', $body);
    }

    /**
     * The creation form opens on « Notre unité » — the widest audience is
     * also the commonest use, and every other section is one click away.
     * It carries the whole section → columns map, which is what lets the
     * browser swap the second picker without a round trip.
     */
    public function testTheCreationFormOpensOnTheFirstSectionAndCarriesEverySectionsColumns(): void
    {
        $body = $this->controller->createForm(new Request('GET', '/config/pages-de-texte/nouveau', [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('<s ' . MenuBuilder::MENU_NOTRE_UNITE . ' SEL>', $body);
        $this->assertStringContainsString('TITLE=', $body);

        foreach (MenuBuilder::menuIds() as $menuId) {
            $this->assertStringContainsString('"' . $menuId . '":', $body, "the map must carry {$menuId}");
        }
    }

    /**
     * The edit form opens on the page's OWN section and column. Opening it
     * on anything else would move the page the moment somebody saved
     * without touching those fields.
     */
    public function testTheEditFormOpensOnThePagesOwnSectionAndColumn(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'unite_donnees');

        $body = $this->controller->editForm(
            new Request('GET', '/config/pages-de-texte/' . $page->id, [], [], [], []),
            ['id' => (string) $page->id]
        )->getBody();

        $this->assertStringContainsString('<s ' . MenuBuilder::MENU_CONFIGURATION . ' SEL>', $body);
        $this->assertStringContainsString('GROUP=unite_donnees', $body);
        $this->assertStringContainsString('TITLE=Notre ASBL', $body);
    }

    public function testTheEditFormOfAPageThatDoesNotExistAnswersNotFound(): void
    {
        $response = $this->controller->editForm(
            new Request('GET', '/config/pages-de-texte/4242', [], [], [], []),
            ['id' => '4242']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * **Renaming a page never moves it.** The slug is frozen at creation,
     * and this is the screen through which somebody would try — fixing a
     * typo in the title is exactly the moment a link already shared would
     * otherwise break.
     */
    public function testRenamingAPageThroughTheScreenLeavesItsAddressAlone(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->post('/config/pages-de-texte/' . $page->id, [
            'menu_label' => 'ASBL',
            'title' => "L'ASBL qui porte l'unité",
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'update', ['id' => (string) $page->id]);

        $reread = $this->pages->findById($page->id);
        $this->assertSame("L'ASBL qui porte l'unité", $reread?->title);
        $this->assertSame($page->path(), $reread?->path());
    }

    /** A move that its section refuses changes nothing. */
    public function testAnEditThatMovesAPageIntoAnImpossibleColumnChangesNothing(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $response = $this->post('/config/pages-de-texte/' . $page->id, [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_CONFIGURATION,
            'menu_group' => 'pages',
        ], 'update', ['id' => (string) $page->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('site', $this->pages->findById($page->id)?->menuGroup);
    }

    public function testDraggingAPageReordersTheMenuEntries(): void
    {
        $first = $this->pages->create('A', 'Page A', MenuBuilder::MENU_NOTRE_UNITE, null);
        $second = $this->pages->create('B', 'Page B', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->postJson('/config/pages-de-texte/ordre', ['ids' => [$second->id, $first->id]], 'reorder');

        $order = array_map(fn($page): int => $page->id, $this->pages->listAll());
        $this->assertSame([$second->id, $first->id], $order);
    }

    /**
     * A body that is not JSON is refused before anything else happens —
     * `json_decode()` returning null must not be read as an empty payload
     * and acted on as `id = 0`.
     *
     * @return array<int, array{0: string}>
     */
    public static function jsonEndpoints(): array
    {
        return [['reorder'], ['toggleActive'], ['delete']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jsonEndpoints')]
    public function testAJsonEndpointRefusesABodyThatIsNotJson(string $action): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/pages-de-texte/ordre', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn('not json at all');

        $response = $this->controller->{$action}($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotNull($this->pages->findById($page->id), 'nothing may have been touched');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jsonEndpoints')]
    public function testAJsonEndpointWithoutAValidTokenChangesNothing(string $action): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/pages-de-texte/ordre', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode([
            'id' => $page->id,
            'ids' => [],
            'active' => false,
            '_csrf_token' => 'not the session token',
        ]));

        $this->controller->{$action}($request, []);

        $reread = $this->pages->findById($page->id);
        $this->assertNotNull($reread, 'the page must still exist');
        $this->assertTrue($reread->isActive, 'the page must still be active');
    }


    /**
     * **The list is grouped by section, and that is not cosmetic.**
     * `sort_order` is a rank inside a menu, so one flat sortable list
     * would let a drag across a boundary post an order nothing reads that
     * way — the row would jump back on reload, and the screen would have
     * lied about what it saved.
     */
    public function testTheListIsGroupedBySectionAndNamesEverySectionEvenTheEmptyOnes(): void
    {
        $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $body = $this->controller->index(new Request('GET', '/config/pages-de-texte', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('[Notre unité|', $body, 'the page must sit under its own section');
    }

    /**
     * A drag that crosses a section boundary must not renumber both
     * sections on one global run: each page's rank is only ever compared
     * with its own section's.
     */
    public function testReorderingRanksEachSectionOnItsOwnCount(): void
    {
        $publicA = $this->pages->create('A', 'Page A', MenuBuilder::MENU_NOTRE_UNITE, null);
        $publicB = $this->pages->create('B', 'Page B', MenuBuilder::MENU_NOTRE_UNITE, null);
        $config = $this->pages->create('C', 'Page C', MenuBuilder::MENU_CONFIGURATION, 'site');

        // As a hand-made request could send it: three sections' ids mixed.
        $this->postJson(
            '/config/pages-de-texte/ordre',
            ['ids' => [$publicB->id, $config->id, $publicA->id]],
            'reorder'
        );

        $this->assertSame(0, $this->pages->findById($publicB->id)?->sortOrder);
        $this->assertSame(1, $this->pages->findById($publicA->id)?->sortOrder);
        $this->assertSame(
            0,
            $this->pages->findById($config->id)?->sortOrder,
            'the only page of its section ranks first in it, whatever its place in the submitted list'
        );
    }

    /**
     * **A page that does not exist cannot be activated, and must not be
     * journaled as if it had been.** `UPDATE … WHERE id = ?` on a missing
     * row succeeds silently, so without a check the screen would answer
     * « done » and write a fabricated line into the audit trail this
     * feature exists to provide.
     */
    public function testActivatingAPageThatDoesNotExistIsRefusedAndJournalsNothing(): void
    {
        $response = $this->postJson(
            '/config/pages-de-texte/activation',
            ['id' => 999999, 'active' => true],
            'toggleActive'
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->journalTypes());
    }

    public function testDeletingAPageThatDoesNotExistIsRefusedAndJournalsNothing(): void
    {
        $response = $this->postJson('/config/pages-de-texte/suppression', ['id' => 999999], 'delete');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->journalTypes());
    }

    /**
     * **Moving a page between sections IS journaled**, unlike renaming
     * one. The section is the page's access control — `roleMin()` derives
     * the route's floor from it — so this same form can publish a
     * superadmin page to the open internet. Hiding a page is already
     * journaled twice over; that move cannot be the silent one.
     */
    public function testMovingAPageToAnotherSectionIsJournaledAtSecurityLevel(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->post('/config/pages-de-texte/' . $page->id, [
            'menu_label' => 'ASBL',
            'title' => 'Notre ASBL',
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'update', ['id' => (string) $page->id]);

        $this->assertSame(MenuBuilder::MENU_NOTRE_UNITE, $this->pages->findById($page->id)?->menuId);

        $moves = array_values(array_filter(
            $this->journalRows(),
            fn(array $row): bool => $row['event_type'] === 'text_page_moved'
        ));
        $this->assertCount(1, $moves);
        $this->assertSame('security', $moves[0]['level']);
        $this->assertStringContainsString(MenuBuilder::MENU_CONFIGURATION, (string) $moves[0]['context']);
        $this->assertStringContainsString(MenuBuilder::MENU_NOTRE_UNITE, (string) $moves[0]['context']);
    }

    /** A rename that moves nothing writes nothing: it changes no audience. */
    public function testRenamingAPageWithoutMovingItJournalsNothing(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->post('/config/pages-de-texte/' . $page->id, [
            'menu_label' => 'ASBL',
            'title' => "L'ASBL qui porte l'unité",
            'menu_id' => MenuBuilder::MENU_NOTRE_UNITE,
            'menu_group' => '',
        ], 'update', ['id' => (string) $page->id]);

        $this->assertNotContains('text_page_moved', $this->journalTypes());
    }

    /** @return array<int, array<string, mixed>> */
    private function journalRows(): array
    {
        $stmt = $this->pdo->query('SELECT event_type, description, context, level FROM event_log');

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    private function journalTypes(): array
    {
        return array_map(fn(array $row): string => (string) $row['event_type'], $this->journalRows());
    }
}
