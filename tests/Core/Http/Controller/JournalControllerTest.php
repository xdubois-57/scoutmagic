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
use Tests\TestTwig;

#[\PHPUnit\Framework\Attributes\Group('database')]
class JournalControllerTest extends TestCase
{
    private JournalController $controller;
    private JournalService $journalService;
    private JournalRepository $journalRepo;
    private UserAccountRepository $userRepo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo = $pdo;
        $this->journalRepo = new JournalRepository($pdo);
        $this->journalService = new JournalService($this->journalRepo);
        $this->userRepo = new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $this->controller = new JournalController($twig, $this->journalRepo, $this->userRepo);
    }

    public function testIndexRendersEmptyJournal(): void
    {
        $request = new Request('GET', '/admin/journal', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        // The page title matches the route's own label (« Journal », same as
        // the <title> and menu entry) since the page_header migration.
        $this->assertStringContainsString('<h1 class="h3 mb-0">Journal</h1>', $response->getBody());
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

    /**
     * The page half of point 17: an uncaught error is only "consultable
     * from the site" if the journal page offers the level, filters on it,
     * and tells it apart from the other two at a glance.
     */
    public function testIndexOffersAndAppliesTheErrorLevel(): void
    {
        $this->journalService->log('core', 'login_success', 'info', 'Connexion réussie');
        $this->journalService->log(
            'core',
            'uncaught_error',
            'error',
            'Erreur non interceptée : RuntimeException dans /app/core/X.php:12'
        );

        $unfiltered = $this->controller->index(new Request('GET', '/admin/journal', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('<option value="error">Erreur</option>', $unfiltered);
        $this->assertStringContainsString('badge text-bg-danger', $unfiltered);

        $filtered = $this->controller
            ->index(new Request('GET', '/admin/journal', ['level' => 'error'], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('1 entrée', $filtered);
        $this->assertStringContainsString('Erreur non interceptée', $filtered);
        $this->assertStringNotContainsString('Connexion réussie', $filtered);
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

    /**
     * Regression: an account left over from a prior install/key (e.g. a
     * reinstall that generated a fresh encryption key against a database
     * that still had old rows) can have an email_encrypted blob that no
     * longer decrypts with the current key. That must degrade to "—" for
     * that one entry, not fatal the whole journal page — this is exactly
     * the page an operator would reach for while diagnosing such an issue.
     */
    public function testIndexDoesNotCrashWhenAnAccountsEmailCannotBeDecrypted(): void
    {
        $actor = $this->userRepo->create('actor@test.be');
        $this->pdo->prepare('UPDATE user_accounts SET email_encrypted = ? WHERE id = ?')
            ->execute([random_bytes(40), $actor->id]);
        $this->journalRepo->insert('core', 'login_success', 'security', 'Connexion', null, $actor->id, '203.0.113.9');

        $request = new Request('GET', '/admin/journal', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Connexion', $response->getBody());
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

    /**
     * The level filter offers every value `event_log.level` accepts, read
     * from the schema itself: `warning` was written by a hundred call sites
     * and could not be filtered on, because the page knew three of the four
     * (issue #1450).
     */
    public function testTheLevelFilterOffersEveryValueOfTheEnum(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 4) . '/schema/core.sql');
        $this->assertSame(1, preg_match('/^\s*level ENUM\(([^)]+)\)/m', $schema, $matches));
        preg_match_all("/'([a-z_]+)'/", $matches[1], $levels);
        $this->assertNotEmpty($levels[1]);

        $body = $this->controller->index(new Request('GET', '/admin/journal', [], [], [], []), [])->getBody();
        preg_match('/<select[^>]*name="level".*?<\/select>/s', $body, $select);
        $this->assertNotEmpty($select, 'the level filter is no longer a select named « level »');
        preg_match_all('/<option value="([^"]*)"/', $select[0], $offered);

        $this->assertSame(array_merge([''], $levels[1]), $offered[1]);
        $this->assertStringContainsString('<option value="warning">Avertissement</option>', $select[0]);
    }

    /**
     * A `warning` line has its own badge — it used to be drawn as « info » —
     * in the table and in the cards alike.
     */
    public function testAWarningLineHasItsOwnBadgeInBothRenderings(): void
    {
        $this->journalService->log('core', 'remote_backup_failed', 'warning', 'Un envoi hors site a échoué et sera repris');

        $body = $this->controller
            ->index(new Request('GET', '/admin/journal', ['level' => 'warning'], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('1 entrée', $body);
        $this->assertSame(2, substr_count($body, 'class="badge bg-warning-subtle text-warning-emphasis"'));
        $this->assertSame(2, substr_count($body, '>avertissement</span>'));
        $this->assertStringNotContainsString('>info</span>', $body);
        $this->assertStringContainsString('<option value="warning" selected>', $body);
    }

    /**
     * The event key is shown, and a « Type » filter built from the keys the
     * journal holds isolates one of them.
     */
    public function testTheTypeFilterListsTheKeysAndIsolatesOne(): void
    {
        $this->journalService->log('core', 'auto_backup_failed', 'error', 'Échec de la sauvegarde automatique');
        $this->journalService->log('core', 'scheduler_task_done', 'info', 'Tâche planifiée « purge » terminée');

        $unfiltered = $this->controller->index(new Request('GET', '/admin/journal', [], [], [], []), [])->getBody();
        preg_match('/<select[^>]*name="type".*?<\/select>/s', $unfiltered, $select);
        $this->assertNotEmpty($select, 'no « Type » filter on the page');
        preg_match_all('/<option value="([^"]*)"/', $select[0], $offered);
        $this->assertSame(['', 'auto_backup_failed', 'scheduler_task_done'], $offered[1]);
        $this->assertStringContainsString('<code>auto_backup_failed</code>', $unfiltered);

        $filtered = $this->controller
            ->index(new Request('GET', '/admin/journal', ['type' => 'auto_backup_failed'], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('1 entrée', $filtered);
        $this->assertStringContainsString('Échec de la sauvegarde automatique', $filtered);
        $this->assertStringNotContainsString('Tâche planifiée « purge » terminée', $filtered);
        $this->assertStringContainsString('<option value="auto_backup_failed" selected>', $filtered);
    }

    /**
     * A key the journal no longer holds stays on the select once it is the
     * filter applied, so the page never shows « Tous » over an empty list.
     */
    public function testATypeFilterWithNoLineLeftStaysSelected(): void
    {
        $this->journalService->log('core', 'login_success', 'info', 'Connexion réussie');

        $body = $this->controller
            ->index(new Request('GET', '/admin/journal', ['type' => 'update_failed'], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('0 entrée', $body);
        $this->assertStringContainsString('<option value="update_failed" selected>', $body);
    }

    /**
     * The mass-mail module tells its readers to search « #12 » (see
     * `MassMailService::journalDeliverySent()`). The page-2 link used to be
     * concatenated, so `#` ended the query string there and page 2 showed
     * the whole journal (issue #1450). Following the link must land on the
     * second page of the SAME filtered list.
     */
    public function testPaginationKeepsASearchForHashTwelveAndEveryOtherFilter(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->journalService->log('mass_mail', 'delivery_sent', 'info', "Envoi #12 : message #{$i} envoyé");
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->journalService->log('mass_mail', 'delivery_sent', 'info', "Envoi #7 : message #{$i} envoyé");
        }
        $this->journalService->log('core', 'login_success', 'info', 'Connexion #12 & co');

        $filters = ['search' => '#12', 'category' => 'mass_mail', 'type' => 'delivery_sent', 'level' => 'info'];
        $first = $this->controller->index(new Request('GET', '/admin/journal', $filters, [], [], []), [])->getBody();
        $this->assertStringContainsString('55 entrées', $first);

        $this->assertSame(1, preg_match('/<a class="page-link" href="([^"]*page=2)"/', $first, $link), 'no link to page 2');
        $href = html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5);
        $this->assertStringNotContainsString('#', $href);
        $this->assertSame('?', $href[0]);
        parse_str(substr($href, 1), $query);
        $this->assertEquals($filters + ['page' => '2'], array_intersect_key($query, $filters + ['page' => '']));

        $second = $this->controller->index(new Request('GET', '/admin/journal', $query, [], [], []), [])->getBody();
        $this->assertStringContainsString('55 entrées', $second);
        $this->assertSame(5, substr_count($second, 'class="journal-row"'));
        $this->assertStringNotContainsString('Envoi #7', $second);
        $this->assertStringNotContainsString('Connexion #12', $second);
    }

    /** A search holding « & » is one parameter, not the start of another. */
    public function testPaginationKeepsASearchHoldingAnAmpersand(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->journalService->log('core', 'test_event', 'info', "Paiement A&B {$i}");
        }

        $body = $this->controller
            ->index(new Request('GET', '/admin/journal', ['search' => 'A&B'], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('href="?search=A%26B&amp;page=2"', $body);
    }

    /** With no filter at all, the links carry the page alone. */
    public function testPaginationWithoutFiltersCarriesThePageOnly(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->journalService->log('core', 'test_event', 'info', "Entrée {$i}");
        }

        $body = $this->controller->index(new Request('GET', '/admin/journal', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('href="?page=2"', $body);
    }
}
