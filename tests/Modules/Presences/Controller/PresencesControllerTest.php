<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Presences\Controller\PresencesController;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceRegisterService;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The routes, end to end: the RBAC floor the router enforces
 * (`role_min: chief`), and the section boundary the controller adds on
 * top of it — which is the one the hierarchy cannot express.
 *
 * The write endpoint is here too, because that is where an over-trusting
 * page would do its damage: the event id AND the animé id are re-derived
 * server-side on every call, and neither is taken from the page the
 * request came from.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresencesControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private Environment $twig;
    private AppConfig $config;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private int $eventA;
    private int $eventB;
    private int $animeA;
    private int $animeB;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/core/View/templates');
        $loader->addPath($root . '/modules/presences/views', 'presences');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addFunction(new TwigFunction('param', static fn (string $key, $default = '') => 'Test'));
        $this->twig->addFunction(new TwigFunction('csrf_field', static fn () => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('csrf_token', static fn () => 't'));
        $this->twig->addFunction(new TwigFunction('get_flash', static fn () => null));
        $this->twig->addFunction(new TwigFunction('file_url', static fn () => ''));
        // The site registers these through Core\View\TwigFactory, which
        // needs half the composition root to build. The name filters are
        // the real extension; the two date filters are stand-ins, and no
        // assertion below reads a rendered date — Tests\Core\View covers
        // their formatting where it belongs.
        $this->twig->addExtension(new \Core\View\TextNormalizerExtension());
        $this->twig->addFilter(new \Twig\TwigFilter('date_fr', static fn (string $d): string => $d));
        $this->twig->addFilter(new \Twig\TwigFilter('french_date', static fn (string $d): string => $d));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'akela@test.be');
        $this->twig->addGlobal('current_user_role', 'chief');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');

        $configFile = sys_get_temp_dir() . '/test_presences_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);
        $year = substr($start, 0, 4);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->sectionA = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->sectionB = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');

        $this->eventA = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->sectionA),
            'Réunion',
            $year . '-09-13'
        );
        $this->eventB = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->sectionB),
            'Réunion',
            $year . '-09-13'
        );

        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->sectionA, 'akela@test.be'
        );
        $this->animeA = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Basile', 'Hargot', 'animated', $this->sectionA
        )['memberId'];
        $this->animeB = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Jeanne', 'Vanloqueren', 'animated', $this->sectionB
        )['memberId'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnAnimateurSeesTheirOwnSectionsSheet(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request('GET', '/chefs/presences/feuille/' . $this->eventA, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hargot', $response->getBody());
        $this->assertStringContainsString('Une connexion est nécessaire.', $response->getBody());
    }

    /**
     * The banner has to be ABOVE the list: discovering that nothing was
     * saved after pointing twenty-five names is the worst moment for it.
     */
    public function testTheConnectionWarningComesBeforeTheList(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $body = $this->handle(
            new Request('GET', '/chefs/presences/feuille/' . $this->eventA, [], [], [], [])
        )->getBody();

        $this->assertLessThan(
            strpos($body, 'id="presences-lines"'),
            strpos($body, 'Une connexion est nécessaire.')
        );
    }

    public function testAnotherSectionsSheetIsNotFound(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request('GET', '/chefs/presences/feuille/' . $this->eventB, [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnIntendantIsRefusedByTheRouter(): void
    {
        $this->signIn('intendant@test.be', 'intendant');

        $this->assertSame(403, $this->handle(
            new Request('GET', '/chefs/presences', [], [], [], [])
        )->getStatusCode());
    }

    public function testAnIdentifiedVisitorIsRefusedByTheRouter(): void
    {
        $this->signIn('parent@test.be', 'identified');

        $this->assertSame(403, $this->handle(
            new Request('GET', '/chefs/presences', [], [], [], [])
        )->getStatusCode());
    }

    public function testTheRegisterListsTheSectionsEveningsAndNobodyElses(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $body = $this->handle(new Request('GET', '/chefs/presences', [], [], [], []))->getBody();

        $this->assertStringContainsString('/chefs/presences/feuille/' . $this->eventA, $body);
        $this->assertStringNotContainsString('/chefs/presences/feuille/' . $this->eventB, $body);
    }

    /**
     * Like the trombinoscope: an animateur of one section has nothing to
     * choose, so the picker is not drawn at all.
     */
    public function testTheSectionPickerIsDrawnOnlyWhenThereIsSomethingToChoose(): void
    {
        $this->signIn('akela@test.be', 'chief');
        $this->assertStringNotContainsString(
            'section-picker',
            $this->handle(new Request('GET', '/chefs/presences', [], [], [], []))->getBody()
        );

        $this->signIn('cu@test.be', 'admin');
        $this->assertStringContainsString(
            'section-picker',
            $this->handle(new Request('GET', '/chefs/presences', [], [], [], []))->getBody()
        );
    }

    /**
     * The most frequent gesture of the module is at the top of the page,
     * not at the end of a click into a graph: somebody opening this on a
     * Saturday at 14:00 wants to point.
     */
    public function testTheShortcutToTheDaysEveningIsAboveTheFigures(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $body = $this->handle(new Request('GET', '/chefs/presences', [], [], [], []))->getBody();

        $this->assertStringContainsString('Prochain évènement', $body);
        $this->assertLessThan(strpos($body, 'Participation par date'), strpos($body, 'Prochain évènement'));
    }

    public function testTheSearchAnswersForOnesOwnSection(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request(
            'GET', '/chefs/presences/recherche', ['section' => (string) $this->sectionA, 'q' => ''], [], [], []
        ));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('/chefs/presences/feuille/' . $this->eventA, $payload['events'][0]['url']);
        $this->assertSame('Hargot, Basile', $payload['animes'][0]['name']);
    }

    /**
     * A JSON route is a route: the section is re-checked here exactly as
     * it is on a page, so the search cannot be aimed at somebody else's
     * animés by editing a query string.
     */
    public function testTheSearchRefusesASectionTheAccountDoesNotStaff(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request(
            'GET', '/chefs/presences/recherche', ['section' => (string) $this->sectionB, 'q' => ''], [], [], []
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('Vanloqueren', $response->getBody());
    }

    public function testTheSearchRefusesAMissingSectionRatherThanAnsweringForAll(): void
    {
        $this->signIn('cu@test.be', 'admin');

        $this->assertSame(403, $this->handle(new Request(
            'GET', '/chefs/presences/recherche', ['q' => ''], [], [], []
        ))->getStatusCode());
    }

    public function testAnAnimesPageShowsTheirRateBesideTheSectionsAverage(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request(
            'GET', '/chefs/presences/anime/' . $this->animeA, [], [], [], []
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Moyenne de la section', $response->getBody());
        $this->assertStringContainsString(
            "Cette page n'est jamais visible par la famille",
            $response->getBody()
        );
    }

    public function testAnAnimeOfAnotherSectionIsNotFound(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $this->assertSame(404, $this->handle(new Request(
            'GET', '/chefs/presences/anime/' . $this->animeB, [], [], [], []
        ))->getStatusCode());
    }

    public function testTheExportDownloadsTheSectionsWholeYear(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle(new Request(
            'GET', '/chefs/presences/export', ['section' => (string) $this->sectionA], [], [], []
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaders()['Content-Type'] ?? null
        );
        $this->assertStringContainsString('presences-Louveteaux-1', $response->getHeaders()['Content-Disposition']);
    }

    public function testTheExportOfAnotherSectionIsRefused(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $this->assertSame(404, $this->handle(new Request(
            'GET', '/chefs/presences/export', ['section' => (string) $this->sectionB], [], [], []
        ))->getStatusCode());
    }

    /**
     * The file carries names and the comments written about minors, so
     * what the journal records is that an export happened and how big it
     * was — never who is in it (AGENTS.md § Security checklist).
     */
    public function testTheExportIsJournaledWithCountersAndNoName(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $this->handle(new Request(
            'GET', '/chefs/presences/export', ['section' => (string) $this->sectionA], [], [], []
        ));

        $row = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'presences_export'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('presences', $row['category']);
        $this->assertStringNotContainsString('Hargot', (string) $row['description'] . (string) $row['context']);
        $this->assertStringNotContainsString('Basile', (string) $row['description'] . (string) $row['context']);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame(1, $context['animes']);
        $this->assertSame(1, $context['events']);
    }

    public function testATapIsRecordedOnTheSpot(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeA,
            'status' => 'present',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            PresenceStatus::PRESENT,
            (new PresenceRepository($this->pdo, $this->encryption))->find($this->eventA, $this->animeA)?->status
        );
    }

    /**
     * The one that matters: an event of a section this account staffs,
     * and an animé of a section it does not. Neither id alone would be
     * refused — the pair has to be.
     */
    public function testAnAnimeOfAnotherSectionCannotBeWrittenOntoOnesOwnSheet(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeB,
            'status' => 'present',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(
            (new PresenceRepository($this->pdo, $this->encryption))->find($this->eventA, $this->animeB)
        );
    }

    public function testAnotherSectionsSheetCannotBeWrittenTo(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle($this->jsonPost($this->eventB, [
            'member_id' => $this->animeB,
            'status' => 'present',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            "n'est pas la vôtre",
            (string) (json_decode($response->getBody(), true)['error'] ?? '')
        );
    }

    /**
     * Both refusals say exactly the same thing: a message that told them
     * apart would answer « does this event exist » for free.
     */
    public function testEveryRefusalOfAWriteIsWordedIdentically(): void
    {
        $this->signIn('akela@test.be', 'chief');
        $token = CsrfGuard::generateToken();

        $bodies = array_map(fn (array $payload): string => $this->handle(
            $this->jsonPost($payload['event'], [
                'member_id' => $payload['member'],
                'status' => 'present',
                '_csrf_token' => $token,
            ])
        )->getBody(), [
            ['event' => $this->eventB, 'member' => $this->animeB],
            ['event' => $this->eventA, 'member' => $this->animeB],
            ['event' => 999_999, 'member' => $this->animeA],
        ]);

        $this->assertCount(1, array_unique($bodies));
    }

    public function testAnUnknownStateIsRefusedRatherThanStored(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeA,
            'status' => 'peut-être',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(
            (new PresenceRepository($this->pdo, $this->encryption))->find($this->eventA, $this->animeA)
        );
    }

    public function testAWriteWithoutAValidTokenIsRefused(): void
    {
        $this->signIn('akela@test.be', 'chief');

        $response = $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeA,
            'status' => 'present',
            '_csrf_token' => 'not-the-token',
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'session a expiré',
            (string) (json_decode($response->getBody(), true)['error'] ?? '')
        );
    }

    public function testACommentIsSavedWithoutTouchingTheState(): void
    {
        $this->signIn('akela@test.be', 'chief');
        $repository = new PresenceRepository($this->pdo, $this->encryption);
        $repository->save($this->eventA, $this->animeA, PresenceStatus::EXCUSED, null, null);

        $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeA,
            'comment' => 'Prévenu jeudi.',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $record = $repository->find($this->eventA, $this->animeA);
        $this->assertSame('Prévenu jeudi.', $record?->comment);
        $this->assertSame(PresenceStatus::EXCUSED, $record?->status);
    }

    public function testAStateIsSavedWithoutErasingTheCommentBesideIt(): void
    {
        $this->signIn('akela@test.be', 'chief');
        $repository = new PresenceRepository($this->pdo, $this->encryption);
        $repository->save($this->eventA, $this->animeA, PresenceStatus::UNSET, 'Sa maman a prévenu.', null);

        $this->handle($this->jsonPost($this->eventA, [
            'member_id' => $this->animeA,
            'status' => 'absent',
            '_csrf_token' => CsrfGuard::generateToken(),
        ]));

        $record = $repository->find($this->eventA, $this->animeA);
        $this->assertSame('Sa maman a prévenu.', $record?->comment);
        $this->assertSame(PresenceStatus::ABSENT, $record?->status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonPost(int $eventId, array $payload): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([
                'POST', '/chefs/presences/feuille/' . $eventId . '/enregistrer', [], [], [], [],
            ])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($payload));

        return $request;
    }

    private function handle(Request $request): \Core\Http\Response
    {
        return $this->buildFrontController()->handle($request);
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        // Mirrors modules/presences/module.json.
        $router->addRoute('GET', '/chefs/presences', PresencesController::class, 'index', 'chief');
        $router->addRoute('GET', '/chefs/presences/recherche', PresencesController::class, 'search', 'chief');
        $router->addRoute('GET', '/chefs/presences/export', PresencesController::class, 'export', 'chief');
        $router->addRoute(
            'GET', '/chefs/presences/anime/{memberId}', PresencesController::class, 'anime', 'chief'
        );
        $router->addRoute(
            'GET', '/chefs/presences/feuille/{eventId}', PresencesController::class, 'sheet', 'chief'
        );
        $router->addRoute(
            'POST', '/chefs/presences/feuille/{eventId}/enregistrer', PresencesController::class, 'record', 'chief'
        );

        $calendar = PresencesTestHelper::calendarService($this->pdo, $this->encryption);
        $sectionService = PresencesTestHelper::sectionService($this->pdo, $this->encryption);
        $repository = new PresenceRepository($this->pdo, $this->encryption);
        $authorization = PresencesTestHelper::authorization($this->pdo, $this->encryption);
        $sheetService = PresencesTestHelper::sheetService($this->pdo, $this->encryption, $calendar);
        $registerService = new PresenceRegisterService(
            $calendar,
            new \Core\Config\ScoutYearService($this->pdo),
            $sectionService,
            $repository
        );
        $controller = new PresencesController(
            $this->twig,
            $authorization,
            $sheetService,
            $registerService,
            new \Modules\Presences\Service\PresenceAnimeService(
                $authorization, $sheetService, $registerService, $repository
            ),
            new \Modules\Presences\Service\PresenceExportService(
                $registerService, $sectionService, $repository
            ),
            new \Core\Member\MemberService(
                new \Core\Import\MemberYearRepository($this->pdo, $this->encryption),
                $this->encryption,
                \Core\Database\Connection::withPdo($this->pdo)
            ),
            $this->stubResolver(),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(PresencesController::class, $controller);

        return $frontController;
    }

    private function stubResolver(): ScoutYearResolver
    {
        $scoutYearId = $this->scoutYearId;

        return new class ($scoutYearId) extends ScoutYearResolver {
            public function __construct(private int $scoutYearId)
            {
                // No settings table in this test.
            }

            public function getEffectiveYear(?int $sessionOverrideId, Role $role): EffectiveScoutYear
            {
                return new EffectiveScoutYear($this->scoutYearId, '2026-2027', null);
            }
        };
    }

    private function signIn(string $email, string $role): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        AuthSession::login(1, $email, $role);
    }
}
