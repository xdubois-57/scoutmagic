<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\PageController;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Member\MemberProfile;
use Core\Module\HomeBannerProvider;
use Core\Module\HomeGroupActivityProvider;
use Core\Module\HookRegistry;
use Core\Module\HomePaymentDueProvider;
use Core\Module\HomeNewsProvider;
use Core\Module\SectionResponsableProvider;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\Service\TextNormalizerService;
use Core\View\SectionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PageControllerTest extends TestCase
{
    private \PDO $pdo;
    private PageController $controller;
    private Environment $twig;
    private EditableContentService $editableService;
    private SectionRepository $sectionRepo;
    private SettingService $settingService;
    private RgpdContentService $rgpdContentService;
    private SectionService $sectionService;
    private UnitStaffSectionService $unitStaffSectionService;
    private ScoutYearService $scoutYearService;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));
        // Core\View\TwigFactory registers this in production; this test
        // builds a bare Environment, so the homepage's payment band needs
        // it declared here too.
        $twig->addFilter(new \Twig\TwigFilter('money_cents', function ($cents): string {
            if ($cents === null || $cents === '') {
                return '';
            }

            return number_format(((int) $cents) / 100, 2, ',', ' ') . ' €';
        }));
        $twig->addFunction(new \Twig\TwigFunction('param', function (string $key): string {
            $params = ['contact_email' => 'test@example.com', 'site_name' => 'Test'];
            return $params[$key] ?? '';
        }));

        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $repo = new EditableContentRepository($this->pdo);
        $editableService = new EditableContentService($repo);
        $twig->addGlobal('_editable_content_service', $editableService);

        $twig->addFunction(new \Twig\TwigFunction('editable', function (string $key, string $default = ''): string {
            return $default;
        }, ['is_safe' => ['html']]));
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        // Minimal stand-in for TwigFactory::create()'s real section_photo() —
        // real rendering/placeholder/overlay logic is covered in full by
        // Tests\Core\View\SectionPhotoFunctionTest; here it only needs to
        // exist so pages/contact.html.twig doesn't fail to render.
        $twig->addFunction(new \Twig\TwigFunction('section_photo', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addExtension(new \Core\View\TextNormalizerExtension());
        $twig->addFilter(new \Twig\TwigFilter('display_name', function ($member) {
            return $member instanceof MemberProfile ? $member->getDisplayName() : (string) $member;
        }));
        // Minimal stand-in for TwigFactory::create()'s own relative_date —
        // the real French/UTC formatting is covered in full by
        // Tests\Core\View\TwigFactoryTest; here it only needs to exist so
        // pages/home.html.twig's groups activity card can render.
        $twig->addFilter(new \Twig\TwigFilter('relative_date', function ($date) {
            return (string) $date;
        }));

        $sectionRepo = new SectionRepository($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $memberBadgeRepository = new \Core\Badge\MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $scoutYearService = new ScoutYearService($this->pdo);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('default');

        $rgpdContentService = $this->createMock(RgpdContentService::class);
        $rgpdContentService->method('getDefaultContent')->willReturn('<h2>Protection des données</h2>');
        $rgpdContentService->method('getDefaultContentLastModified')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $this->twig = $twig;
        $this->editableService = $editableService;
        $this->sectionRepo = $sectionRepo;
        $this->settingService = $settingService;
        $this->rgpdContentService = $rgpdContentService;
        $this->sectionService = $sectionService;
        $this->unitStaffSectionService = $unitStaffSectionService;
        $this->scoutYearService = $scoutYearService;
        $this->controller = new PageController(
            $twig, $editableService, $sectionRepo, $settingService, $rgpdContentService,
            $sectionService, $unitStaffSectionService, $scoutYearService
        );
    }

    public function testHomePageRenders(): void
    {
        $request = new Request('GET', '/', [], [], [], []);
        $response = $this->controller->home($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bienvenue', $response->getBody());
    }

    public function testHomePageRendersNoBannerWhenNoProviderWired(): void
    {
        // Banner module disabled — PageController's bannerProvider defaults
        // to null, and the homepage must render normally, without error.
        $request = new Request('GET', '/', [], [], [], []);
        $response = $this->controller->home($request, []);

        $this->assertStringNotContainsString('alert-info', $response->getBody());
    }

    public function testHomePageRendersBannerWhenProviderReturnsContent(): void
    {
        $provider = new class implements HomeBannerProvider {
            public function getRandomBannerHtml(string $viewerRole): ?string
            {
                return '<p>Message important</p>';
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\HomeBannerProvider::class, $provider));

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringContainsString('Message important', $response->getBody());
    }

    /**
     * A parent of two sees ONE band with two lines, never two bands —
     * and the total is what the strong line says.
     */
    public function testHomePageRendersOneBandForTheWholeFamily(): void
    {
        $controller = $this->controllerWithPaymentDue([
            'total_cents' => 8325,
            'demands' => [
                ['member_year_id' => 11, 'member_name' => 'Margaux', 'label' => 'Cotisation', 'amount_cents' => 3825],
                ['member_year_id' => 12, 'member_name' => 'Antoine', 'label' => 'Week-end', 'amount_cents' => 4500],
            ],
            'single_member_year_id' => null,
            'statement_date' => '2026-02-20',
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertSame(1, substr_count($body, 'id="home-payment-due"'), 'one band, not one per child');
        $this->assertStringContainsString('Margaux', $body);
        $this->assertStringContainsString('Antoine', $body);
        $this->assertStringContainsString('/members/11', $body);
        $this->assertStringContainsString('/members/12', $body);
    }

    /**
     * The warning quotes the last statement actually imported and then a
     * plain-prose delay — never a computed bank-closing time.
     */
    public function testTheBandQuotesTheImportDateAndTheDelayInProse(): void
    {
        $controller = $this->controllerWithPaymentDue([
            'total_cents' => 3825,
            'demands' => [['member_year_id' => 11, 'member_name' => 'Margaux', 'label' => 'Cotisation', 'amount_cents' => 3825]],
            'single_member_year_id' => 11,
            'statement_date' => '2026-02-20',
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('20/02/2026', $body);
        $this->assertStringContainsString('un à deux jours ouvrables', $body);
    }

    /** Nothing imported: the promise is dropped rather than made up. */
    public function testTheBandDropsTheDateSentenceWhenNothingHasBeenImported(): void
    {
        $controller = $this->controllerWithPaymentDue([
            'total_cents' => 3825,
            'demands' => [['member_year_id' => 11, 'member_name' => 'Margaux', 'label' => 'Cotisation', 'amount_cents' => 3825]],
            'single_member_year_id' => 11,
            'statement_date' => null,
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('un à deux jours ouvrables', $body);
        $this->assertStringContainsString('Voir le détail et payer', $body);
    }

    /**
     * The provider answers null for everyone with nothing to pay, and the
     * band is then not rendered at all — there is no access check in the
     * template to get wrong.
     */
    public function testNoBandAtAllWhenTheProviderHasNothingToSay(): void
    {
        $request = new Request('GET', '/', [], [], [], []);

        $this->assertStringNotContainsString(
            'id="home-payment-due"',
            $this->controller->home($request, [])->getBody()
        );
    }

    /**
     * THE PRIORITY RULE, with all three candidates able to speak at once.
     *
     * The home page carries ONE band, and the order that picks it is a
     * business rule shared by PageController::home() and
     * pages/home.html.twig: money due first, group activity second, the
     * unit's editorial banner last. This is the case that used to render
     * a stack of three.
     */
    public function testOnlyThePaymentBandIsRenderedWhenAllThreeProvidersHaveSomethingToSay(): void
    {
        $banner = $this->spyBannerProvider('<p>Message important</p>');
        $activity = $this->spyGroupActivityProvider(self::GROUP_ACTIVITY);
        $controller = $this->controllerWithHooks([
            HomePaymentDueProvider::class => $this->paymentDueProvider(self::PAYMENT_DUE),
            HomeGroupActivityProvider::class => $activity,
            HomeBannerProvider::class => $banner,
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('id="home-payment-due"', $body);
        $this->assertStringNotContainsString('Du nouveau dans vos groupes', $body);
        $this->assertStringNotContainsString('Message important', $body);
        $this->assertSame(1, substr_count($body, 'alert alert-info'), 'exactly one band, never a stack');

        // The order is meant to be short-circuited, not merely rendered
        // selectively: a provider whose answer can no longer be shown
        // goes to the database for nothing.
        $this->assertSame(0, $activity->calls, 'the group provider must not be asked once a payment band was chosen');
        $this->assertSame(0, $banner->calls, 'the banner provider must not be asked once a payment band was chosen');
    }

    /**
     * Second in the order: with nothing to pay, group activity outranks
     * the editorial banner — a message addressed to this visitor beats
     * one addressed to everybody.
     */
    public function testGroupActivityOutranksTheEditorialBannerWhenNothingIsDue(): void
    {
        $banner = $this->spyBannerProvider('<p>Message important</p>');
        $controller = $this->controllerWithHooks([
            HomeGroupActivityProvider::class => $this->spyGroupActivityProvider(self::GROUP_ACTIVITY),
            HomeBannerProvider::class => $banner,
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Du nouveau dans vos groupes', $body);
        $this->assertStringNotContainsString('Message important', $body);
        $this->assertSame(1, substr_count($body, 'alert alert-info'));
        $this->assertSame(0, $banner->calls, 'the banner provider must not be asked once the group band was chosen');
    }

    /**
     * Last in the order: the editorial banner is the fallback, shown
     * exactly when the two bands above it stayed silent.
     */
    public function testTheEditorialBannerIsRenderedWhenTheTwoBandsAboveHaveNothingToSay(): void
    {
        $controller = $this->controllerWithHooks([
            HomePaymentDueProvider::class => $this->paymentDueProvider(null),
            HomeGroupActivityProvider::class => $this->spyGroupActivityProvider(null),
            HomeBannerProvider::class => $this->spyBannerProvider('<p>Message important</p>'),
        ]);

        $body = $controller->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Message important', $body);
        $this->assertSame(1, substr_count($body, 'alert alert-info'));
    }

    /**
     * One style for all three: the Banners module's plain Bootstrap
     * alert-info. Three different colours in one slot read as a severity
     * scale the page never meant.
     *
     * Asserted on each band's own class attribute rather than on the
     * whole document — base.html.twig carries alert-warning bands of its
     * own (the offline and impersonation strips), so a document-wide
     * search would pass whatever the home page did.
     */
    public function testEveryBandWearsTheSameAlertInfoStyle(): void
    {
        $paymentBody = $this->controllerWithHooks([
            HomePaymentDueProvider::class => $this->paymentDueProvider(self::PAYMENT_DUE),
        ])->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        $this->assertSame(
            1,
            preg_match('/<div id="home-payment-due" class="([^"]+)"/', $paymentBody, $paymentClasses),
            'the payment band was not rendered at all'
        );
        $this->assertStringContainsString('alert-info', $paymentClasses[1]);
        $this->assertStringNotContainsString('alert-warning', $paymentClasses[1]);

        $activityBody = $this->controllerWithHooks([
            HomeGroupActivityProvider::class => $this->spyGroupActivityProvider(self::GROUP_ACTIVITY),
        ])->home(new Request('GET', '/', [], [], [], []), [])->getBody();

        // The group band carries no id; its own icon identifies it.
        $this->assertSame(
            1,
            preg_match('/<div class="([^"]*alert[^"]*)">\s*<i class="bi bi-chat-dots"/', $activityBody, $activityClasses),
            'the group activity band was not rendered at all'
        );
        $this->assertStringContainsString('alert-info', $activityClasses[1]);
        $this->assertStringNotContainsString('alert-primary', $activityClasses[1]);
        // The band's one action stays btn-primary — coherent inside an
        // alert-info, and unchanged by the restyling.
        $this->assertStringContainsString('btn btn-sm btn-primary', $activityBody);
    }

    /** A summary with something to pay, for the priority-order tests. */
    private const PAYMENT_DUE = [
        'total_cents' => 3825,
        'demands' => [['member_year_id' => 11, 'member_name' => 'Margaux', 'label' => 'Cotisation', 'amount_cents' => 3825]],
        'single_member_year_id' => 11,
        'statement_date' => '2026-02-20',
    ];

    /** A summary with unread group activity, for the same tests. */
    private const GROUP_ACTIVITY = [
        'group_count' => 1,
        'single_group' => ['id' => 7, 'name' => 'Staff Louveteaux'],
        'new_posts' => 2,
        'new_replies_or_reactions' => 0,
        'new_mentions' => 0,
    ];

    /**
     * @param array<string, mixed>|null $summary
     */
    private function paymentDueProvider(?array $summary): HomePaymentDueProvider
    {
        return new class ($summary) implements HomePaymentDueProvider {
            /** @param array<string, mixed>|null $summary */
            public function __construct(private ?array $summary)
            {
            }

            /**
             * @return null|array{total_cents: int, demands: list<array{member_year_id: int, member_name: string, label: string, amount_cents: int}>, single_member_year_id: ?int, statement_date: ?string}
             */
            public function getHomePaymentSummaryForCurrentUser(): ?array
            {
                /** @var null|array{total_cents: int, demands: list<array{member_year_id: int, member_name: string, label: string, amount_cents: int}>, single_member_year_id: ?int, statement_date: ?string} $summary */
                $summary = $this->summary;

                return $summary;
            }
        };
    }

    /**
     * Counts its calls, so a test can assert the controller stopped
     * asking once a higher-priority band had been chosen.
     *
     * @param array<string, mixed>|null $summary
     */
    private function spyGroupActivityProvider(?array $summary): HomeGroupActivityProvider
    {
        return new class ($summary) implements HomeGroupActivityProvider {
            public int $calls = 0;

            /** @param array<string, mixed>|null $summary */
            public function __construct(private ?array $summary)
            {
            }

            /**
             * @return null|array{group_count: int, single_group: null|array{id: int, name: string}, new_posts: int, new_replies_or_reactions: int, new_mentions: int}
             */
            public function getHomeActivitySummaryForCurrentUser(): ?array
            {
                ++$this->calls;
                /** @var null|array{group_count: int, single_group: null|array{id: int, name: string}, new_posts: int, new_replies_or_reactions: int, new_mentions: int} $summary */
                $summary = $this->summary;

                return $summary;
            }
        };
    }

    /** Counts its calls, for the same reason. */
    private function spyBannerProvider(?string $html): HomeBannerProvider
    {
        return new class ($html) implements HomeBannerProvider {
            public int $calls = 0;

            public function __construct(private ?string $html)
            {
            }

            public function getRandomBannerHtml(string $viewerRole): ?string
            {
                ++$this->calls;

                return $this->html;
            }
        };
    }

    /**
     * A PageController holding a registry of several hooks at once — what
     * the composition root builds on an install where more than one of
     * the home page's contributing modules is enabled.
     *
     * @param array<class-string, object> $hooks
     */
    private function controllerWithHooks(array $hooks): PageController
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hookInterface => $implementation) {
            $registry->register($hookInterface, $implementation);
        }

        return new PageController(
            $this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService,
            $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $registry
        );
    }

    /**
     * @param array{total_cents: int, demands: list<array{member_year_id: int, member_name: string, label: string, amount_cents: int}>, single_member_year_id: ?int, statement_date: ?string} $summary
     */
    private function controllerWithPaymentDue(array $summary): PageController
    {
        return $this->controllerWithHooks([
            HomePaymentDueProvider::class => $this->paymentDueProvider($summary),
        ]);
    }

    public function testHomePagePassesTheCurrentViewerRoleToTheBannerProvider(): void
    {
        $capture = new \stdClass();
        $capture->role = null;
        $provider = new class ($capture) implements HomeBannerProvider {
            public function __construct(private \stdClass $capture)
            {
            }
            public function getRandomBannerHtml(string $viewerRole): ?string
            {
                $this->capture->role = $viewerRole;
                return null;
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\HomeBannerProvider::class, $provider));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', 'chief');

        $controller->home(new Request('GET', '/', [], [], [], []), []);

        AuthSession::logout();
        $this->assertSame('chief', $capture->role);
    }

    public function testHomePageRendersNothingWhenProviderReturnsNull(): void
    {
        $provider = new class implements HomeBannerProvider {
            public function getRandomBannerHtml(string $viewerRole): ?string
            {
                return null;
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\HomeBannerProvider::class, $provider));

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringNotContainsString('alert-info', $response->getBody());
    }

    public function testHomePageRendersNoNewsColumnWhenNoProviderWired(): void
    {
        $request = new Request('GET', '/', [], [], [], []);
        $response = $this->controller->home($request, []);

        $this->assertStringNotContainsString('Actualités', $response->getBody());
    }

    public function testHomePageRendersNewsColumnWhenProviderReturnsArticles(): void
    {
        $provider = new class implements HomeNewsProvider {
            /** @var string[] */
            public array $rolesAsked = [];

            public function getLatestVisibleArticles(int $limit, string $role): array
            {
                $this->rolesAsked[] = $role;

                return [
                    ['id' => 1, 'title' => 'Camp ete', 'summary' => 'Inscriptions ouvertes.', 'image_url' => '/files/42', 'created_at' => '2026-01-01 00:00:00'],
                ];
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\HomeNewsProvider::class, $provider));

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringContainsString('Actualités', $response->getBody());
        $this->assertStringContainsString('Camp ete', $response->getBody());
        $this->assertStringContainsString('/news/1', $response->getBody());
        // The provider filters by role itself (a Service never reads
        // $_SESSION), so the controller has to hand it one.
        $this->assertSame(['public'], $provider->rolesAsked);
    }

    public function testHomePageRendersNoNewsColumnWhenProviderReturnsNoArticles(): void
    {
        $provider = new class implements HomeNewsProvider {
            public function getLatestVisibleArticles(int $limit, string $role): array
            {
                return [];
            }
        };
        $controller = new PageController($this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService, $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\HomeNewsProvider::class, $provider));

        $request = new Request('GET', '/', [], [], [], []);
        $response = $controller->home($request, []);

        $this->assertStringNotContainsString('Actualités', $response->getBody());
    }

    public function testContactPageRenders(): void
    {
        $request = new Request('GET', '/contact', [], [], [], []);
        $response = $this->controller->contact($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Contact', $response->getBody());
    }

    public function testContactPageListsStaffDuMembersInACardLikeTheEmailBox(): void
    {
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $staffduSectionId = $this->unitStaffSectionService->ensureSection();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->prepare("INSERT INTO functions (desk_code, label, role) VALUES ('FN1', ?, 'admin')")->execute(["Chef d'Unité"]);
        $functionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, $encryption->encrypt('Jean', 'member_years.first_name'), $encryption->encrypt('Dupont', 'member_years.last_name'), $encryption->encrypt('Baloo', 'member_years.totem')]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $staffduSectionId]);

        $request = new Request('GET', '/contact', [], [], [], []);
        $response = $this->controller->contact($request, []);
        $body = $response->getBody();

        // Same card structure (card / card-body / card-title) as the email
        // box further down the page.
        $this->assertMatchesRegularExpression(
            '/<div class="card mb-4">\s*<div class="card-body">\s*<h5 class="card-title">.*Staff d\'Unité/s',
            $body
        );
    }

    public function testSectionsPageRendersEmptyState(): void
    {
        $request = new Request('GET', '/sections', [], [], [], []);
        $response = $this->controller->sections($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('premier import', $response->getBody());
    }

    public function testSectionsPageShowsResponsableNameFromProvider(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BRANCH', 'Branche Test', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name, email) VALUES ($branchId, 'SEC1', 'Section Test', 'sec1@example.com')");
        $sectionId = (int) $this->pdo->lastInsertId();

        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D2')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, $encryption->encrypt('Marie', 'member_years.first_name'), $encryption->encrypt('Curie', 'member_years.last_name'), $encryption->encrypt('Aigle', 'member_years.totem')]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $profile = $this->sectionService->hydrateMemberProfile($memberYearId);

        $provider = new class($profile, $sectionId, $scoutYearId) implements SectionResponsableProvider {
            /** @var array<int, array{0: int, 1: int}> */
            public array $calls = [];

            public function __construct(
                private ?MemberProfile $profile,
                private int $expectedSectionId,
                private int $expectedScoutYearId
            ) {
            }

            public function getResponsable(int $sectionId, int $scoutYearId): ?MemberProfile
            {
                $this->calls[] = [$sectionId, $scoutYearId];
                if ($sectionId === $this->expectedSectionId && $scoutYearId === $this->expectedScoutYearId) {
                    return $this->profile;
                }
                return null;
            }
        };

        $controller = new PageController(
            $this->twig, $this->editableService, $this->sectionRepo, $this->settingService, $this->rgpdContentService,
            $this->sectionService, $this->unitStaffSectionService, $this->scoutYearService, $this->hooksWith(\Core\Module\SectionResponsableProvider::class, $provider)
        );

        $request = new Request('GET', '/sections', [], [], [], []);
        $response = $controller->sections($request, []);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(TextNormalizerService::normalizeName('Aigle'), $body);
        $this->assertContains([$sectionId, $scoutYearId], $provider->calls);
    }

    public function testRgpdPageRenders(): void
    {
        $request = new Request('GET', '/rgpd', [], [], [], []);
        $response = $this->controller->rgpd($request, []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Protection des données', $response->getBody());
    }
    /**
     * A registry carrying exactly one hook — what the composition root
     * builds, reduced to the hook under test.
     *
     * @param class-string $hookInterface
     */
    private function hooksWith(string $hookInterface, object $implementation): HookRegistry
    {
        $hooks = new HookRegistry();
        $hooks->register($hookInterface, $implementation);
        return $hooks;
    }
}
