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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

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

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        LeadershipTestHelper::createTables($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['2025-2026', '2025-09-01', '2026-08-31']);
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->twig = $this->buildTwig();

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

        $response = $this->frontController('/admin/leadership/training/mapping', 'FormationMappingController', 'save', 'POST')
            ->handle(new Request('POST', '/admin/leadership/training/mapping', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
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

    public function testTheMappingBlockOpensWhenTheRedirectSaysSo(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $shut = (string) $this->frontController('/admin/leadership/training', 'LeadershipController', 'training')
            ->handle(new Request('GET', '/admin/leadership/training', [], [], [], []))
            ->getBody();
        $open = (string) $this->frontController('/admin/leadership/training', 'LeadershipController', 'training')
            ->handle(new Request('GET', '/admin/leadership/training', ['mapping' => '1'], [], [], []))
            ->getBody();

        $this->assertStringContainsString('<div class="collapse mt-3" id="formation-mapping">', $shut);
        $this->assertStringContainsString('<div class="collapse show mt-3" id="formation-mapping">', $open);
        $this->assertStringContainsString('aria-expanded="true"', $open);
    }

    /**
     * The mapping block, all the way through `training()` and out of the
     * template — the two lists it renders are the same row with a
     * different verb (`partials/_mapping_row.html.twig`), and nothing
     * before this test rendered either of them.
     */
    public function testTheMappingBlockRendersBothListsThroughTheSharedRow(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        $this->seedFormationLevels();

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController(
            '/admin/leadership/training',
            'LeadershipController',
            'training'
        )->handle(new Request('GET', '/admin/leadership/training', ['mapping' => '1'], [], [], []))->getBody());

        // Not recognised: offered a placeholder and a « Rattacher » button,
        // and nothing to remove — there is no decision to undo yet.
        $this->assertStringContainsString('<code>Zorglub</code>', $body);
        $this->assertStringContainsString('1 personne avec cette valeur', $body);
        $this->assertStringContainsString('Choisir une étape…', $body);
        $this->assertStringContainsString('Rattacher', $body);

        // Already decided: the stored step comes back selected, the verb is
        // « Modifier », and the decision can be removed.
        $this->assertStringContainsString('<code>Wording maison</code>', $body);
        $this->assertStringContainsString('Rattachée à', $body);
        $this->assertStringContainsString('Modifier', $body);
        $this->assertStringContainsString('Supprimer le rattachement de Wording maison', $body);
    }

    /**
     * One Desk value nobody has decided about, and one that has already
     * been mapped — the two states the mapping block exists to show.
     */
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
     */
    private function withBreadcrumb(string $path, string $controller, string $action): FrontController
    {
        $class = "Modules\\Leadership\\Controller\\{$controller}";

        $router = new Router();
        $router->addRoute('GET', $path, $class, $action, 'admin', [
            'label' => 'Encadrement',
            'parents' => ["Espace chefs d'U"],
        ]);

        $configFile = sys_get_temp_dir() . '/test_leadership_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController($class, $this->instantiate($controller));

        return $frontController;
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

    public function testAnAddressDeskHoldsBecomesAMailtoAndCanBeCopied(): void
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
        $this->assertStringContainsString('data-email="candidat@example.org"', $body);
        $this->assertStringContainsString('data-copy-emails="obligations-candidates"', $body);
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
        // And with nothing to copy, the button is not offered at all.
        $this->assertStringNotContainsString('data-copy-emails="stewards-registrations"', $body);
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
            [1, $candidateFunction, $birthDate, 'candidat@example.org'],
            [2, $stewardFunction, null, null],
        ];

        foreach ($rows as [$index, $functionId, $birth, $email]) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['D' . $index]);
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, email_encrypted)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId,
                $this->scoutYearId,
                $encryption->encrypt('Prénom' . $index, 'member_years.first_name'),
                $encryption->encrypt('Nom' . $index, 'member_years.last_name'),
                $birth === null ? null : $encryption->encrypt($birth, 'member_years.birth_date'),
                $email === null ? null : $encryption->encrypt($email, 'member_years.email'),
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
                new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo)),
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
            new EditableContentService(new EditableContentRepository($this->pdo))
        );
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/leadership/views', 'leadership');

        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);

        // The shared French format filters (core/View/TwigFactory.php) the
        // templates under test use — same rendering as the shipped ones.
        $twig->addFilter(new TwigFilter(
            'date_fr',
            fn ($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')
        ));
        $twig->addFilter(new TwigFilter(
            'datetime_fr',
            fn ($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')
        ));

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn () => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', fn () => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', fn () => null));
        $twig->addFunction(new TwigFunction('file_url', fn () => ''));

        return $twig;
    }
}
