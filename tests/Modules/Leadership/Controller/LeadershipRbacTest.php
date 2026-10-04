<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberYearService;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\Module\ModuleManifest;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Leadership\Controller\FormationMappingController;
use Modules\Leadership\Controller\LeadershipController;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\CandidateDetector;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\ObligationsService;
use Modules\Leadership\Service\StewardService;
use Modules\Leadership\Service\SupervisionCalculator;
use Modules\Leadership\Service\TrainingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Leadership\LeadershipTestHelper;
use Tests\TestTwig;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * The RBAC boundary of every leadership route, exercised through the real
 * Router/RbacGuard/FrontController stack and the real templates — so a
 * template that fails to compile fails here rather than in production.
 *
 * Every route is role_min: admin, the floor of the Espace chefs d'U menu
 * (Core\Module\ModuleManifest::MENU_MIN_ROLES). A chief, one level below,
 * is refused.
 */
#[Group('database')]
class LeadershipRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private int $scoutYearId;
    private ?RecordingMassMailDraft $draft;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        LeadershipTestHelper::createTables($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(DatabaseTestHelper::scoutYear());
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->twig = $this->buildTwig();
        $this->draft = new RecordingMassMailDraft();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'overview' => ['/admin/leadership', 'LeadershipController', 'index'],
            'training' => ['/admin/leadership/training', 'LeadershipController', 'training'],
            'obligations' => ['/admin/leadership/obligations', 'LeadershipController', 'obligations'],
            'stewards' => ['/admin/leadership/stewards', 'LeadershipController', 'stewards'],
            'configuration' => ['/admin/leadership/configuration', 'LeadershipController', 'configuration'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testAdminReachesEveryPage(string $path, string $controller, string $action): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->frontController($path, $controller, $action)
            ->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "Expected 200 on {$path}, got {$response->getStatusCode()}: " . $response->getBody()
        );
    }

    #[DataProvider('routeProvider')]
    public function testChiefIsRefusedOnEveryPage(string $path, string $controller, string $action): void
    {
        AuthSession::login(2, 'animateur@test.be', 'chief');

        $response = $this->frontController($path, $controller, $action)
            ->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheMappingRouteIsAdminOnlyToo(): void
    {
        AuthSession::login(2, 'animateur@test.be', 'chief');

        $response = $this->frontController('/admin/leadership/configuration/mapping', 'FormationMappingController', 'save', 'POST')
            ->handle(new Request('POST', '/admin/leadership/configuration/mapping', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /** The export and the draft carry the same people as the pages: admin, never chief (#727). */
    public function testTheExportAndTheDraftAreAdminOnlyToo(): void
    {
        AuthSession::login(2, 'animateur@test.be', 'chief');

        $export = $this->frontController('/admin/leadership/{page}/export', 'LeadershipController', 'export')
            ->handle(new Request('GET', '/admin/leadership/training/export', [], [], [], []));
        $draft = $this->frontController('/admin/leadership/draft', 'LeadershipController', 'draft', 'POST')
            ->handle(new Request('POST', '/admin/leadership/draft', [], ['list' => 'obligations-candidates'], [], []));

        $this->assertSame(403, $export->getStatusCode());
        $this->assertSame(403, $draft->getStatusCode());
    }

    /**
     * The module's central prohibition, checked against what the pages
     * actually render rather than against the code that builds them.
     *
     * The phrases below are ASSERTIONS ABOUT A DOCUMENT — "CQA en ordre",
     * "extrait valide" — and none of them may ever reach a page. The bare
     * words cannot be banned: the Obligations page has to explain that Desk
     * flags somebody "tant que les obligations ne sont pas en ordre", which
     * is a sentence about how Desk behaves and not a claim about anybody's
     * paperwork. Banning the word rather than the claim would have forced
     * that explanation off the page, which is the opposite of what the rule
     * is for. Tests\Modules\Leadership\Service\LeadershipProhibitionsTest
     * covers the other half: the per-person text, where a status claim
     * would actually be dangerous.
     *
     * @return list<string>
     */
    private static function forbiddenClaims(): array
    {
        return [
            'cqa en ordre', 'cqa valide', 'cqa à jour', 'cqa manquant', 'cqa expiré',
            'extrait en ordre', 'extrait valide', 'extrait à jour', 'extrait manquant', 'extrait expiré',
            'ecj en ordre', 'ecj valide', 'casier en ordre', 'casier valide',
            'éligible one', 'en règle', 'est conforme', 'non conforme',
        ];
    }

    #[DataProvider('routeProvider')]
    public function testNoPageEverClaimsAPaperworkStatus(string $path, string $controller, string $action): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        $body = $this->frontController($path, $controller, $action)
            ->handle(new Request('GET', $path, [], [], [], []))
            ->getBody();

        foreach (self::forbiddenClaims() as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                "The page {$path} must never claim a paperwork status (found « {$forbidden} »)."
            );
        }
    }

    /** Every page states which import its figures come from. */
    #[DataProvider('routeProvider')]
    public function testEveryPageStatesTheValidityDate(string $path, string $controller, string $action): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) VALUES (?, 1, 1, ?)'
        );
        $stmt->execute([$this->scoutYearId, '2025-10-02 19:30:00']);

        $body = $this->frontController($path, $controller, $action)
            ->handle(new Request('GET', $path, [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('02/10/2025', $body);
        $this->assertStringContainsString('Seuils appliqués', $body);
    }

    public function testAPageWithNoImportSaysSoRatherThanShowingNothing(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = $this->frontController('/admin/leadership', 'LeadershipController', 'index')
            ->handle(new Request('GET', '/admin/leadership', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString("aucun import Desk n'a encore été enregistré", $body);
    }

    /**
     * The candidate message the Obligations page renders is the one the
     * service decided, wording and all — this is the surface a chief acts
     * on, so it is worth pinning end to end and not only in a unit test.
     */
    public function testTheObligationsPageRendersTheExactCandidateWording(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        $body = $this->frontController('/admin/leadership/obligations', 'LeadershipController', 'obligations')
            ->handle(new Request('GET', '/admin/leadership/obligations', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('CQA à signer', $body);
        $this->assertStringNotContainsString('CQA ou extrait', $body);
    }

    /** The vocabulary mapping left the Formations page for Configuration (#727). */
    public function testTheTrainingPageNoLongerCarriesTheMappingBlock(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedFormationLevels();

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController('/admin/leadership/training', 'LeadershipController', 'training')
            ->handle(new Request('GET', '/admin/leadership/training', [], [], [], []))
            ->getBody());

        $this->assertStringNotContainsString('formation-mapping', $body);
        $this->assertStringNotContainsString('leadership-mapping-select', $body);
        // Said where the count it shortens is read, with the way to fix it.
        $this->assertStringContainsString("1 niveau de formation n'est pas reconnu.", $body);
        $this->assertStringContainsString('Le nombre de brevetés peut être incomplet.', $body);
        $this->assertStringContainsString('<a href="/admin/leadership/configuration" class="alert-link">Configurer les niveaux de formation</a>', $body);
    }

    /**
     * The Configuration page: « À configurer » first, then the decisions,
     * each a compact line — the select and the trash side by side, no
     * « Modifier », no « Rattachée à ».
     */
    public function testTheConfigurationPageSeparatesWhatToConfigureFromWhatIsDecided(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedFormationLevels();

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController(
            '/admin/leadership/configuration',
            'LeadershipController',
            'configuration'
        )->handle(new Request('GET', '/admin/leadership/configuration', [], [], [], []))->getBody());

        $unresolved = strpos($body, 'id="leadership-mapping-unresolved"');
        $decided = strpos($body, 'id="leadership-mapping-decided"');
        $this->assertNotFalse($unresolved);
        $this->assertNotFalse($decided);
        $zorglub = strpos($body, '<code>Zorglub</code>');
        $maison = strpos($body, '<code>Wording maison</code>');
        $this->assertTrue($unresolved < $zorglub && $zorglub < $decided, 'Zorglub is still to configure');
        $this->assertGreaterThan($decided, $maison, 'Wording maison is already decided');

        $this->assertStringContainsString('1 personne cette année', $body);
        $this->assertStringContainsString('Choisir une étape…', $body);
        $this->assertStringContainsString('title="Supprimer le rattachement" aria-label="Supprimer le rattachement de « Wording maison »"', $body);
        $this->assertStringNotContainsString('Rattachée à', $body);
        $this->assertStringNotContainsString('Modifier', $body);
        $this->assertStringContainsString('leadership-configuration.js', $body);
    }

    /**
     * The five pages carry the same rail, the current one marked active
     * with aria-current (#727) — and the breadcrumbs stay.
     */
    #[DataProvider('routeProvider')]
    public function testEveryPageCarriesTheEncadrementRailWithItselfActive(string $path, string $controller, string $action): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->twig = $this->buildTwig($path);

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController($path, $controller, $action)
            ->handle(new Request('GET', $path, [], [], [], []))
            ->getBody());

        foreach ([
            '/admin/leadership' => 'Tableau de bord',
            '/admin/leadership/training' => 'Formations',
            '/admin/leadership/obligations' => 'Obligations',
            '/admin/leadership/stewards' => 'Intendants',
            '/admin/leadership/configuration' => 'Configuration',
        ] as $url => $label) {
            $this->assertMatchesRegularExpression(
                '#<a href="' . preg_quote($url, '#') . '" class="[^"]*nav-link[^"]*" data-id="[^"]*" data-selected="'
                    . ($url === $path ? 'true" aria-current="page"' : 'false" ') . '>.*?<span>' . $label . '</span>#',
                $body,
                "{$label} on {$path}"
            );
        }
        $this->assertSame(1, substr_count($body, 'aria-current="page"'));
    }

    /**
     * One Desk value nobody has decided about, and one that has already
     * been mapped — the two states the mapping block exists to show.
     */
    /**
     * Roadmap IT-20: only the BACV counts towards the ONE ratio, so a
     * brevet whose kind nobody recorded stopped counting — and the figure
     * fell on the day of the update. The sentence above the ratio is what
     * makes that fall understandable; shipping the change without it would
     * have been worse than not changing the number at all.
     */
    public function testTheRatioSaysWhoStoppedCountingAndWhereToFixIt(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        // Maps « Wording maison » to the legacy box, on one animateur.
        $this->seedFormationLevels();

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController(
            '/admin/leadership/training',
            'LeadershipController',
            'training'
        )->handle(new Request('GET', '/admin/leadership/training', [], [], [], []))->getBody());

        $this->assertStringContainsString('1 animateur a un niveau de formation à préciser', $body);
        $this->assertStringContainsString("Il n'est pas compté dans le ratio ONE", $body);
        $this->assertStringContainsString('/admin/leadership/configuration', $body);
    }

    /**
     * No legacy box anywhere, no sentence: a warning that shows when there
     * is nothing to do is a warning readers learn to skip.
     */
    public function testNothingToPreciseMeansNoSentenceAboveTheRatio(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController(
            '/admin/leadership/training',
            'LeadershipController',
            'training'
        )->handle(new Request('GET', '/admin/leadership/training', [], [], [], []))->getBody());

        $this->assertStringNotContainsString('niveau de formation à préciser', $body);
    }

    private function seedFormationLevels(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['ANIM', 'Animateur', 'chief']);
        $functionId = (int) $this->pdo->lastInsertId();

        foreach (['Zorglub', 'Wording maison'] as $index => $level) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['DF' . $index]);
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, formation_level)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId,
                $this->scoutYearId,
                $encryption->encrypt('Prénom' . $index, 'member_years.first_name'),
                $encryption->encrypt('Nom' . $index, 'member_years.last_name'),
                $level,
            ]);
            $memberYearId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, start_date, is_main_function)
                 VALUES (?, ?, NULL, ?, 1)'
            );
            $stmt->execute([$memberYearId, $functionId, '2025-09-01']);
        }

        (new FormationLevelMappingRepository(Connection::withPdo($this->pdo)))
            ->save('Wording maison', \Modules\Leadership\FormationStep::BREVET);
    }

    // --- Getting back out -----------------------------------------------

    /**
     * Only `/admin/leadership` carries a menu entry, so its three
     * sub-pages showed « Espace chefs d'U › Formations » and offered no
     * way back to the hub whose card had just sent the visitor there. The
     * breadcrumb is the site's only back affordance (design.md §7.3),
     * which made a one-click page a dead end.
     */
    #[DataProvider('subPageProvider')]
    public function testEverySubPageLinksBackToItsHub(string $path, string $controller, string $action): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) $this->withBreadcrumb($path, $controller, $action)
                ->handle(new Request('GET', $path, [], [], [], []))
                ->getBody()
        );

        $this->assertStringContainsString('<a href="/admin/leadership" class="text-decoration-none">Encadrement</a>', $body, $path);
    }

    public function testTheHubItselfCarriesNoTrail(): void
    {
        // It IS the ancestor; a link back to itself would be noise.
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = (string) $this->withBreadcrumb('/admin/leadership', 'LeadershipController', 'index')
            ->handle(new Request('GET', '/admin/leadership', [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString('breadcrumb-bar--has-trail', $body);
    }

    /**
     * The same front controller as `frontController()`, with the route's
     * real `breadcrumb` declaration attached — without it the bar renders
     * the home icon and stops, and a trail assertion would pass on an
     * empty bar.
     *
     * The declaration is read from the real `module.json` rather than
     * retyped here, because it is now the manifest that carries the way
     * back to the hub: the controller used to pass a hand-built trail
     * naming the same page the route already declared, and the bar
     * rendered « Encadrement / Encadrement ». A copy of the declaration
     * in this file would keep passing after somebody deleted the real
     * one. The hub route itself is registered too, since
     * Router::ancestorTrailFor() drops an ancestor whose route it cannot
     * resolve — which is right for a disabled module and would silently
     * empty this assertion.
     */
    private function withBreadcrumb(string $path, string $controller, string $action): FrontController
    {
        $class = "Modules\\Leadership\\Controller\\{$controller}";

        $router = new Router();
        $router->addRoute('GET', '/admin/leadership', $class, 'index', 'admin');
        $router->addRoute('GET', $path, $class, $action, 'admin', $this->manifestBreadcrumbFor($path));

        $configFile = sys_get_temp_dir() . '/test_leadership_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController($class, $this->instantiate($controller));

        return $frontController;
    }

    /**
     * @return ?array{label: string, parents: array<string>, ancestors?: array<int, array{label: string, path: string}>}
     */
    private function manifestBreadcrumbFor(string $path): ?array
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/leadership/module.json');
        foreach ($manifest->routes as $route) {
            if ($route['path'] === $path && strtoupper($route['method']) === 'GET') {
                return $route['breadcrumb'];
            }
        }

        self::fail("No GET route declared for {$path} in modules/leadership/module.json");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function subPageProvider(): array
    {
        $routes = self::routeProvider();
        unset($routes['overview']);

        return $routes;
    }

    // --- The lists can be acted on --------------------------------------

    /**
     * These pages answer « à qui dois-je parler », and answered it with a
     * list of names and no way to reach any of them: a chef d'unité read
     * sixteen names here and then looked each one up in Desk.
     */
    public function testEveryNameLinksToThatPersonsFiche(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        $body = $this->frontController('/admin/leadership/obligations', 'LeadershipController', 'obligations')
            ->handle(new Request('GET', '/admin/leadership/obligations', [], [], [], []))
            ->getBody();

        $this->assertMatchesRegularExpression(
            '#<a href="/members/\d+">Prénom1 Nom1</a>#',
            (string) preg_replace('/\s+/', ' ', $body)
        );
    }

    public function testAnAddressAndANumberDeskHoldsAreLinksAndTheListOffersADraft(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        $body = (string) preg_replace(
            '/\s+/',
            ' ',
            $this->frontController('/admin/leadership/obligations', 'LeadershipController', 'obligations')
                ->handle(new Request('GET', '/admin/leadership/obligations', [], [], [], []))
                ->getBody()
        );

        $this->assertStringContainsString('mailto:candidat@example.org', $body);
        $this->assertStringContainsString('<a href="tel:0470123456">0470 12 34 56</a>', $body);
        $this->assertStringContainsString('<input type="hidden" name="list" value="obligations-candidates">', $body);
        $this->assertStringContainsString("Créer un brouillon d'e-mail", $body);
        $this->assertStringNotContainsString('Copier les adresses', $body);
        $this->assertStringContainsString('href="/admin/leadership/obligations/export"', $body);
    }

    public function testTheListSaysHowManyPeopleItCannotReach(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        $body = (string) preg_replace(
            '/\s+/',
            ' ',
            $this->frontController('/admin/leadership/stewards', 'LeadershipController', 'stewards')
                ->handle(new Request('GET', '/admin/leadership/stewards', [], [], [], []))
                ->getBody()
        );

        // Said out loud: a list of sixteen people written to twelve of them
        // reads as sixteen people prevented, and nobody notices the four.
        $this->assertStringContainsString('1 personne sans adresse dans Desk', $body);
        // And with nobody to write to, no draft is offered at all.
        $this->assertStringNotContainsString('value="stewards-registrations"', $body);
    }

    /**
     * The draft is built from the list computed again on the server — only
     * the people with an address, nothing sent, the visitor sent to the
     * composer (#727).
     */
    public function testTheDraftHoldsTheListsAddressesAndLandsOnTheComposer(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();
        $token = \Core\Security\CsrfGuard::generateToken();

        $response = $this->frontController('/admin/leadership/draft', 'LeadershipController', 'draft', 'POST')
            ->handle(new Request('POST', '/admin/leadership/draft', [], ['list' => 'obligations-candidates', '_csrf_token' => $token], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mass-mail/draft/9', $response->getHeaders()['Location'] ?? null);
        $this->assertCount(1, $this->draft->calls);
        $this->assertSame('Encadrement — Candidats au dernier import', $this->draft->calls[0]['label']);
        $this->assertSame(
            [['email' => 'candidat@example.org', 'values' => ['Nom' => 'Prénom1 Nom1', 'Section' => 'Louveteaux']]],
            $this->draft->calls[0]['rows']
        );
        $this->assertJournaled('leadership_list_draft_created', '"recipient_count":1');
    }

    public function testAnUnknownListIsNotADraft(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $token = \Core\Security\CsrfGuard::generateToken();

        $response = $this->frontController('/admin/leadership/draft', 'LeadershipController', 'draft', 'POST')
            ->handle(new Request('POST', '/admin/leadership/draft', [], ['list' => 'tout-le-monde', '_csrf_token' => $token], [], []));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->draft->calls);
    }

    /** A bad token on an unknown list goes back to the dashboard, a real page. */
    public function testABadTokenOnAnUnknownListReturnsToTheDashboard(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        \Core\Security\CsrfGuard::generateToken();

        $response = $this->frontController('/admin/leadership/draft', 'LeadershipController', 'draft', 'POST')
            ->handle(new Request('POST', '/admin/leadership/draft', [], ['list' => 'tout-le-monde', '_csrf_token' => 'faux'], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/leadership', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->draft->calls);
    }

    /** Without mass_mail, the lists offer no draft button at all. */
    public function testWithoutMassMailNoDraftIsOffered(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();
        $this->draft = null;

        $body = (string) $this->frontController('/admin/leadership/obligations', 'LeadershipController', 'obligations')
            ->handle(new Request('GET', '/admin/leadership/obligations', [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString("Créer un brouillon d'e-mail", $body);
    }

    /** One spreadsheet per page, its lists' people with their phone. */
    public function testTheExportIsASpreadsheetOfThePagesLists(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedCandidateAndSteward();

        ob_start();
        $response = $this->frontController('/admin/leadership/{page}/export', 'LeadershipController', 'export')
            ->handle(new Request('GET', '/admin/leadership/obligations/export', [], [], [], []));
        $response->send();
        $bytes = (string) ob_get_clean();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('encadrement-obligations-', (string) ($response->getHeaders()['Content-Disposition'] ?? ''));
        $file = tempnam(sys_get_temp_dir(), 'leadership-xlsx-');
        file_put_contents((string) $file, $bytes);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $file)->getActiveSheet()->toArray();
        @unlink((string) $file);

        $this->assertSame(['Liste', 'Nom', 'Totem', 'Section', 'Détail', 'Remarque', 'E-mail', 'Téléphone', 'Échéance'], $sheet[0]);
        $this->assertSame('Candidats au dernier import', $sheet[1][0]);
        $this->assertSame('Prénom1 Nom1', $sheet[1][1]);
        $this->assertSame('candidat@example.org', $sheet[1][6]);
        $this->assertSame('0470 12 34 56', $sheet[1][7]);

        // Journaled with the page and a count, never a name or an address.
        $this->assertJournaled('leadership_list_exported', '"page":"obligations"');
    }

    private function assertJournaled(string $action, string $contextFragment): void
    {
        $stmt = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ?');
        $stmt->execute([$action]);
        $context = $stmt->fetchColumn();
        $this->assertNotFalse($context, $action . ' is journaled');
        $this->assertStringContainsString($contextFragment, (string) $context);
        $this->assertStringNotContainsString('candidat@example.org', (string) $context);
        $this->assertStringNotContainsString('Nom1', (string) $context);
    }

    public function testAnUnknownPageHasNoExport(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->frontController('/admin/leadership/{page}/export', 'LeadershipController', 'export')
            ->handle(new Request('GET', '/admin/leadership/configuration/export', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- fixtures -------------------------------------------------------

    /**
     * A 17-year-old candidate animateur (so the "CQA à signer" branch) and
     * a steward, both in one section.
     */
    private function seedCandidateAndSteward(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('Louveteaux', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'LOUV', 'Louveteaux']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['CAND', 'Candidat animateur', 'chief']);
        $candidateFunction = (int) $this->pdo->lastInsertId();
        $stmt->execute(['INTE', 'Intendant', 'intendant']);
        $stewardFunction = (int) $this->pdo->lastInsertId();

        $birthDate = (new \DateTimeImmutable('today'))->modify('-17 years')->format('Y-m-d');

        // One of the two carries an address and the other does not, on
        // purpose: that is what makes the « sans adresse dans Desk » count
        // a real assertion rather than a branch nothing exercises.
        $rows = [
            [1, $candidateFunction, $birthDate, 'candidat@example.org', '0470 12 34 56'],
            [2, $stewardFunction, null, null, null],
        ];

        foreach ($rows as [$index, $functionId, $birth, $email, $mobile]) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['D' . $index]);
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, email_encrypted, mobile_encrypted)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId,
                $this->scoutYearId,
                $encryption->encrypt('Prénom' . $index, 'member_years.first_name'),
                $encryption->encrypt('Nom' . $index, 'member_years.last_name'),
                $birth === null ? null : $encryption->encrypt($birth, 'member_years.birth_date'),
                $email === null ? null : $encryption->encrypt($email, 'member_years.email'),
                $mobile === null ? null : $encryption->encrypt($mobile, 'member_years.mobile'),
            ]);
            $memberYearId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, start_date, is_main_function)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $stmt->execute([$memberYearId, $functionId, $sectionId, '2025-09-01']);
        }
    }

    private function frontController(string $path, string $controller, string $action, string $method = 'GET'): FrontController
    {
        $class = "Modules\\Leadership\\Controller\\{$controller}";

        $router = new Router();
        $router->addRoute($method, $path, $class, $action, 'admin');

        $configFile = sys_get_temp_dir() . '/test_leadership_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController($class, $this->instantiate($controller));

        return $frontController;
    }

    private function instantiate(string $controller): object
    {
        $connection = Connection::withPdo($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repository = new LeadershipRepository($connection, $encryption);
        $mappingRepository = new FormationLevelMappingRepository($connection);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        if ($controller === 'FormationMappingController') {
            return new FormationMappingController($this->twig, $mappingRepository, $journalService);
        }

        $obligationsService = new ObligationsService(new CandidateDetector());
        $settingService = new SettingService(new SettingRepository($this->pdo));

        return new LeadershipController(
            $this->twig,
            $repository,
            $mappingRepository,
            new FormationLevelResolver(),
            new TrainingService(
                $repository,
                new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
),
                new MemberYearService(),
                new SupervisionCalculator()
            ),
            $obligationsService,
            new StewardService($repository, $obligationsService),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                $settingService,
                new MemberYearRepository($this->pdo)
            ),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $journalService,
            $this->draft
        );
    }

    private function buildTwig(string $currentPath = '/'): Environment
    {
        $twig = TestTwig::create(['leadership']);

        // The shipped filters themselves, not a rendering that resembles
        // them (Core\View, issue #465).

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', $currentPath);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}

/** Records the drafts asked for, and answers a composer URL. */
final class RecordingMassMailDraft implements \Modules\MassMail\Api\MassMailDraftInterface
{
    /** @var list<array{label: string, rows: list<array{email: string, values: array<string, string>}>}> */
    public array $calls = [];

    public function createMergeDraft(
        string $label,
        string $subject,
        array $columns,
        array $rows,
        string $actorRole,
        string $actorEmail,
        ?int $actorAccountId,
        ?string $bodyHtml = null
    ): string {
        $this->calls[] = ['label' => $label, 'rows' => $rows];

        return '/mass-mail/draft/9';
    }
}
