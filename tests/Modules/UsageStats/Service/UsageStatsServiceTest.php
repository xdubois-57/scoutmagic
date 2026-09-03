<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Service;

use Core\Http\Router;
use Core\Module\ModuleInfo;
use Core\Module\ModuleManager;
use Core\Module\ModuleManifest;
use Modules\UsageStats\Repository\AccountActivityRepository;
use Modules\UsageStats\Repository\PageViewRepository;
use Modules\UsageStats\Service\UsageStatsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;

class UsageStatsServiceTest extends TestCase
{
    private const NOW = '2026-08-14 10:00:00';
    private const THIS_MONTH = '2026-08';

    private \PDO $pdo;
    private PageViewRepository $pageViews;
    private UsageStatsService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($this->pdo);
        $this->pageViews = new PageViewRepository($this->pdo);

        $router = new Router();
        $router->addRoute('GET', '/calendar', 'X', 'index', 'identified', ['label' => 'Calendrier', 'parents' => []]);
        $router->addRoute('GET', '/members/{id}', 'X', 'show', 'identified', ['label' => "Page d'un animé", 'parents' => []]);
        // No breadcrumb: an endpoint rather than a page. Its pattern is
        // what the screen then shows, which is still true.
        $router->addRoute('GET', '/files/{id}', 'X', 'serve', 'identified');

        $this->service = new UsageStatsService(
            $this->pageViews,
            new AccountActivityRepository($this->pdo),
            self::moduleManager(),
            $router
        );
    }

    /**
     * `calendar` is a page module, `fees` a staff-only one, `retro` an
     * enabled module with nothing counted, `inbound_mail` an enabled
     * module with no page of its own, `banner` a disabled one.
     */
    private static function moduleManager(): ModuleManager
    {
        return new class () extends ModuleManager {
            public function __construct()
            {
            }

            /** @return ModuleInfo[] */
            public function discoverModules(): array
            {
                return [
                    self::info('calendar', 'Calendrier', true, [['/calendar', 'identified']]),
                    self::info('fees', 'Cotisations', true, [['/admin/fees', 'chief']]),
                    self::info('retro', 'Rétrospectives', true, [['/retro', 'identified']]),
                    self::info('inbound_mail', 'Courrier entrant', true, [['/api/inbound/hook', 'public']]),
                    self::info('banner', 'Bannière', false, [['/config/banner', 'admin']]),
                ];
            }

            /** @param list<array{0: string, 1: string}> $routes */
            private static function info(string $id, string $name, bool $enabled, array $routes): ModuleInfo
            {
                $declared = [];
                foreach ($routes as [$path, $roleMin]) {
                    $declared[] = [
                        'path' => $path,
                        'method' => 'GET',
                        'controller' => 'X',
                        'action' => 'index',
                        'menu' => 'configuration',
                        'role_min' => $roleMin,
                        'label' => '',
                        'menu_order' => 100,
                        'menu_order_explicit' => false,
                        'menu_icon' => null,
                        'menu_group' => null,
                        'breadcrumb' => null,
                    ];
                }

                return new ModuleInfo(
                    new ModuleManifest($id, $name, '1.0.0', $declared, [], [], [], []),
                    $enabled,
                    '1.0.0',
                    true,
                    null
                );
            }
        };
    }

    // ── Vue d'ensemble ───────────────────────────────────────────────

    public function testTheCurveCoversTwelveMonthsAndFillsTheSilentOnesWithZero(): void
    {
        $this->view('2026-08', '/calendar', 'calendar', 'identified', 10);
        $this->view('2026-02', '/calendar', 'calendar', 'identified', 4);

        $curve = $this->service->overview(self::THIS_MONTH, $this->now())['curve'];

        $this->assertCount(12, $curve);
        $this->assertSame('2025-09', $curve[0]['month']);
        $this->assertSame('2026-08', $curve[11]['month']);
        $this->assertTrue($curve[11]['is_last']);
        $this->assertSame(0, $curve[0]['views']);
        $this->assertSame(4, $curve[5]['views']);
        // Heights are relative to the peak, so the tallest bar is full.
        $this->assertSame(100, $curve[11]['height_percent']);
        $this->assertSame(40, $curve[5]['height_percent']);
    }

    public function testAdoptionIsAnsweredForTheCurrentMonthOnly(): void
    {
        $this->account('a@test.be', '2026-08-03 08:00:00');
        $this->account('b@test.be', '2026-08-11 08:00:00');
        $this->account('c@test.be', '2026-05-11 08:00:00');
        $this->account('d@test.be', null);

        $overview = $this->service->overview(self::THIS_MONTH, $this->now());

        $this->assertTrue($overview['is_current_month']);
        $this->assertSame(2, $overview['active_accounts']);
        $this->assertSame(4, $overview['total_accounts']);
        $this->assertSame(50, $overview['adoption_percent']);
    }

    /**
     * The one thing this must never do: answer « comptes actifs » for a
     * past month. `last_login_at` holds the LAST login, so for May it
     * would count the people who stopped coming in May — the opposite of
     * what a reader would take it for. Null is what the screen turns into
     * a sentence.
     */
    public function testAPastMonthReportsNoAdoptionFigureAtAll(): void
    {
        $this->account('c@test.be', '2026-05-11 08:00:00');

        $overview = $this->service->overview('2026-05', $this->now());

        $this->assertFalse($overview['is_current_month']);
        $this->assertSame(0, $overview['active_accounts']);
        $this->assertNull($overview['adoption_percent']);
    }

    public function testTopPagesAreNamedByTheirBreadcrumbLabelAndFallBackToThePattern(): void
    {
        $this->view(self::THIS_MONTH, '/members/{id}', 'core', 'identified', 30);
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 20);
        $this->view(self::THIS_MONTH, '/files/{id}', 'core', 'identified', 5);

        $top = $this->service->overview(self::THIS_MONTH, $this->now())['top_pages'];

        $this->assertSame(["Page d'un animé", 'Calendrier', '/files/{id}'], array_column($top, 'label'));
        $this->assertSame([30, 20, 5], array_column($top, 'views'));
    }

    public function testEveryAudienceIsListedEvenWhenItHasNoView(): void
    {
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 8);
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'anonymous', 4);

        $audiences = $this->service->overview(self::THIS_MONTH, $this->now())['audiences'];

        $this->assertSame(
            ['Visiteurs anonymes' => 4, 'Membres identifiés' => 8, 'Staff' => 0],
            array_combine(array_column($audiences, 'label'), array_column($audiences, 'views'))
        );
    }

    // ── Modules ──────────────────────────────────────────────────────

    public function testModulesAreRankedAndNamedAndTheCoreIsOneOfThem(): void
    {
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 40);
        $this->view(self::THIS_MONTH, '/', 'core', 'anonymous', 90);

        $used = $this->service->modules(self::THIS_MONTH)['used'];

        $this->assertSame(['Cœur du site', 'Calendrier'], array_column($used, 'name'));
        $this->assertSame([100, 44], array_column($used, 'width_percent'));
    }

    public function testAStaffOnlyModuleIsBadgedAndAMemberFacingOneIsNot(): void
    {
        $this->view(self::THIS_MONTH, '/admin/fees', 'fees', 'staff', 6);
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 6);

        $badges = [];
        foreach ($this->service->modules(self::THIS_MONTH)['used'] as $module) {
            $badges[$module['id']] = $module['staff_only'];
        }

        $this->assertTrue($badges['fees']);
        $this->assertFalse($badges['calendar']);
    }

    public function testTheTrendComparesWithTheMonthBeforeAndIsNullWithNothingToCompare(): void
    {
        $this->view('2026-07', '/calendar', 'calendar', 'identified', 100);
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 75);
        $this->view(self::THIS_MONTH, '/', 'core', 'anonymous', 10);

        $trends = [];
        foreach ($this->service->modules(self::THIS_MONTH)['used'] as $module) {
            $trends[$module['id']] = $module['trend_percent'];
        }

        $this->assertSame(-25, $trends['calendar']);
        $this->assertNull($trends['core']);
    }

    /**
     * The whole point of the screen: an enabled module nobody has opened
     * in a year. A disabled one is not listed (it is already off), and
     * neither is one with no page of its own — telling a chief to switch
     * off a module that has nothing to open would be advice based on a
     * measurement that does not apply to it.
     */
    public function testTheUnusedBlockListsOnlyEnabledModulesThatHaveAPageAndNoView(): void
    {
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 5);
        $this->view(self::THIS_MONTH, '/admin/fees', 'fees', 'staff', 2);

        $unused = $this->service->modules(self::THIS_MONTH)['unused'];

        // `retro` is enabled, has a page and nobody opened it — the only
        // one of the five that qualifies. `banner` is already disabled and
        // `inbound_mail` has no page of its own.
        $this->assertSame(['Rétrospectives'], array_column($unused, 'name'));
    }

    public function testAModuleOpenedElevenMonthsAgoIsNotReportedAsUnused(): void
    {
        $this->view('2025-10', '/retro', 'retro', 'identified', 1);

        $unused = array_column($this->service->modules(self::THIS_MONTH)['unused'], 'id');

        $this->assertNotContains('retro', $unused);
        // The window is twelve months, so a single opening last October
        // still counts — and the two modules nobody ever opened do show.
        $this->assertSame(['calendar', 'fees'], $unused);
    }

    public function testAModuleOpenedTwelveMonthsAgoHasFallenOutOfTheWindow(): void
    {
        $this->view('2025-08', '/retro', 'retro', 'identified', 1);

        $unused = array_column($this->service->modules(self::THIS_MONTH)['unused'], 'id');

        $this->assertContains('retro', $unused);
    }

    // ── Pages ────────────────────────────────────────────────────────

    public function testPagesSumTheAudiencesUnlessOneIsAskedFor(): void
    {
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 8);
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'anonymous', 3);

        $this->assertSame(11, $this->service->pages(self::THIS_MONTH, null)['pages'][0]['views']);
        $this->assertSame(3, $this->service->pages(self::THIS_MONTH, 'anonymous')['pages'][0]['views']);
    }

    public function testAnUnknownAudienceIsIgnoredRatherThanEmptyingThePage(): void
    {
        $this->view(self::THIS_MONTH, '/calendar', 'calendar', 'identified', 8);

        $report = $this->service->pages(self::THIS_MONTH, 'chef-dunite');

        $this->assertNull($report['audience']);
        $this->assertCount(1, $report['pages']);
    }

    public function testAPageKeepsItsPatternBesideItsLabel(): void
    {
        $this->view(self::THIS_MONTH, '/members/{id}', 'core', 'identified', 8);

        $page = $this->service->pages(self::THIS_MONTH, null)['pages'][0];

        $this->assertSame("Page d'un animé", $page['label']);
        $this->assertSame('/members/{id}', $page['route']);
        $this->assertSame('Cœur du site', $page['module_name']);
    }

    // ── Le sélecteur de mois ─────────────────────────────────────────

    public function testTheMonthPickerOffersEveryCountedMonthPlusTheCurrentOne(): void
    {
        $this->view('2026-06', '/calendar', 'calendar', 'identified', 1);

        $months = array_column($this->service->availableMonths($this->now()), 'value');

        $this->assertSame(['2026-08', '2026-06'], $months);
    }

    public function testAMalformedMonthFallsBackToTheCurrentOne(): void
    {
        $this->assertSame('2026-08', $this->service->resolveMonth('2026-13', $this->now()));
        $this->assertSame('2026-08', $this->service->resolveMonth("' OR 1=1", $this->now()));
        $this->assertSame('2026-05', $this->service->resolveMonth('2026-05', $this->now()));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function view(string $month, string $route, string $moduleId, string $audience, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->pageViews->increment($month, $route, $moduleId, $audience);
        }
    }

    private function account(string $email, ?string $lastLoginAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_active, last_login_at)
             VALUES (?, ?, 1, ?)'
        );
        $stmt->execute(['x', hash('sha256', $email), $lastLoginAt]);
    }
}
